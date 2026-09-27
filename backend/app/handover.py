"""From the website to Dolibarr, and then forgotten (#39, #41).

A new inquiry goes to Dolibarr right away. When that fails, a queue tries
again, first after 5 minutes, then with a growing pause up to an hour. An
inquiry still waiting after 30 minutes triggers one warning mail to the
operator - once per inquiry, never per attempt. Once the ticket is complete in
Dolibarr, the website drops the personal data and keeps only what prevents a
double hand-over: number, ticket reference and times.

Records from before this version carry no ``auto_handover`` mark. The queue
leaves them alone; the one-time migration hands them over without a
confirmation mail to the customer, after a dry run the owner looks at first.
"""
import hashlib
import logging
from datetime import datetime, timedelta, timezone

from pymongo import ReturnDocument

from . import dolibarr, mailer
from .db import get_db, now_utc, serialize

logger = logging.getLogger("it-tabelander.handover")

QUEUE_FIRST_PAUSE = timedelta(minutes=5)
QUEUE_LONGEST_PAUSE = timedelta(minutes=60)
QUEUE_MAX_ATTEMPTS = 72  # with the pauses above about three days
WARN_AFTER = timedelta(minutes=30)
STALE_CLAIM = timedelta(minutes=5)

# Everything a customer typed. After the hand-over it lives in Dolibarr only.
PERSONAL_FIELDS = (
    "contact", "description", "device_type", "device_source", "manufacturer",
    "model", "issues", "desired_services", "budget", "timeframe", "attachment_ids",
    "attachments", "callback_at", "source", "consent",
)


def new_queue(now: datetime) -> dict:
    return {"attempts": 0, "next_attempt_at": now, "warned_at": None, "gave_up": False}


def pause_after(attempts: int) -> timedelta:
    """5, 10, 20, 40, then 60 minutes between attempts."""
    doublings = min(max(attempts - 1, 0), 8)  # beyond that the hour caps it anyway
    return min(QUEUE_FIRST_PAUSE * (2 ** doublings), QUEUE_LONGEST_PAUSE)


attachments_of = dolibarr.attachments_of


async def _with_media_ids(db, photos: list[dict]) -> list[dict]:
    """Early records name a photo by its URL only; find its media entry."""
    resolved = []
    for item in photos:
        if item.get("id") != item.get("url"):
            resolved.append(item)
            continue
        media = await db.media.find_one({"url": item.get("url"), "kind": "repair_attachment"}, {"_id": 1})
        resolved.append({**item, "id": str(media["_id"])} if media else item)
    return resolved


async def store_sync_result(db, doc: dict, sync_result: dict, *, manual: bool = False) -> dict:
    """Save one attempt. On success drop the photos and the personal data;
    on failure plan the next attempt of an automatic record."""
    from .routers.media import delete_repair_attachments

    now = now_utc()
    update_set: dict = {"dolibarr": sync_result, "updated_at": now}
    update_unset: dict = {}
    if sync_result.get("synced"):
        uploaded = set(sync_result.get("documents_uploaded") or [])
        photos = attachments_of(doc)
        handed_over = [item for item in photos if str(item.get("id") or item.get("url")) in uploaded]
        # A photo not on the ticket keeps the whole record here.
        keep = len(handed_over) != len(photos)
        if handed_over and not keep:
            try:
                await delete_repair_attachments(await _with_media_ids(db, handed_over))
            except Exception as exc:  # noqa: BLE001
                # Safe in Dolibarr; clean_up_handed_over retries the website copy.
                logger.exception("Could not remove photos handed to Dolibarr: %s", exc)
                keep = True
            else:
                update_set["photos_in_dolibarr"] = len(handed_over)
        if not keep:
            update_unset.update({field: "" for field in PERSONAL_FIELDS})
            update_set["personal_data_removed_at"] = now
        update_unset["queue"] = ""
    elif doc.get("auto_handover"):
        queue = dict(doc.get("queue") or new_queue(now))
        if sync_result.get("stage") == "disabled":
            # Dolibarr switched off is no failed attempt; wait for it.
            queue["next_attempt_at"] = now + QUEUE_FIRST_PAUSE
        else:
            attempts = int(queue.get("attempts") or 0) + 1
            queue.update({
                "attempts": attempts,
                "next_attempt_at": now + pause_after(attempts),
                # A click on "Jetzt erneut versuchen" gives a stopped record
                # another round of automatic attempts.
                "gave_up": attempts >= QUEUE_MAX_ATTEMPTS and not manual,
            })
        update_set["queue"] = queue
    update = {"$set": update_set}
    if update_unset:
        update["$unset"] = update_unset
    await db.repair_requests.update_one({"_id": doc["_id"]}, update)
    return sync_result


async def _claim(db, query: dict, now: datetime):
    return await db.repair_requests.find_one_and_update(
        query,
        {"$set": {"dolibarr.sync_in_progress": True, "dolibarr.sync_started_at": now, "updated_at": now}},
        sort=[("created_at", 1)],
        return_document=ReturnDocument.AFTER,
    )


def _not_in_progress(now: datetime) -> dict:
    return {"$or": [
        {"dolibarr.sync_in_progress": {"$ne": True}},
        {"dolibarr.sync_started_at": {"$lt": now - STALE_CLAIM}},
    ]}


async def process_queue(*, force: bool = False, limit: int = 20) -> dict:
    """Try the waiting automatic records; force ignores the planned pause."""
    db = get_db()
    result = {"tried": 0, "synced": 0, "failed": 0, "dolibarr_enabled": await dolibarr.is_enabled()}
    if not result["dolibarr_enabled"]:
        return result
    done = []
    for _index in range(limit):
        now = now_utc()
        query = {
            "auto_handover": True,
            "queue": {"$exists": True},
            "queue.gave_up": {"$ne": True},
            "dolibarr.synced": {"$ne": True},
            "_id": {"$nin": done},
            **_not_in_progress(now),
        }
        if force:
            query.pop("queue.gave_up")
        else:
            query["queue.next_attempt_at"] = {"$lte": now}
        claimed = await _claim(db, query, now)
        if not claimed:
            break
        done.append(claimed["_id"])
        sync_result = await dolibarr.create_ticket_for_inquiry(claimed, previous=claimed.get("dolibarr") or {})
        await store_sync_result(db, claimed, sync_result, manual=force)
        result["tried"] += 1
        result["synced" if sync_result.get("synced") else "failed"] += 1
    return result


async def clean_up_handed_over(*, limit: int = 50) -> int:
    """Finish records whose photos could not be removed at hand-over time."""
    db = get_db()
    cleaned = 0
    docs = await db.repair_requests.find({
        "auto_handover": True,
        "dolibarr.synced": True,
        "personal_data_removed_at": {"$exists": False},
    }).to_list(limit)
    for doc in docs:
        await store_sync_result(db, doc, doc["dolibarr"])
        cleaned += 1
    return cleaned


# ------------------------------------------------------------------ warning

def _local_time(value) -> str:
    if not isinstance(value, datetime):
        return "–"
    if value.tzinfo is None:
        value = value.replace(tzinfo=timezone.utc)
    try:
        from zoneinfo import ZoneInfo
        return value.astimezone(ZoneInfo("Europe/Vienna")).strftime("%d.%m.%Y %H:%M")
    except Exception:  # noqa: BLE001 - no time zone data (Windows without tzdata)
        return value.astimezone(timezone.utc).strftime("%d.%m.%Y %H:%M UTC")


def _reason(doc: dict) -> str:
    sync = doc.get("dolibarr") or {}
    if sync.get("stage") == "disabled":
        return "Dolibarr ist in den Website-Einstellungen ausgeschaltet."
    error = sync.get("error") or {}
    if isinstance(error, dict) and error.get("message"):
        return str(error["message"])
    if sync.get("sync_in_progress"):
        return "Übergabe läuft gerade."
    return "Noch kein Versuch abgeschlossen."


async def warning_recipients() -> list[str]:
    db = get_db()
    settings = await db.settings.find_one({"_id": "site"}) or {}
    if settings.get("warning_email"):
        return [str(settings["warning_email"])]
    admins = await db.users.find({"role": "super_admin"}, {"email": 1}).to_list(10)
    return [str(admin["email"]) for admin in admins if admin.get("email")]


async def warn_about_waiting(*, now: datetime | None = None) -> int:
    """One mail about every automatic record waiting longer than 30 minutes
    that no earlier mail named. Returns how many it named."""
    db = get_db()
    now = now or now_utc()
    docs = await db.repair_requests.find({
        "auto_handover": True,
        "dolibarr.synced": {"$ne": True},
        "queue": {"$exists": True},
        "queue.warned_at": None,
        "created_at": {"$lte": now - WARN_AFTER},
    }).sort("created_at", 1).to_list(100)
    if not docs:
        return 0
    settings = await db.settings.find_one({"_id": "site"}) or {}
    site = str(settings.get("canonical_base_url") or "https://it.tabelander.co.at").rstrip("/")
    lines = [
        f"- {doc.get('ref')} ({dolibarr.REQUEST_TYPE_LABELS.get(doc.get('request_type') or 'repair', 'Anfrage')}, "
        f"seit {_local_time(doc.get('created_at'))}): {_reason(doc)}"
        for doc in docs
    ]
    count = len(docs)
    text = "\n".join([
        "Hallo,",
        "",
        f"{count} {'Anfrage ist' if count == 1 else 'Anfragen sind'} seit mehr als 30 Minuten nicht in "
        "Dolibarr angekommen. Die Website hat sie sicher gespeichert und versucht es automatisch weiter.",
        "",
        *lines,
        "",
        "Was du tun kannst:",
        "1. Prüfen, ob Dolibarr läuft und erreichbar ist.",
        f"2. In der Website-Verwaltung unter „Dolibarr“ den Stand ansehen und „Jetzt erneut versuchen“ klicken: {site}/admin/dolibarr",
        "",
        "Zu jeder Anfrage kommt diese Warnung nur einmal.",
    ])
    subject = f"Website: {count} {'Anfrage wartet' if count == 1 else 'Anfragen warten'} auf Dolibarr"
    status = {"_id": "handover_warning"}
    try:
        recipients = await warning_recipients()
        if not recipients:
            raise mailer.MailNotConfigured("Keine Empfänger-Adresse für Warnungen eingetragen.")
        await mailer.send_mail(recipients, subject, text)
    except (mailer.MailNotConfigured, mailer.MailError) as exc:
        await db.system_status.update_one(status, {"$set": {"last_error": str(exc), "last_error_at": now}},
                                          upsert=True)
        return 0
    await db.repair_requests.update_many({"_id": {"$in": [doc["_id"] for doc in docs]}},
                                         {"$set": {"queue.warned_at": now}})
    await db.system_status.update_one(status, {"$set": {"last_sent_at": now, "last_error": None}}, upsert=True)
    return count


async def run_cycle() -> dict:
    """What the maintenance loop does every five minutes."""
    result = await process_queue()
    result["cleaned"] = await clean_up_handed_over()
    result["warned"] = await warn_about_waiting()
    return result


async def queue_overview() -> dict:
    """For the admin: what waits, since when and why."""
    db = get_db()
    query = {"auto_handover": True, "dolibarr.synced": {"$ne": True}}
    docs = await db.repair_requests.find(query).sort("created_at", 1).to_list(50)
    status = await db.system_status.find_one({"_id": "handover_warning"}) or {}
    return {
        "waiting": await db.repair_requests.count_documents(query),
        "gave_up": await db.repair_requests.count_documents({**query, "queue.gave_up": True}),
        "items": [{
            "id": str(doc["_id"]),
            "ref": doc.get("ref"),
            "request_type": doc.get("request_type") or "repair",
            "created_at": serialize({"at": doc.get("created_at")})["at"],
            "reason": _reason(doc),
            "attempts": (doc.get("queue") or {}).get("attempts", 0),
            "next_attempt_at": serialize({"at": (doc.get("queue") or {}).get("next_attempt_at")})["at"],
            "warned_at": serialize({"at": (doc.get("queue") or {}).get("warned_at")})["at"],
            "gave_up": bool((doc.get("queue") or {}).get("gave_up")),
        } for doc in docs],
        "warning_mail": {
            "last_sent_at": serialize({"at": status.get("last_sent_at")})["at"],
            "last_error": status.get("last_error"),
            "last_error_at": serialize({"at": status.get("last_error_at")})["at"],
        },
    }


# ---------------------------------------------------------------- migration

OLD_UNSENT = {"auto_handover": {"$ne": True}, "dolibarr.synced": {"$ne": True}}
OLD_SENT_WITH_DATA = {
    "auto_handover": {"$ne": True},
    "dolibarr.synced": True,
    "personal_data_removed_at": {"$exists": False},
}


def contact_message_as_inquiry(message: dict) -> dict:
    """A message of the old contact inbox, shaped like a contact inquiry."""
    reference = hashlib.sha256(str(message["_id"]).encode()).hexdigest()[:8].upper()
    subject = str(message.get("subject") or "").strip()
    text = str(message.get("message") or "").strip()
    return {
        "request_type": "contact",
        "ref": f"ALT-{reference}",
        "track_id": f"ITALT{reference}",
        "description": f"Betreff: {subject}\n\n{text}" if subject else text,
        "contact": {
            "name": message.get("name") or "",
            "email": str(message.get("email") or "").strip().lower(),
            "phone": message.get("phone") or "",
        },
        "created_at": message.get("created_at"),
    }


async def migration_plan() -> dict:
    """The dry run: what the migration would do, changing nothing."""
    db = get_db()
    unsent = await db.repair_requests.find(OLD_UNSENT).sort("created_at", 1).to_list(200)
    sent = await db.repair_requests.find(OLD_SENT_WITH_DATA).sort("created_at", 1).to_list(200)
    messages = await db.contact_messages.find().sort("created_at", 1).to_list(200)

    def row(doc, what):
        return {"ref": doc.get("ref"), "created_at": serialize({"at": doc.get("created_at")})["at"],
                "request_type": doc.get("request_type") or "repair", "what": what,
                "photos": len(attachments_of(doc))}

    return {
        "inquiries_to_send": await db.repair_requests.count_documents(OLD_UNSENT),
        "inquiries_to_finish": await db.repair_requests.count_documents(OLD_SENT_WITH_DATA),
        "contact_messages": await db.contact_messages.count_documents({}),
        "items": [
            *(row(doc, "wird als Ticket an Dolibarr übergeben, ohne Mail an den Kunden") for doc in unsent),
            *(row(doc, "Ticket gibt es schon; Fotos kommen ans Ticket, dann werden die Daten hier gelöscht")
              for doc in sent),
            *({"ref": contact_message_as_inquiry(msg)["ref"],
               "created_at": serialize({"at": msg.get("created_at")})["at"],
               "request_type": "contact", "photos": 0,
               "what": "alte Kontaktnachricht wird ein Ticket, ohne Mail an den Absender"} for msg in messages),
        ],
    }


async def run_migration(*, limit: int = 25) -> dict:
    """Hand the old records over, at most ``limit`` per call, without
    confirmation mails; each success is cleaned up at once."""
    db = get_db()
    result = {"sent": 0, "finished": 0, "contact_messages": 0, "failed": [], "remaining": 0}
    if not await dolibarr.is_enabled():
        raise RuntimeError("Dolibarr ist nicht aktiviert oder der API-Key fehlt.")
    budget = limit
    for query, key in ((OLD_UNSENT, "sent"), (OLD_SENT_WITH_DATA, "finished")):
        while budget > 0:
            now = now_utc()
            claimed = await _claim(db, {**query, **_not_in_progress(now),
                                        "_id": {"$nin": [item["id"] for item in result["failed"]]}}, now)
            if not claimed:
                break
            budget -= 1
            sync_result = await dolibarr.create_ticket_for_inquiry(
                claimed, previous=claimed.get("dolibarr") or {}, notify=False,
            )
            await store_sync_result(db, claimed, sync_result)
            if sync_result.get("synced"):
                result[key] += 1
            else:
                result["failed"].append({"id": claimed["_id"], "ref": claimed.get("ref"), "reason": _reason(
                    {"dolibarr": sync_result})})
    for message in await db.contact_messages.find().sort("created_at", 1).to_list(max(budget, 0)):
        inquiry = contact_message_as_inquiry(message)
        sync_result = await dolibarr.create_ticket_for_inquiry(inquiry, notify=False)
        if sync_result.get("synced"):
            await db.contact_messages.delete_one({"_id": message["_id"]})
            result["contact_messages"] += 1
        else:
            result["failed"].append({"id": message["_id"], "ref": inquiry["ref"],
                                     "reason": _reason({"dolibarr": sync_result})})
    failed_ids = [item["id"] for item in result["failed"]]
    result["remaining"] = (
        await db.repair_requests.count_documents({**OLD_UNSENT, "_id": {"$nin": failed_ids}})
        + await db.repair_requests.count_documents({**OLD_SENT_WITH_DATA, "_id": {"$nin": failed_ids}})
        + await db.contact_messages.count_documents({"_id": {"$nin": failed_ids}})
    )
    result["failed"] = [{"ref": item["ref"], "reason": item["reason"]} for item in result["failed"]]
    return result

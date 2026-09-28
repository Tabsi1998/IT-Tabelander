"""After the job, one mail asking for a review (#71).

Only for inquiries whose sender said yes to it in the form. The maintenance
loop asks Dolibarr every 30 minutes whether the ticket is closed; then the
customer gets one mail with a personal link, to the address in the ticket and
nowhere else. The link works 60 days and for one review. What the customer
writes waits hidden in the admin until the owner releases it.

At most once: a record is marked as invited before the mail leaves, so a
crash in between never sends a second mail - it sends none, which is the
better mistake. A mail that fails outright is taken back and tried again.
"""
import hashlib
import logging
import secrets
from datetime import datetime, timedelta, timezone

from pymongo import ReturnDocument

from . import dolibarr, mailer
from .db import get_db, now_utc

logger = logging.getLogger("it-tabelander.review-invites")

CHECK_EVERY = timedelta(minutes=30)
LINK_VALID = timedelta(days=60)
# A ticket still open after half a year gets no request any more.
STOP_AFTER = timedelta(days=183)
LINK_GONE = "Dieser Link ist abgelaufen oder wurde schon verwendet."


def token_hash(token: str) -> str:
    """Only the hash is stored; the link itself exists in the mail alone."""
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def review_link(site_base: str, token: str) -> str:
    # After the "#": browsers never send it, so it is in no log and no Referer.
    return f"{site_base.rstrip('/')}/bewertung#{token}"


def mail_text(*, ref: str, kind: str, link: str, sender_name: str, site_base: str) -> tuple[str, str]:
    subject = f"Kurze Bitte zu deiner Anfrage {ref}"
    text = f"""Hallo,

deine Anfrage {ref} ({kind}) ist abgeschlossen. Danke für dein Vertrauen!

Bei der Anfrage hast du Ja gesagt, dass ich dich danach um eine kurze
Bewertung bitten darf. Wenn du magst, schreib mir in zwei Minuten, wie es war:

{link}

Der Link gilt 60 Tage und für eine Bewertung. Sie erscheint erst auf der
Website, wenn ich sie freigegeben habe - mit dem Namen, den du dort angibst.

Das ist die einzige Mail dazu, es kommt keine Erinnerung.

Viele Grüße
{sender_name}
{site_base}
"""
    return subject, text


def _due(now: datetime, *, force: bool) -> dict:
    query = {
        "review_ok": True,
        "dolibarr.synced": True,
        "review.invited_at": {"$exists": False},
        "review.stopped": {"$exists": False},
    }
    # A claim moves the next check exactly one interval ahead; "Jetzt prüfen"
    # takes every record not claimed in this very round.
    limit = now + CHECK_EVERY if force else now
    query["$or"] = [{"review.next_check_at": {"$exists": False}},
                    {"review.next_check_at": {"$lt" if force else "$lte": limit}}]
    return query


async def _stop(db, doc: dict, reason: str) -> str:
    await db.repair_requests.update_one(
        {"_id": doc["_id"]}, {"$set": {"review.stopped": reason}, "$unset": {"review.last_error": ""}},
    )
    return "stopped"


async def _check_one(db, doc: dict, now: datetime, *, site_base: str, sender_name: str) -> str:
    ticket_id = (doc.get("dolibarr") or {}).get("ticket_id")
    if not ticket_id:
        return await _stop(db, doc, "Zu dieser Anfrage gibt es kein Ticket.")
    try:
        ticket = await dolibarr.fetch_ticket_status(str(ticket_id))
    except Exception as exc:  # noqa: BLE001 - Dolibarr away: the next round asks again
        logger.warning("Ticket state for a review request unavailable: %s", type(exc).__name__)
        return "away"
    if ticket is None:
        return await _stop(db, doc, "Das Ticket gibt es in Dolibarr nicht mehr.")
    if ticket["step"] == "abgebrochen":
        return await _stop(db, doc, "Das Ticket wurde abgebrochen.")
    if ticket["step"] != "abgeschlossen":
        created = doc.get("created_at")
        if isinstance(created, datetime) and now - created > STOP_AFTER:
            return await _stop(db, doc, "Das Ticket ist seit einem halben Jahr offen.")
        return "later"
    email = ticket.get("origin_email") or ""
    if "@" not in email:
        # Never another address: without the customer's own, no mail at all.
        return await _stop(db, doc, "Im Ticket steht keine E-Mail-Adresse.")

    claimed = await db.repair_requests.find_one_and_update(
        {"_id": doc["_id"], "review.invited_at": {"$exists": False}},
        {"$set": {"review.invited_at": now}},
    )
    if not claimed:
        return "later"
    token = secrets.token_urlsafe(32)
    invite = {
        "token_hash": token_hash(token),
        "inquiry_id": doc["_id"],
        "ref": doc.get("ref"),
        "request_type": doc.get("request_type") or "repair",
        "created_at": now,
        "expires_at": now + LINK_VALID,
    }
    await db.review_invites.insert_one(invite)
    kind = dolibarr.REQUEST_TYPE_LABELS.get(invite["request_type"], "Anfrage")
    subject, text = mail_text(ref=str(doc.get("ref") or ""), kind=kind, link=review_link(site_base, token),
                              sender_name=sender_name, site_base=site_base)
    try:
        await mailer.send_mail(email, subject, text)
    except (mailer.MailNotConfigured, mailer.MailError) as exc:
        await db.review_invites.delete_one({"_id": invite["_id"]})
        await db.repair_requests.update_one(
            {"_id": doc["_id"]},
            {"$unset": {"review.invited_at": ""}, "$set": {"review.last_error": str(exc)}},
        )
        return "failed"
    await db.repair_requests.update_one({"_id": doc["_id"]}, {"$unset": {"review.last_error": ""}})
    return "sent"


async def run(*, force: bool = False, limit: int = 20, now: datetime | None = None) -> dict:
    """Check the inquiries that are due; send a request for each closed ticket."""
    db = get_db()
    now = now or now_utc()
    result = {"checked": 0, "sent": 0, "stopped": 0, "failed": 0, "problem": None}
    mail = await mailer.get_mail_config()
    if not mail["host"] or not mail["sender"]:
        result["problem"] = "Der E-Mail-Versand ist noch nicht eingerichtet (Technik)."
        return result
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        result["problem"] = "Dolibarr ist nicht verbunden."
        return result
    for _index in range(limit):
        # Moving the next check ahead claims the record: a second process or
        # a click on "Jetzt prüfen" at the same time passes it by.
        doc = await db.repair_requests.find_one_and_update(
            _due(now, force=force), {"$set": {"review.next_check_at": now + CHECK_EVERY}},
            sort=[("created_at", 1)], return_document=ReturnDocument.AFTER,
        )
        if not doc:
            break
        result["checked"] += 1
        outcome = await _check_one(db, doc, now, site_base=cfg["site_base"],
                                   sender_name=mail.get("sender_name") or "IT-Tabelander")
        if outcome in ("sent", "stopped", "failed"):
            result[outcome] += 1
        if outcome == "away":
            # The others would wait for the same timeout one by one: stop this round.
            result["problem"] = "Dolibarr ist gerade nicht erreichbar; die nächste Runde fragt wieder."
            break
    return result


async def overview() -> dict:
    """For the admin: how many wait, went out, came back."""
    db = get_db()
    wanted = {"review_ok": True}
    last_error = await db.repair_requests.find_one(
        {**wanted, "review.last_error": {"$exists": True}}, {"review.last_error": 1}, sort=[("updated_at", -1)],
    )
    return {
        "waiting": await db.repair_requests.count_documents(
            {**wanted, "review.invited_at": {"$exists": False}, "review.stopped": {"$exists": False}}),
        "sent": await db.repair_requests.count_documents({**wanted, "review.invited_at": {"$exists": True}}),
        "answered": await db.review_invites.count_documents({"used_at": {"$exists": True}}),
        "last_error": ((last_error or {}).get("review") or {}).get("last_error"),
    }


async def open_invite(token: str) -> dict | None:
    return await get_db().review_invites.find_one(
        {"token_hash": token_hash(token), "used_at": {"$exists": False}, "expires_at": {"$gt": now_utc()}},
    )


def review_date(now: datetime) -> str:
    try:
        from zoneinfo import ZoneInfo
        return now.astimezone(ZoneInfo("Europe/Vienna")).date().isoformat()
    except Exception:  # noqa: BLE001 - no time zone data (Windows without tzdata)
        return now.astimezone(timezone.utc).date().isoformat()


async def submit(token: str, *, rating: int, text: str, author: str) -> bool:
    """Store the review hidden and pending; the link is used up."""
    db = get_db()
    now = now_utc()
    invite = await db.review_invites.find_one_and_update(
        {"token_hash": token_hash(token), "used_at": {"$exists": False}, "expires_at": {"$gt": now}},
        {"$set": {"used_at": now}},
        return_document=ReturnDocument.AFTER,
    )
    if not invite:
        return False
    review = {
        "author": author, "rating": rating, "text": text, "source": "Website", "source_url": "",
        "review_date": review_date(now), "is_demo": False, "featured": False, "sort": 0,
        "visible": False, "pending": True, "inquiry_ref": invite.get("ref"), "created_at": now,
    }
    try:
        await db.reviews.insert_one(review)
    except Exception:
        await db.review_invites.update_one({"_id": invite["_id"]}, {"$unset": {"used_at": ""}})
        raise
    return True

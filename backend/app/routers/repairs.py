import hashlib
import json
import logging
import re
import secrets
import string
from datetime import timedelta

from fastapi import APIRouter, Depends, HTTPException
from pymongo import ReturnDocument
from pymongo.errors import DuplicateKeyError

from .. import dolibarr, handover
from ..db import get_db, now_utc, serialize, to_oid
from ..website import seo_data
from ..models import ContactInput, InquiryInput
from ..security import require_admin
from .media import mark_repair_attachments_linked, release_repair_attachment_claims

router = APIRouter(prefix="/api", tags=["inquiries"])
logger = logging.getLogger("it-tabelander.inquiries")


def _inquiry_ref() -> str:
    alphabet = string.ascii_uppercase + string.digits
    return "ANF-" + "".join(secrets.choice(alphabet) for _ in range(8))


def _track_id() -> str:
    """Random Dolibarr tracking id; the link in the confirmation mail names it
    (#43). Since #122 it opens the inquiry in the customer area, after sign-in."""
    alphabet = string.ascii_uppercase + string.digits
    return "IT" + "".join(secrets.choice(alphabet) for _ in range(14))


def _created_response(doc: dict, duplicate: bool = False) -> dict:
    dolibarr_data = doc.get("dolibarr") or {}
    return {
        "ok": True,
        "ref": doc["ref"],
        "id": str(doc["_id"]),
        "duplicate": duplicate,
        # Do not expose ERP error details on this public endpoint. They remain
        # available to authenticated staff in the admin area.
        "dolibarr_synced": bool(dolibarr_data.get("synced")),
        "ticket_ref": dolibarr_data.get("ticket_ref") if dolibarr_data.get("synced") else None,
    }


def _payload_hash(data: dict, model=InquiryInput) -> str:
    normalized = {
        field: data[field]
        for field in model.model_fields
        if field != "honeypot" and field in data
    }
    encoded = json.dumps(
        normalized,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
        default=str,
    ).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def _existing_payload_hash(existing: dict) -> str | None:
    stored = existing.get("payload_hash")
    if stored:
        return str(stored)
    try:
        legacy_payload = InquiryInput.model_validate({
            field: existing[field]
            for field in InquiryInput.model_fields
            if field in existing
        }).model_dump(mode="json", exclude_none=True)
    except Exception:  # noqa: BLE001
        return None
    legacy_payload.pop("honeypot", None)
    return _payload_hash(legacy_payload)


def _duplicate_or_conflict(existing: dict, payload_hash: str) -> dict:
    if _existing_payload_hash(existing) != payload_hash:
        raise HTTPException(
            status_code=409,
            detail="Diese Anfrage-ID wurde bereits mit anderen Angaben verwendet.",
        )
    return _created_response(existing, duplicate=True)


async def _release_claims_safely(attachment_ids: list, claim_token: str) -> None:
    try:
        await release_repair_attachment_claims(attachment_ids, claim_token)
    except Exception as exc:  # noqa: BLE001
        # Startup recovery resolves a leftover claim after a process/database
        # interruption. Preserve the original customer-facing outcome here.
        logger.exception("Could not release inquiry attachment claims: %s", exc)


async def _claim_attachments(
    db, attachment_ids: list[str], request_id: str | None, claim_token: str,
) -> tuple[list[dict], list]:
    if not attachment_ids:
        return [], []
    if not request_id:
        raise HTTPException(status_code=400, detail="Anfrage-ID für Anhänge fehlt")
    object_ids = [to_oid(attachment_id) for attachment_id in attachment_ids]
    by_id = {}
    claimed_ids = []
    for attachment_id, object_id in zip(attachment_ids, object_ids):
        doc = await db.media.find_one_and_update(
            {
                "_id": object_id,
                "kind": "repair_attachment",
                "draft_request_id": request_id,
                "linked_at": {"$exists": False},
                "attachment_claim": {"$exists": False},
                "attachment_deleting": {"$exists": False},
                "expires_at": {"$gt": now_utc()},
            },
            {"$set": {
                "attachment_claim": claim_token,
                "attachment_claimed_at": now_utc(),
            }},
            return_document=ReturnDocument.AFTER,
        )
        if not doc:
            await _release_claims_safely(claimed_ids, claim_token)
            raise HTTPException(
                status_code=400,
                detail="Mindestens ein Anhang ist ungültig oder wird bereits verarbeitet",
            )
        claimed_ids.append(object_id)
        by_id[attachment_id] = doc
    attachments = [
        {"id": attachment_id, "url": by_id[attachment_id]["url"]}
        for attachment_id in attachment_ids
    ]
    return attachments, object_ids


@router.post("/inquiries")
async def create_inquiry(payload: InquiryInput):
    if payload.honeypot:
        raise HTTPException(status_code=400, detail="Ungültige Anfrage")
    if not payload.consent:
        raise HTTPException(status_code=400, detail="Zustimmung zum Datenschutz erforderlich")

    db = get_db()
    data = payload.model_dump(mode="json", exclude_none=True)
    data.pop("honeypot", None)
    request_id = data["request_id"]
    payload_hash = _payload_hash(data)

    # A client-generated request id makes browser/network retries safe. The
    # unique partial DB index also protects against parallel duplicate posts.
    existing = await db.repair_requests.find_one({"request_id": request_id})
    if existing:
        return _duplicate_or_conflict(existing, payload_hash)

    attachment_claim = secrets.token_urlsafe(24)
    data["attachments"], attachment_object_ids = await _claim_attachments(
        db, data.get("attachment_ids") or [], request_id, attachment_claim,
    )

    timestamp = now_utc()
    data.update(_new_record(payload_hash, timestamp))
    try:
        result = await db.repair_requests.insert_one(data)
    except DuplicateKeyError:
        await _release_claims_safely(attachment_object_ids, attachment_claim)
        existing = await db.repair_requests.find_one({"request_id": request_id})
        if not existing:
            raise
        return _duplicate_or_conflict(existing, payload_hash)
    except Exception:
        await _release_claims_safely(attachment_object_ids, attachment_claim)
        raise

    data["_id"] = result.inserted_id
    if attachment_object_ids:
        try:
            await mark_repair_attachments_linked(
                attachment_object_ids, request_id, result.inserted_id,
                attachment_claim,
            )
        except Exception as exc:  # noqa: BLE001
            # The inquiry itself is already durable. Do not turn a successful
            # customer submission into a 500 response because attachment
            # metadata could not be finalized in the same moment.
            logger.exception("Could not link inquiry attachments: %s", exc)
    return await _hand_over(db, data)


def _new_record(payload_hash: str, timestamp) -> dict:
    """What every new inquiry and contact message starts with."""
    return {
        "payload_hash": payload_hash,
        "ref": _inquiry_ref(),
        "track_id": _track_id(),
        "status": "eingegangen",
        "dolibarr": {
            "synced": False,
            "stage": "pending",
            "error": None,
            "http_status": None,
            "sync_in_progress": True,
            "sync_started_at": timestamp,
        },
        # The queue retries and warns for these; older records wait for the
        # one-time migration (#39, #41).
        "auto_handover": True,
        "queue": handover.new_queue(timestamp),
        "created_at": timestamp,
        "updated_at": timestamp,
    }


async def _hand_over(db, data: dict) -> dict:
    # Dolibarr is deliberately best-effort and runs only after persistence.
    # A failed ERP call therefore never loses the customer's inquiry; the
    # queue tries again (#39).
    sync_result = await dolibarr.create_ticket_for_inquiry(data)
    data["dolibarr"] = sync_result
    await handover.store_sync_result(db, data, sync_result)
    return _created_response(data)


@router.post("/contact")
async def create_contact_message(payload: ContactInput):
    """The contact form: stored, then a Dolibarr ticket without a new third
    party (#42). A known sender is linked to its customer."""
    if payload.honeypot:
        raise HTTPException(status_code=400, detail="Ungültige Anfrage")
    if not payload.consent:
        raise HTTPException(status_code=400, detail="Zustimmung zum Datenschutz erforderlich")
    db = get_db()
    form = payload.model_dump(mode="json", exclude_none=True)
    form.pop("honeypot", None)
    payload_hash = _payload_hash(form, ContactInput)
    existing = await db.repair_requests.find_one({"request_id": payload.request_id})
    if existing:
        return _duplicate_or_conflict(existing, payload_hash)
    data = {
        "request_type": "contact",
        "source": "website",
        "request_id": payload.request_id,
        "description": payload.message,
        **({"topic": payload.topic} if payload.topic else {}),
        "contact": {
            "name": payload.name,
            "email": str(payload.email).lower(),
            "phone": payload.phone or "",
            "preferred_contact": "phone" if payload.callback_at else "email",
        },
        "consent": True,
        "attachments": [],
        **({"callback_at": form["callback_at"]} if payload.callback_at else {}),
        **_new_record(payload_hash, now_utc()),
    }
    try:
        result = await db.repair_requests.insert_one(data)
    except DuplicateKeyError:
        existing = await db.repair_requests.find_one({"request_id": payload.request_id})
        if not existing:
            raise
        return _duplicate_or_conflict(existing, payload_hash)
    data["_id"] = result.inserted_id
    return await _hand_over(db, data)


@router.get("/admin/labels/{ref}")
async def device_label(ref: str, _: dict = Depends(require_admin)):
    """What the device label shows (#72): number, device, date and the QR
    link to the inquiry in the customer area (#122). No name: the label sits on the device in the shop."""
    wanted = ref.strip()
    if not re.fullmatch(r"[A-Za-z0-9-]{4,40}", wanted):
        raise HTTPException(status_code=404, detail="Keine Anfrage mit dieser Nummer gefunden.")
    doc = await get_db().repair_requests.find_one(
        {"$or": [{"ref": wanted.upper()}, {"dolibarr.ticket_ref": wanted}]},
        sort=[("created_at", -1)],
    )
    if not doc:
        raise HTTPException(status_code=404, detail="Keine Anfrage mit dieser Nummer gefunden.")
    sync = doc.get("dolibarr") or {}
    kind = dolibarr.REQUEST_TYPE_LABELS.get(doc.get("request_type") or "repair", "Anfrage")
    title = ""
    if doc.get("device_type") or doc.get("manufacturer") or doc.get("model"):
        title = dolibarr.subject_without_ref(dolibarr.inquiry_subject(doc), doc.get("ref"))
    elif sync.get("synced") and sync.get("ticket_id"):
        try:
            subject = await dolibarr.fetch_ticket_subject(str(sync["ticket_id"]))
        except Exception as exc:  # noqa: BLE001 - the label works without it
            logger.warning("Ticket subject for a label unavailable: %s", type(exc).__name__)
            subject = None
        title = dolibarr.subject_without_ref(subject or "", doc.get("ref"))
    track_id = doc.get("track_id") or sync.get("ticket_track_id") or dolibarr.inquiry_track_id(doc)
    base, _settings, _cache = await seo_data()
    return {
        "ref": doc.get("ref"),
        "ticket_ref": sync.get("ticket_ref") if sync.get("synced") else None,
        "request_type_label": kind,
        "title": title or kind,
        "created_at": serialize({"at": doc.get("created_at")})["at"],
        "status_url": f"{base}/status/view.php?track_id={track_id}",
    }


async def recover_stale_dolibarr_syncs(
    *, started_before=None, limit: int = 10,
) -> int:
    """Resume syncs interrupted after the local inquiry was persisted."""
    db = get_db()
    cutoff = started_before or (now_utc() - timedelta(minutes=5))
    recovered = 0
    for _index in range(limit):
        claimed = await db.repair_requests.find_one_and_update(
            {
                "dolibarr.synced": {"$ne": True},
                "dolibarr.sync_in_progress": True,
                "$or": [
                    {"dolibarr.sync_started_at": {"$lte": cutoff}},
                    {"dolibarr.sync_started_at": {"$exists": False}},
                ],
            },
            {"$set": {
                "dolibarr.sync_started_at": now_utc(),
                "updated_at": now_utc(),
            }},
            sort=[("created_at", 1)],
            return_document=ReturnDocument.AFTER,
        )
        if not claimed:
            break
        sync_result = await dolibarr.create_ticket_for_inquiry(
            claimed, previous=claimed.get("dolibarr") or {},
            notify=bool(claimed.get("auto_handover")),
        )
        await handover.store_sync_result(db, claimed, sync_result)
        recovered += 1
    return recovered

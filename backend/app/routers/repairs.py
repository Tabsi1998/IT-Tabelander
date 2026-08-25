import hashlib
import json
import logging
import secrets
import string
from datetime import timedelta

from fastapi import APIRouter, Depends, HTTPException, Query
from pymongo import ReturnDocument
from pymongo.errors import DuplicateKeyError

from .. import dolibarr
from ..db import get_db, now_utc, serialize, to_oid
from ..models import InquiryInput, InquiryStatusUpdate
from ..security import require_admin
from .media import (
    delete_repair_attachments,
    mark_repair_attachments_linked,
    release_repair_attachment_claims,
)

router = APIRouter(prefix="/api", tags=["inquiries"])
logger = logging.getLogger("it-tabelander.inquiries")

# New inquiries use workflow-neutral states. Historical repair-only states stay
# accepted so existing records and older admin clients remain editable.
STATUSES = [
    "eingegangen",
    "in_bearbeitung",
    "wartet_auf_kunde",
    "angebot_erstellt",
    "beauftragt",
    "abgeschlossen",
    "abgelehnt",
]
LEGACY_STATUSES = ["in_diagnose", "warten_auf_teile", "in_reparatur", "fertig"]
VALID_STATUSES = set(STATUSES + LEGACY_STATUSES)
REQUEST_TYPES = {"repair", "pc_build", "pc_upgrade", "controller_custom", "consulting", "other"}


def _inquiry_ref() -> str:
    alphabet = string.ascii_uppercase + string.digits
    return "ANF-" + "".join(secrets.choice(alphabet) for _ in range(8))


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
        "ticket_public_url": (
            dolibarr_data.get("ticket_public_url") if dolibarr_data.get("synced") else None
        ),
    }


def _payload_hash(data: dict) -> str:
    normalized = {
        field: data[field]
        for field in InquiryInput.model_fields
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
@router.post("/repairs", include_in_schema=False)
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
    data.update({
        "payload_hash": payload_hash,
        "ref": _inquiry_ref(),
        "status": "eingegangen",
        "dolibarr": {
            "synced": False,
            "stage": "pending",
            "error": None,
            "http_status": None,
            "sync_in_progress": True,
            "sync_started_at": timestamp,
        },
        "created_at": timestamp,
        "updated_at": timestamp,
    })
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
    # Dolibarr is deliberately best-effort and runs only after persistence.
    # A failed ERP call therefore never loses the customer's inquiry.
    sync_result = await dolibarr.create_ticket_for_inquiry(data)
    data["dolibarr"] = sync_result
    await db.repair_requests.update_one(
        {"_id": result.inserted_id},
        {"$set": {"dolibarr": sync_result, "updated_at": now_utc()}},
    )
    return _created_response(data)


@router.get("/admin/inquiries")
@router.get("/admin/repairs", include_in_schema=False)
async def list_inquiries(
    status: str | None = None,
    request_type: str | None = Query(default=None),
    _: dict = Depends(require_admin),
):
    query = {}
    if status:
        query["status"] = status
    if request_type:
        if request_type not in REQUEST_TYPES:
            raise HTTPException(status_code=400, detail="Ungültige Anfrageart")
        if request_type == "repair":
            query["$or"] = [
                {"request_type": "repair"},
                {"request_type": {"$exists": False}},
            ]
        else:
            query["request_type"] = request_type
    docs = await get_db().repair_requests.find(query).sort("created_at", -1).to_list(500)
    return [serialize(doc) for doc in docs]


@router.get("/admin/inquiries/{inquiry_id}")
@router.get("/admin/repairs/{inquiry_id}", include_in_schema=False)
async def get_inquiry(inquiry_id: str, _: dict = Depends(require_admin)):
    doc = await get_db().repair_requests.find_one({"_id": to_oid(inquiry_id)})
    if not doc:
        raise HTTPException(status_code=404, detail="Anfrage nicht gefunden")
    return serialize(doc)


@router.patch("/admin/inquiries/{inquiry_id}/status")
@router.patch("/admin/repairs/{inquiry_id}/status", include_in_schema=False)
async def update_status(
    inquiry_id: str,
    payload: InquiryStatusUpdate,
    _: dict = Depends(require_admin),
):
    if payload.status not in VALID_STATUSES:
        raise HTTPException(status_code=400, detail="Ungültiger Status")
    result = await get_db().repair_requests.update_one(
        {"_id": to_oid(inquiry_id)},
        {"$set": {"status": payload.status, "updated_at": now_utc()}},
    )
    if not result.matched_count:
        raise HTTPException(status_code=404, detail="Anfrage nicht gefunden")
    return {"ok": True}


@router.post("/admin/inquiries/{inquiry_id}/sync-dolibarr")
@router.post("/admin/repairs/{inquiry_id}/sync-dolibarr", include_in_schema=False)
async def retry_dolibarr(inquiry_id: str, _: dict = Depends(require_admin)):
    db = get_db()
    object_id = to_oid(inquiry_id)
    stale_before = now_utc() - timedelta(minutes=5)
    claimed = await db.repair_requests.find_one_and_update(
        {
            "_id": object_id,
            "dolibarr.synced": {"$ne": True},
            "$or": [
                {"dolibarr.sync_in_progress": {"$ne": True}},
                {"dolibarr.sync_started_at": {"$lt": stale_before}},
            ],
        },
        {"$set": {
            "dolibarr.sync_in_progress": True,
            "dolibarr.sync_started_at": now_utc(),
            "updated_at": now_utc(),
        }},
        return_document=ReturnDocument.AFTER,
    )
    if not claimed:
        existing = await db.repair_requests.find_one({"_id": object_id})
        if not existing:
            raise HTTPException(status_code=404, detail="Anfrage nicht gefunden")
        if (existing.get("dolibarr") or {}).get("synced"):
            return {"ok": True, "already_synced": True,
                    "dolibarr": serialize(existing["dolibarr"])}
        raise HTTPException(status_code=409, detail="Dolibarr-Synchronisierung läuft bereits")

    sync_result = await dolibarr.create_ticket_for_inquiry(
        claimed, previous=claimed.get("dolibarr") or {},
    )
    await db.repair_requests.update_one(
        {"_id": object_id},
        {"$set": {"dolibarr": sync_result, "updated_at": now_utc()}},
    )
    return {"ok": bool(sync_result.get("synced")), "dolibarr": serialize(sync_result)}


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
        )
        await db.repair_requests.update_one(
            {"_id": claimed["_id"]},
            {"$set": {"dolibarr": sync_result, "updated_at": now_utc()}},
        )
        recovered += 1
    return recovered


@router.delete("/admin/inquiries/{inquiry_id}")
@router.delete("/admin/repairs/{inquiry_id}", include_in_schema=False)
async def delete_inquiry(inquiry_id: str, _: dict = Depends(require_admin)):
    db = get_db()
    object_id = to_oid(inquiry_id)
    inquiry = await db.repair_requests.find_one({"_id": object_id})
    if not inquiry:
        raise HTTPException(status_code=404, detail="Anfrage nicht gefunden")
    try:
        await delete_repair_attachments(inquiry.get("attachments") or [])
    except Exception as exc:  # noqa: BLE001
        logger.exception("Could not delete inquiry attachments: %s", exc)
        raise HTTPException(
            status_code=500,
            detail="Anfrage-Fotos konnten nicht vollständig gelöscht werden; bitte erneut versuchen",
        ) from None
    result = await db.repair_requests.delete_one({"_id": object_id})
    if not result.deleted_count:
        raise HTTPException(status_code=404, detail="Anfrage nicht gefunden")
    return {"ok": True}

import io
import logging
import os
import re
import uuid
from datetime import timedelta

from bson import ObjectId
from fastapi import (APIRouter, Depends, File, Form, HTTPException, UploadFile)
from fastapi.responses import FileResponse
from PIL import Image, ImageOps
from pymongo import ReturnDocument
from pymongo.errors import DuplicateKeyError

from ..db import get_db, now_utc, serialize
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["media"])
logger = logging.getLogger("it-tabelander.media")

UPLOAD_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "uploads")
os.makedirs(UPLOAD_DIR, exist_ok=True)

ALLOWED = {"image/jpeg", "image/png", "image/webp", "image/gif"}
MAX_BYTES = 8 * 1024 * 1024  # 8 MB
MAX_PIXELS = 25_000_000
MAX_DRAFT_ATTACHMENTS = 5
ATTACHMENT_TTL = timedelta(hours=24)
REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$")


def _remove_upload(filename: str) -> None:
    safe = os.path.basename(filename)
    path = os.path.join(UPLOAD_DIR, safe)
    if os.path.isfile(path):
        os.remove(path)


async def _claim_media_deletion(db, query: dict) -> tuple[str, dict | None]:
    token = uuid.uuid4().hex
    doc = await db.media.find_one_and_update(
        {**query, "attachment_deleting": {"$exists": False}},
        {"$set": {
            "attachment_deleting": token,
            "attachment_deleting_at": now_utc(),
        }},
        return_document=ReturnDocument.AFTER,
    )
    return token, doc


async def _finish_media_deletion(db, doc: dict, token: str) -> None:
    """Delete the file and document while keeping an I/O failure retryable."""
    try:
        _remove_upload(doc.get("filename", ""))
        result = await db.media.delete_one({
            "_id": doc["_id"],
            "attachment_deleting": token,
        })
        if result.deleted_count != 1:
            raise RuntimeError("Medien-Datensatz konnte nicht final gelöscht werden")
    except Exception:
        try:
            await db.media.update_one(
                {"_id": doc["_id"], "attachment_deleting": token},
                {"$unset": {
                    "attachment_deleting": "",
                    "attachment_deleting_at": "",
                }},
            )
        except Exception as release_error:  # noqa: BLE001
            logger.exception("Could not release media deletion claim: %s", release_error)
        raise


async def recover_interrupted_media_deletions(
    limit: int = 500, *, deleting_before=None,
) -> int:
    """Finish deletions interrupted between filesystem and database writes."""
    db = get_db()
    cutoff = deleting_before or (now_utc() - timedelta(minutes=15))
    docs = await db.media.find({
        "attachment_deleting": {"$type": "string"},
        "$or": [
            {"attachment_deleting_at": {"$lte": cutoff}},
            {"attachment_deleting_at": {"$exists": False}},
        ],
    }).limit(limit).to_list(limit)
    processed = 0
    for doc in docs:
        token = doc.get("attachment_deleting")
        try:
            await _finish_media_deletion(db, doc, token)
            processed += 1
        except Exception as exc:  # noqa: BLE001
            logger.exception("Interrupted media deletion recovery failed: %s", exc)
    return processed


async def cleanup_expired_repair_attachments(limit: int = 500) -> int:
    """Remove expired inquiry drafts from MongoDB and from disk.

    A MongoDB TTL index alone would leave the image file behind, so cleanup is
    deliberately handled by the application and runs at startup and on upload.
    """
    db = get_db()
    expiry_cutoff = now_utc()
    docs = await db.media.find({
        "kind": "repair_attachment",
        "linked_at": {"$exists": False},
        "attachment_claim": {"$exists": False},
        "expires_at": {"$lte": expiry_cutoff},
    }).limit(limit).to_list(limit)
    removed = 0
    for candidate in docs:
        # Re-check every state predicate while deleting. A submit may have
        # claimed or linked the draft after the initial scan.
        token, doc = await _claim_media_deletion(db, {
            "_id": candidate["_id"],
            "kind": "repair_attachment",
            "linked_at": {"$exists": False},
            "attachment_claim": {"$exists": False},
            "expires_at": {"$lte": expiry_cutoff},
        })
        if not doc:
            continue
        try:
            await _finish_media_deletion(db, doc, token)
            removed += 1
        except Exception as exc:  # noqa: BLE001
            logger.exception("Expired inquiry attachment could not be deleted: %s", exc)
    return removed


async def mark_repair_attachments_linked(
    attachment_ids: list[ObjectId], request_id: str, inquiry_id: ObjectId,
    claim_token: str,
) -> None:
    if not attachment_ids:
        return
    result = await get_db().media.update_many(
        {
            "_id": {"$in": attachment_ids},
            "kind": "repair_attachment",
            "draft_request_id": request_id,
            "attachment_claim": claim_token,
            "linked_at": {"$exists": False},
            "attachment_deleting": {"$exists": False},
        },
        {
            "$set": {"linked_at": now_utc(), "inquiry_id": inquiry_id},
            "$unset": {
                "expires_at": "",
                "draft_request_id": "",
                "draft_slot": "",
                "attachment_claim": "",
                "attachment_claimed_at": "",
                "attachment_deleting": "",
                "attachment_deleting_at": "",
            },
        },
    )
    if result.matched_count != len(attachment_ids):
        raise RuntimeError("Nicht alle Anfrage-Anhänge konnten verknüpft werden")


async def release_repair_attachment_claims(
    attachment_ids: list[ObjectId], claim_token: str,
) -> None:
    if not attachment_ids:
        return
    await get_db().media.update_many(
        {
            "_id": {"$in": attachment_ids},
            "attachment_claim": claim_token,
            "linked_at": {"$exists": False},
        },
        {"$unset": {"attachment_claim": "", "attachment_claimed_at": ""}},
    )


async def recover_repair_attachment_claims(
    limit: int = 500, *, claimed_before=None,
) -> int:
    """Finish crash-interrupted claims or release claims without an inquiry."""
    db = get_db()
    cutoff = claimed_before or (now_utc() - timedelta(minutes=15))
    docs = await db.media.find({
        "kind": "repair_attachment",
        "linked_at": {"$exists": False},
        "attachment_claim": {"$exists": True},
        "$or": [
            {"attachment_claimed_at": {"$lte": cutoff}},
            {"attachment_claimed_at": {"$exists": False}},
        ],
    }).limit(limit).to_list(limit)
    processed = 0
    for doc in docs:
        request_id = doc.get("draft_request_id")
        inquiry = await db.repair_requests.find_one({"request_id": request_id})
        attachment_ids = set(inquiry.get("attachment_ids") or []) if inquiry else set()
        if inquiry and str(doc["_id"]) in attachment_ids:
            result = await db.media.update_one(
                {
                    "_id": doc["_id"],
                    "attachment_claim": doc.get("attachment_claim"),
                    "linked_at": {"$exists": False},
                    "attachment_deleting": {"$exists": False},
                },
                {
                    "$set": {"linked_at": now_utc(), "inquiry_id": inquiry["_id"]},
                    "$unset": {
                        "expires_at": "",
                        "draft_request_id": "",
                        "draft_slot": "",
                        "attachment_claim": "",
                        "attachment_claimed_at": "",
                        "attachment_deleting": "",
                        "attachment_deleting_at": "",
                    },
                },
            )
            processed += result.modified_count
        else:
            # This runs during startup before requests are accepted, so an
            # unmatched claim can only be residue from an interrupted process.
            result = await db.media.update_one(
                {
                    "_id": doc["_id"],
                    "attachment_claim": doc.get("attachment_claim"),
                    "linked_at": {"$exists": False},
                },
                {"$unset": {"attachment_claim": "", "attachment_claimed_at": ""}},
            )
            processed += result.modified_count
    return processed


async def delete_repair_attachments(attachments: list[dict]) -> int:
    object_ids = [
        ObjectId(str(item.get("id")))
        for item in attachments
        if ObjectId.is_valid(str(item.get("id", "")))
    ]
    if not object_ids:
        return 0
    db = get_db()
    deleted = 0
    for object_id in object_ids:
        token, doc = await _claim_media_deletion(db, {
            "_id": object_id,
            "kind": "repair_attachment",
        })
        if not doc:
            existing = await db.media.find_one({"_id": object_id})
            if existing:
                raise RuntimeError("Anfrage-Anhang wird bereits gelöscht")
            continue
        await _finish_media_deletion(db, doc, token)
        deleted += 1
    return deleted


async def _store_image(file: UploadFile, optimize: bool = True) -> dict:
    if file.content_type not in ALLOWED:
        raise HTTPException(status_code=400, detail="Nur Bilddateien (JPG, PNG, WEBP, GIF) erlaubt")
    raw = await file.read(MAX_BYTES + 1)
    if len(raw) > MAX_BYTES:
        raise HTTPException(status_code=400, detail="Datei zu groß (max. 8 MB)")
    if not raw:
        raise HTTPException(status_code=400, detail="Die Bilddatei ist leer")
    ext = "webp"
    fid = uuid.uuid4().hex
    fname = f"{fid}.{ext}"
    path = os.path.join(UPLOAD_DIR, fname)
    try:
        with Image.open(io.BytesIO(raw)) as source:
            if source.width * source.height > MAX_PIXELS:
                raise HTTPException(
                    status_code=400,
                    detail="Bildauflösung ist zu groß (max. 25 Megapixel)",
                )
            source.seek(0)
            source.load()
            img = ImageOps.exif_transpose(source)
            img = img.convert("RGBA") if img.mode in ("P", "LA", "RGBA") else img.convert("RGB")
        max_dim = 1600
        if max(img.size) > max_dim:
            img.thumbnail((max_dim, max_dim))
        save_kwargs = {"quality": 82, "method": 6} if optimize else {}
        img.save(path, "WEBP", **save_kwargs)
    except HTTPException:
        _remove_upload(fname)
        raise
    except Exception:
        _remove_upload(fname)
        raise HTTPException(status_code=400, detail="Bild konnte nicht verarbeitet werden")
    return {"filename": fname, "size": os.path.getsize(path)}


@router.post("/admin/media")
async def upload_media(file: UploadFile = File(...), alt: str = Form(""),
                       _: dict = Depends(require_admin)):
    stored = await _store_image(file)
    db = get_db()
    doc = {"filename": stored["filename"], "alt": alt, "size": stored["size"],
           "url": f"/api/media/{stored['filename']}", "created_at": now_utc()}
    res = await db.media.insert_one(doc)
    doc["_id"] = res.inserted_id
    return serialize(doc)


@router.get("/admin/media")
async def list_media(_: dict = Depends(require_admin)):
    docs = await get_db().media.find().sort("created_at", -1).to_list(500)
    return [serialize(d) for d in docs]


@router.put("/admin/media/{media_id}")
async def update_alt(media_id: str, alt: str = Form(""), _: dict = Depends(require_admin)):
    await get_db().media.update_one({"_id": ObjectId(media_id)}, {"$set": {"alt": alt}})
    return {"ok": True}


@router.delete("/admin/media/{media_id}")
async def delete_media(media_id: str, _: dict = Depends(require_admin)):
    db = get_db()
    if not ObjectId.is_valid(media_id):
        raise HTTPException(status_code=400, detail="Ungültige Medien-ID")
    token, doc = await _claim_media_deletion(db, {"_id": ObjectId(media_id)})
    if doc:
        try:
            await _finish_media_deletion(db, doc, token)
        except Exception as exc:  # noqa: BLE001
            logger.exception("Admin media deletion failed: %s", exc)
            raise HTTPException(
                status_code=500,
                detail="Medium konnte nicht gelöscht werden; bitte erneut versuchen",
            ) from None
    return {"ok": True}


# ---- repair attachments (public upload, size/type limited) ----
@router.post("/uploads/repair-attachment")
async def upload_repair_attachment(
    file: UploadFile = File(...),
    request_id: str = Form(..., min_length=8, max_length=128),
):
    request_id = request_id.strip()
    if not REQUEST_ID_PATTERN.fullmatch(request_id):
        raise HTTPException(status_code=400, detail="Ungültige Anfrage-ID")
    await cleanup_expired_repair_attachments()
    db = get_db()
    draft_filter = {
        "kind": "repair_attachment",
        "draft_request_id": request_id,
        "linked_at": {"$exists": False},
        "expires_at": {"$gt": now_utc()},
    }
    draft_count = await db.media.count_documents(draft_filter)
    if draft_count >= MAX_DRAFT_ATTACHMENTS:
        raise HTTPException(status_code=400, detail="Maximal 5 Fotos pro Anfrage erlaubt")
    stored = await _store_image(file)
    timestamp = now_utc()
    base_doc = {
        "filename": stored["filename"],
        "url": f"/api/media/{stored['filename']}",
        "kind": "repair_attachment",
        "draft_request_id": request_id,
        "created_at": timestamp,
        "expires_at": timestamp + ATTACHMENT_TTL,
    }
    try:
        res = None
        doc = None
        # The unique (request_id, slot) index makes the five-item quota safe
        # even when uploads arrive concurrently from multiple tabs.
        for slot in range(MAX_DRAFT_ATTACHMENTS):
            candidate = {**base_doc, "draft_slot": slot}
            try:
                res = await db.media.insert_one(candidate)
                doc = candidate
                break
            except DuplicateKeyError:
                continue
        if res is None or doc is None:
            raise HTTPException(status_code=400, detail="Maximal 5 Fotos pro Anfrage erlaubt")
    except Exception:
        _remove_upload(stored["filename"])
        raise
    return {"id": str(res.inserted_id), "url": doc["url"]}


@router.delete("/uploads/repair-attachment/{media_id}")
async def delete_repair_attachment(media_id: str, request_id: str):
    request_id = request_id.strip()
    if not REQUEST_ID_PATTERN.fullmatch(request_id) or not ObjectId.is_valid(media_id):
        raise HTTPException(status_code=400, detail="Ungültiger Foto-Entwurf")
    db = get_db()
    token, doc = await _claim_media_deletion(db, {
        "_id": ObjectId(media_id),
        "kind": "repair_attachment",
        "draft_request_id": request_id,
        "linked_at": {"$exists": False},
        "attachment_claim": {"$exists": False},
    })
    if not doc:
        raise HTTPException(status_code=404, detail="Foto-Entwurf nicht gefunden")
    try:
        await _finish_media_deletion(db, doc, token)
    except Exception as exc:  # noqa: BLE001
        logger.exception("Public inquiry attachment deletion failed: %s", exc)
        raise HTTPException(
            status_code=500,
            detail="Foto konnte nicht gelöscht werden; bitte erneut versuchen",
        ) from None
    return {"ok": True}


@router.get("/media/{filename}")
async def serve_media(filename: str):
    # prevent path traversal
    safe = os.path.basename(filename)
    if safe != filename:
        raise HTTPException(status_code=404, detail="Datei nicht gefunden")
    db = get_db()
    doc = await db.media.find_one({"filename": safe})
    if not doc or doc.get("attachment_deleting"):
        raise HTTPException(status_code=404, detail="Datei nicht gefunden")
    expires_at = doc.get("expires_at")
    if (
        doc.get("kind") == "repair_attachment"
        and not doc.get("linked_at")
        and not doc.get("attachment_claim")
        and expires_at
        and expires_at <= now_utc()
    ):
        await cleanup_expired_repair_attachments(limit=100)
        raise HTTPException(status_code=404, detail="Datei nicht gefunden")
    path = os.path.join(UPLOAD_DIR, safe)
    if not os.path.exists(path):
        raise HTTPException(status_code=404, detail="Datei nicht gefunden")
    return FileResponse(path, media_type="image/webp")

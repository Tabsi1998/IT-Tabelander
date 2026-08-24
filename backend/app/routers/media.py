import io
import os
import uuid

from bson import ObjectId
from fastapi import (APIRouter, Depends, File, Form, HTTPException, UploadFile)
from fastapi.responses import FileResponse
from PIL import Image

from ..db import get_db, now_utc, serialize
from ..security import get_current_user, require_admin

router = APIRouter(prefix="/api", tags=["media"])

UPLOAD_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "uploads")
os.makedirs(UPLOAD_DIR, exist_ok=True)

ALLOWED = {"image/jpeg", "image/png", "image/webp", "image/gif"}
MAX_BYTES = 8 * 1024 * 1024  # 8 MB


async def _store_image(file: UploadFile, optimize: bool = True) -> dict:
    if file.content_type not in ALLOWED:
        raise HTTPException(status_code=400, detail="Nur Bilddateien (JPG, PNG, WEBP, GIF) erlaubt")
    raw = await file.read()
    if len(raw) > MAX_BYTES:
        raise HTTPException(status_code=400, detail="Datei zu groß (max. 8 MB)")
    ext = "webp"
    fid = uuid.uuid4().hex
    fname = f"{fid}.{ext}"
    path = os.path.join(UPLOAD_DIR, fname)
    try:
        img = Image.open(io.BytesIO(raw))
        img = img.convert("RGBA") if img.mode in ("P", "LA") else img.convert("RGB") if img.mode != "RGBA" else img
        max_dim = 1600
        if max(img.size) > max_dim:
            img.thumbnail((max_dim, max_dim))
        save_kwargs = {"quality": 82, "method": 6} if optimize else {}
        img.save(path, "WEBP", **save_kwargs)
    except HTTPException:
        raise
    except Exception:
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
    doc = await db.media.find_one({"_id": ObjectId(media_id)})
    if doc:
        path = os.path.join(UPLOAD_DIR, doc["filename"])
        if os.path.exists(path):
            os.remove(path)
        await db.media.delete_one({"_id": ObjectId(media_id)})
    return {"ok": True}


# ---- repair attachments (public upload, size/type limited) ----
@router.post("/uploads/repair-attachment")
async def upload_repair_attachment(file: UploadFile = File(...)):
    stored = await _store_image(file)
    db = get_db()
    doc = {"filename": stored["filename"], "url": f"/api/media/{stored['filename']}",
           "kind": "repair_attachment", "created_at": now_utc()}
    res = await db.media.insert_one(doc)
    return {"id": str(res.inserted_id), "url": doc["url"]}


@router.get("/media/{filename}")
async def serve_media(filename: str):
    # prevent path traversal
    safe = os.path.basename(filename)
    path = os.path.join(UPLOAD_DIR, safe)
    if not os.path.exists(path):
        raise HTTPException(status_code=404, detail="Datei nicht gefunden")
    return FileResponse(path, media_type="image/webp")

"""Photos of finished work for "Aus der Werkstatt" on the website (#50).

The website shows the newest ones and hides the section while there are none.
Uploading and sorting them comes with the new admin (#60).
"""
import logging
import os

from bson import ObjectId
from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile
from fastapi.concurrency import run_in_threadpool
from PIL import Image

from ..db import get_db, now_utc, serialize, to_oid
from ..models import GalleryUpdate, OrderInput
from ..security import require_admin
from . import media

router = APIRouter(prefix="/api", tags=["gallery"])
logger = logging.getLogger("it-tabelander.gallery")
THUMB_SIZE = 720  # two columns on a phone, four on a desktop

CATEGORIES = {
    "pc_build": "PC-Bau",
    "repair": "Reparatur",
    "upgrade": "Upgrade",
    "console": "Konsole",
    "controller": "Controller",
    "other": "Sonstiges",
}


@router.get("/gallery")
async def public_gallery():
    docs = await get_db().gallery.find({"visible": {"$ne": False}}).sort(
        [("sort", 1), ("created_at", -1)]).to_list(200)
    items = []
    for doc in docs:
        if not doc.get("image_url"):
            continue
        category = doc.get("category") if doc.get("category") in CATEGORIES else "other"
        items.append({
            "id": str(doc["_id"]),
            "image_url": doc["image_url"],
            "thumb_url": doc.get("thumb_url") or doc["image_url"],
            "caption": str(doc.get("caption") or ""),
            "category": category,
            "category_label": CATEGORIES[category],
        })
    return {"items": items}


def _thumbnail(filename: str) -> dict:
    """A smaller copy for the grid; the full photo opens on a click."""
    source_path = os.path.join(media.UPLOAD_DIR, filename)
    thumb = filename.rsplit(".", 1)[0] + "-thumb.webp"
    with Image.open(source_path) as image:
        image.thumbnail((THUMB_SIZE, THUMB_SIZE))
        image.save(os.path.join(media.UPLOAD_DIR, thumb), "WEBP", quality=80, method=6)
    return {"filename": thumb, "size": os.path.getsize(os.path.join(media.UPLOAD_DIR, thumb))}


async def _media_doc(db, stored: dict, kind: str) -> dict:
    doc = {"filename": stored["filename"], "size": stored["size"], "kind": kind,
           "url": f"/api/media/{stored['filename']}", "created_at": now_utc()}
    doc["_id"] = (await db.media.insert_one(doc)).inserted_id
    return doc


def _admin_item(doc: dict) -> dict:
    item = serialize(doc)
    item["category_label"] = CATEGORIES.get(doc.get("category"), CATEGORIES["other"])
    return item


@router.get("/admin/gallery")
async def admin_gallery(_: dict = Depends(require_admin)):
    docs = await get_db().gallery.find().sort([("sort", 1), ("created_at", -1)]).to_list(500)
    return {"items": [_admin_item(doc) for doc in docs], "categories": CATEGORIES}


@router.post("/admin/gallery")
async def add_photo(file: UploadFile = File(...), caption: str = Form(""), category: str = Form("other"),
                    visible: bool = Form(True), _: dict = Depends(require_admin)):
    """A photo of finished work (#60), also straight from the phone camera."""
    if category not in CATEGORIES:
        raise HTTPException(status_code=400, detail="Unbekannter Bereich")
    if len(caption.strip()) > 160:
        raise HTTPException(status_code=400, detail="Der Bildtext darf höchstens 160 Zeichen lang sein")
    db = get_db()
    stored = await media._store_image(file)
    try:
        thumb = await run_in_threadpool(_thumbnail, stored["filename"])
    except Exception:
        media._remove_upload(stored["filename"])
        raise HTTPException(status_code=400, detail="Bild konnte nicht verarbeitet werden") from None
    full = await _media_doc(db, stored, "gallery")
    small = await _media_doc(db, thumb, "gallery")
    first = await db.gallery.find_one(sort=[("sort", 1)])
    doc = {
        "image_url": full["url"], "thumb_url": small["url"], "media_ids": [full["_id"], small["_id"]],
        "caption": caption.strip(), "category": category, "visible": bool(visible),
        # New photos come first; the admin can move them.
        "sort": (first.get("sort", 0) - 1) if first else 0,
        "created_at": now_utc(), "updated_at": now_utc(),
    }
    doc["_id"] = (await db.gallery.insert_one(doc)).inserted_id
    return _admin_item(doc)


@router.put("/admin/gallery/{item_id}")
async def update_photo(item_id: str, payload: GalleryUpdate, _: dict = Depends(require_admin)):
    db = get_db()
    oid = to_oid(item_id)
    changes = payload.model_dump(exclude_none=True)
    changes["updated_at"] = now_utc()
    result = await db.gallery.update_one({"_id": oid}, {"$set": changes})
    if not result.matched_count:
        raise HTTPException(status_code=404, detail="Foto nicht gefunden")
    return _admin_item(await db.gallery.find_one({"_id": oid}))


@router.post("/admin/gallery/order")
async def order_photos(payload: OrderInput, _: dict = Depends(require_admin)):
    db = get_db()
    for index, item_id in enumerate(payload.ids):
        await db.gallery.update_one({"_id": to_oid(item_id)}, {"$set": {"sort": index}})
    return {"ok": True}


@router.delete("/admin/gallery/{item_id}")
async def delete_photo(item_id: str, _: dict = Depends(require_admin)):
    """The photo leaves the website and the disk."""
    db = get_db()
    oid = to_oid(item_id)
    doc = await db.gallery.find_one({"_id": oid})
    if not doc:
        raise HTTPException(status_code=404, detail="Foto nicht gefunden")
    for media_id in doc.get("media_ids") or []:
        token, media_doc = await media._claim_media_deletion(db, {"_id": ObjectId(str(media_id))})
        if media_doc:
            try:
                await media._finish_media_deletion(db, media_doc, token)
            except Exception as exc:  # noqa: BLE001
                logger.exception("Gallery photo deletion failed: %s", exc)
                raise HTTPException(status_code=500, detail="Foto konnte nicht gelöscht werden; bitte erneut versuchen") from None
    await db.gallery.delete_one({"_id": oid})
    return {"ok": True}

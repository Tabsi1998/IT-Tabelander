"""Photos of finished work for "Aus der Werkstatt" on the website (#50).

The website shows the newest ones and hides the section while there are none.
Uploading and sorting them comes with the new admin (#60).
"""
from fastapi import APIRouter

from ..db import get_db

router = APIRouter(prefix="/api", tags=["gallery"])

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

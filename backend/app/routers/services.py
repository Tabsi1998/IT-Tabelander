import re

from fastapi import APIRouter, Depends, HTTPException

from ..db import get_db, now_utc, serialize, to_oid
from ..models import ServiceInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["services"])


def _slugify(text: str) -> str:
    text = text.lower().strip()
    text = text.replace("ä", "ae").replace("ö", "oe").replace("ü", "ue").replace("ß", "ss")
    text = re.sub(r"[^a-z0-9]+", "-", text).strip("-")
    return text or "service"


@router.get("/services")
async def list_services(include_inactive: bool = False):
    q = {} if include_inactive else {"active": True}
    docs = await get_db().services.find(q).sort("sort", 1).to_list(200)
    return [serialize(d) for d in docs]


@router.get("/services/{slug}")
async def get_service(slug: str):
    doc = await get_db().services.find_one({"slug": slug})
    if not doc:
        raise HTTPException(status_code=404, detail="Leistung nicht gefunden")
    return serialize(doc)


@router.post("/admin/services")
async def create_service(payload: ServiceInput, _: dict = Depends(require_admin)):
    db = get_db()
    data = payload.model_dump()
    data["slug"] = data.get("slug") or _slugify(data["title"])
    if await db.services.find_one({"slug": data["slug"]}):
        raise HTTPException(status_code=400, detail="Slug bereits vergeben")
    data["created_at"] = now_utc()
    data["updated_at"] = now_utc()
    res = await db.services.insert_one(data)
    data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/services/{service_id}")
async def update_service(service_id: str, payload: ServiceInput, _: dict = Depends(require_admin)):
    db = get_db()
    oid = to_oid(service_id)
    data = payload.model_dump()
    data["slug"] = data.get("slug") or _slugify(data["title"])
    data["updated_at"] = now_utc()
    res = await db.services.update_one({"_id": oid}, {"$set": data})
    if res.matched_count == 0:
        raise HTTPException(status_code=404, detail="Leistung nicht gefunden")
    doc = await db.services.find_one({"_id": oid})
    return serialize(doc)


@router.delete("/admin/services/{service_id}")
async def delete_service(service_id: str, _: dict = Depends(require_admin)):
    res = await get_db().services.delete_one({"_id": to_oid(service_id)})
    if res.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Leistung nicht gefunden")
    return {"ok": True}

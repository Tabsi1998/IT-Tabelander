from fastapi import APIRouter, Depends, HTTPException

from ..db import get_db, now_utc, serialize, to_oid
from ..models import FAQInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["faqs"])


@router.get("/faqs")
async def list_faqs(category: str | None = None, include_inactive: bool = False):
    q = {} if include_inactive else {"active": True}
    if category:
        q["category"] = category
    docs = await get_db().faqs.find(q).sort("sort", 1).to_list(200)
    return [serialize(d) for d in docs]


@router.post("/admin/faqs")
async def create_faq(payload: FAQInput, _: dict = Depends(require_admin)):
    data = payload.model_dump()
    data["created_at"] = now_utc()
    res = await get_db().faqs.insert_one(data)
    data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/faqs/{faq_id}")
async def update_faq(faq_id: str, payload: FAQInput, _: dict = Depends(require_admin)):
    db = get_db()
    oid = to_oid(faq_id)
    res = await db.faqs.update_one({"_id": oid}, {"$set": payload.model_dump()})
    if res.matched_count == 0:
        raise HTTPException(status_code=404, detail="FAQ nicht gefunden")
    return serialize(await db.faqs.find_one({"_id": oid}))


@router.delete("/admin/faqs/{faq_id}")
async def delete_faq(faq_id: str, _: dict = Depends(require_admin)):
    res = await get_db().faqs.delete_one({"_id": to_oid(faq_id)})
    if res.deleted_count == 0:
        raise HTTPException(status_code=404, detail="FAQ nicht gefunden")
    return {"ok": True}

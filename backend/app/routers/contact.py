from bson import ObjectId
from fastapi import APIRouter, Depends

from ..db import get_db, serialize
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["contact"])

@router.get("/admin/contact")
async def list_contact(_: dict = Depends(require_admin)):
    docs = await get_db().contact_messages.find().sort("created_at", -1).to_list(500)
    return [serialize(d) for d in docs]


@router.patch("/admin/contact/{msg_id}/read")
async def mark_read(msg_id: str, _: dict = Depends(require_admin)):
    await get_db().contact_messages.update_one({"_id": ObjectId(msg_id)},
                                               {"$set": {"status": "gelesen"}})
    return {"ok": True}


@router.delete("/admin/contact/{msg_id}")
async def delete_contact(msg_id: str, _: dict = Depends(require_admin)):
    await get_db().contact_messages.delete_one({"_id": ObjectId(msg_id)})
    return {"ok": True}

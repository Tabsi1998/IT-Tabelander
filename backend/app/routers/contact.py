from bson import ObjectId
from fastapi import APIRouter, Depends, HTTPException

from ..db import get_db, now_utc, serialize
from ..models import ContactInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["contact"])


@router.post("/contact")
async def create_contact(payload: ContactInput):
    if payload.honeypot:
        raise HTTPException(status_code=400, detail="Ungültige Anfrage")
    if not payload.consent:
        raise HTTPException(status_code=400, detail="Zustimmung zum Datenschutz erforderlich")
    db = get_db()
    data = payload.model_dump()
    data.pop("honeypot", None)
    data["status"] = "neu"
    data["created_at"] = now_utc()
    res = await db.contact_messages.insert_one(data)
    from .. import dolibarr
    dol = await dolibarr.create_lead({
        "subject": f"Kontaktanfrage: {payload.subject or 'Website'}",
        "message": payload.message,
        "contact": {"name": payload.name, "email": payload.email, "phone": payload.phone},
    }, kind="contact")
    await db.contact_messages.update_one({"_id": res.inserted_id}, {"$set": {"dolibarr": dol}})
    return {"ok": True, "id": str(res.inserted_id)}


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

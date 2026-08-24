import random
import string

from bson import ObjectId
from fastapi import APIRouter, Depends, HTTPException

from .. import dolibarr
from ..db import get_db, now_utc, serialize
from ..models import RepairInput, RepairStatusUpdate
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["repairs"])

STATUSES = ["eingegangen", "in_diagnose", "warten_auf_teile", "in_reparatur", "fertig", "abgeschlossen", "abgelehnt"]


def _ticket_ref() -> str:
    return "REP-" + "".join(random.choices(string.ascii_uppercase + string.digits, k=6))


@router.post("/repairs")
async def create_repair(payload: RepairInput):
    if payload.honeypot:
        raise HTTPException(status_code=400, detail="Ungültige Anfrage")
    if not payload.consent:
        raise HTTPException(status_code=400, detail="Zustimmung zum Datenschutz erforderlich")
    db = get_db()
    data = payload.model_dump()
    data.pop("honeypot", None)
    data["ref"] = _ticket_ref()
    data["status"] = "eingegangen"
    data["dolibarr"] = {"synced": False}
    data["created_at"] = now_utc()
    res = await db.repair_requests.insert_one(data)
    data["_id"] = res.inserted_id
    # best-effort Dolibarr ticket
    dol = await dolibarr.create_ticket_for_repair(data)
    await db.repair_requests.update_one({"_id": res.inserted_id},
                                        {"$set": {"dolibarr": dol}})
    return {"ok": True, "ref": data["ref"], "id": str(res.inserted_id)}


@router.get("/admin/repairs")
async def list_repairs(status: str | None = None, _: dict = Depends(require_admin)):
    q = {"status": status} if status else {}
    docs = await get_db().repair_requests.find(q).sort("created_at", -1).to_list(500)
    return [serialize(d) for d in docs]


@router.get("/admin/repairs/{repair_id}")
async def get_repair(repair_id: str, _: dict = Depends(require_admin)):
    doc = await get_db().repair_requests.find_one({"_id": ObjectId(repair_id)})
    if not doc:
        raise HTTPException(status_code=404, detail="Anfrage nicht gefunden")
    return serialize(doc)


@router.patch("/admin/repairs/{repair_id}/status")
async def update_status(repair_id: str, payload: RepairStatusUpdate, _: dict = Depends(require_admin)):
    if payload.status not in STATUSES:
        raise HTTPException(status_code=400, detail="Ungültiger Status")
    await get_db().repair_requests.update_one(
        {"_id": ObjectId(repair_id)},
        {"$set": {"status": payload.status, "updated_at": now_utc()}})
    return {"ok": True}


@router.delete("/admin/repairs/{repair_id}")
async def delete_repair(repair_id: str, _: dict = Depends(require_admin)):
    await get_db().repair_requests.delete_one({"_id": ObjectId(repair_id)})
    return {"ok": True}

from fastapi import APIRouter, Depends

from .. import dolibarr
from ..db import get_db, serialize
from ..security import require_admin

router = APIRouter(prefix="/api/admin", tags=["dashboard"])


@router.get("/dashboard")
async def dashboard(_: dict = Depends(require_admin)):
    db = get_db()
    new_repairs = await db.repair_requests.count_documents({"status": "eingegangen"})
    total_repairs = await db.repair_requests.count_documents({})
    recent = await db.repair_requests.find().sort("created_at", -1).to_list(5)
    contact_new = await db.contact_messages.count_documents({"status": "neu"})
    active_services = await db.services.count_documents({"active": True})
    ps5_options = await db.config_options.count_documents({"configurator": "ps5", "active": True})
    pc_options = await db.config_options.count_documents({"configurator": "pc", "active": True})
    reviews_visible = await db.reviews.count_documents({"visible": True})
    last_sync = await db.sync_logs.find_one({"type": "product_sync"}, sort=[("finished_at", -1)])
    return {
        "new_repairs": new_repairs,
        "total_repairs": total_repairs,
        "recent_repairs": [serialize(r) for r in recent],
        "contact_new": contact_new,
        "active_services": active_services,
        "ps5_options": ps5_options,
        "pc_options": pc_options,
        "reviews_visible": reviews_visible,
        "dolibarr_enabled": dolibarr.is_enabled(),
        "last_sync": serialize(last_sync) if last_sync else None,
    }

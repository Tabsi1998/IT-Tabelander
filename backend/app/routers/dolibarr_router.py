from fastapi import APIRouter, Depends

from .. import dolibarr
from ..db import get_db, serialize
from ..security import require_admin

router = APIRouter(prefix="/api/admin/dolibarr", tags=["dolibarr"])


@router.get("/status")
async def status(_: dict = Depends(require_admin)):
    """Show the health of the inquiry integration without exposing secrets."""
    db = get_db()
    total = await db.repair_requests.count_documents({})
    synced = await db.repair_requests.count_documents({"dolibarr.synced": True})
    failed = await db.repair_requests.count_documents({"dolibarr.error": {"$type": "object"}})
    latest = await db.repair_requests.find_one(
        {"dolibarr.attempted_at": {"$exists": True}},
        sort=[("dolibarr.attempted_at", -1)],
    )
    latest_activity = None
    if latest:
        latest_activity = {
            "id": str(latest["_id"]),
            "ref": latest.get("ref"),
            "dolibarr": serialize(latest.get("dolibarr") or {}),
        }
    return {
        "enabled": await dolibarr.is_enabled(),
        "connection": await dolibarr.test_connection(),
        "inquiries": {
            "total": total,
            "synced": synced,
            "pending": max(total - synced, 0),
            "failed": failed,
        },
        "latest_activity": latest_activity,
    }

from fastapi import APIRouter, Depends, HTTPException

from .. import dolibarr, handover
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


@router.get("/queue")
async def queue(_: dict = Depends(require_admin)):
    """Inquiries on their way to Dolibarr: how many, since when, why (#39)."""
    return await handover.queue_overview()


@router.post("/queue/run")
async def run_queue(_: dict = Depends(require_admin)):
    """ "Jetzt erneut versuchen": every waiting inquiry, without the pause."""
    result = await handover.process_queue(force=True, limit=50)
    result["cleaned"] = await handover.clean_up_handed_over()
    result["warned"] = await handover.warn_about_waiting()
    return result


@router.get("/migration")
async def migration_dry_run(_: dict = Depends(require_admin)):
    """The dry run of the one-time move of old records (#41); changes nothing."""
    return await handover.migration_plan()


@router.post("/migration")
async def migration_run(admin: dict = Depends(require_admin)):
    """Hand old records over without confirmation mails, then forget them here."""
    if admin.get("role") != "super_admin":
        raise HTTPException(status_code=403, detail="Nur Super-Admins dürfen Altdaten übergeben.")
    try:
        return await handover.run_migration()
    except RuntimeError as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from None

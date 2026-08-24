from bson import ObjectId
from fastapi import APIRouter, Depends

from .. import dolibarr
from ..db import get_db, serialize
from ..security import require_admin

router = APIRouter(prefix="/api/admin/dolibarr", tags=["dolibarr"])


@router.get("/status")
async def status(_: dict = Depends(require_admin)):
    db = get_db()
    last = await db.sync_logs.find_one({"type": "product_sync"}, sort=[("finished_at", -1)])
    conn = await dolibarr.test_connection()
    product_count = await db.dolibarr_product_cache.count_documents({})
    return {
        "enabled": await dolibarr.is_enabled(),
        "connection": conn,
        "last_sync": serialize(last) if last else None,
        "cached_products": product_count,
    }


@router.post("/sync")
async def sync(_: dict = Depends(require_admin)):
    log = await dolibarr.sync_products()
    return serialize(log)


@router.get("/products")
async def products(_: dict = Depends(require_admin)):
    docs = await get_db().dolibarr_product_cache.find().sort("ref", 1).to_list(500)
    return [serialize(d) for d in docs]


@router.get("/logs")
async def logs(_: dict = Depends(require_admin)):
    docs = await get_db().sync_logs.find().sort("finished_at", -1).to_list(50)
    return [serialize(d) for d in docs]

import secrets

from fastapi import APIRouter, Depends, HTTPException

from ..db import get_db, now_utc, serialize, to_oid
from ..models import (BuilderCategoryInput, BuilderConfigInput,
                      BuilderProductInput, ControllerInput)
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["builder"])


async def _dolibarr_price(pid):
    if not pid:
        return None
    cache = await get_db().dolibarr_product_cache.find_one({"dolibarr_product_id": str(pid)})
    if cache:
        return cache.get("price_ttc") or cache.get("price")
    return None


@router.get("/builder/controllers")
async def list_controllers():
    docs = await get_db().controllers.find({"active": True}).sort("sort", 1).to_list(50)
    return [serialize(d) for d in docs]


@router.get("/builder/{controller_key}")
async def get_builder(controller_key: str, version: str | None = None):
    db = get_db()
    ctrl = await db.controllers.find_one({"key": controller_key, "active": True})
    if not ctrl:
        raise HTTPException(status_code=404, detail="Controller nicht gefunden")
    cats = await db.builder_categories.find({"controller_key": controller_key, "active": True}).sort("sort", 1).to_list(100)
    prods = await db.builder_products.find({"controller_key": controller_key, "active": True}).sort("sort", 1).to_list(500)
    # attach variants + filter by version compatibility
    out_cats = []
    for c in cats:
        c = serialize(c)
        c_prods = []
        for p in prods:
            if p.get("category_key") != c["key"]:
                continue
            comp = p.get("compatible_versions") or []
            if version and comp and version not in comp:
                continue
            p2 = serialize(dict(p))
            dol = await _dolibarr_price(p.get("dolibarr_product_id"))
            for v in p2.get("variants", []):
                if dol is not None:
                    v["price"] = dol
            c_prods.append(p2)
        c["products"] = c_prods
        out_cats.append(c)
    return {"controller": serialize(ctrl), "categories": out_cats}


@router.post("/builder/save")
async def save_config(payload: BuilderConfigInput):
    db = get_db()
    cid = secrets.token_urlsafe(8)
    doc = payload.model_dump()
    doc["config_id"] = cid
    doc["kind"] = "controller"
    doc["created_at"] = now_utc()
    await db.builder_configs.insert_one(doc)
    # optional Dolibarr lead
    if payload.contact:
        from .. import dolibarr
        summary = ", ".join(f"{k}: {v.get('name','')}" for k, v in (payload.selections or {}).items())
        await dolibarr.create_lead({
            "subject": f"Controller-Konfiguration {payload.controller_key} ({cid})",
            "message": f"Konfiguration: {summary}\nGesamt: {payload.total} EUR\n{payload.note or ''}",
            "contact": payload.contact.model_dump(),
        }, kind="controller_config")
    return {"ok": True, "config_id": cid}


@router.get("/builder/config/{config_id}")
async def get_saved(config_id: str):
    doc = await get_db().builder_configs.find_one({"config_id": config_id})
    if not doc:
        raise HTTPException(status_code=404, detail="Konfiguration nicht gefunden")
    return serialize(doc)


# ---------------- Admin: controllers ----------------
@router.get("/admin/builder/controllers")
async def admin_controllers(_: dict = Depends(require_admin)):
    docs = await get_db().controllers.find().sort("sort", 1).to_list(50)
    return [serialize(d) for d in docs]


@router.post("/admin/builder/controllers")
async def create_controller(p: ControllerInput, _: dict = Depends(require_admin)):
    data = p.model_dump(); data["created_at"] = now_utc()
    res = await get_db().controllers.insert_one(data); data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/builder/controllers/{cid}")
async def update_controller(cid: str, p: ControllerInput, _: dict = Depends(require_admin)):
    db = get_db(); oid = to_oid(cid)
    r = await db.controllers.update_one({"_id": oid}, {"$set": p.model_dump()})
    if r.matched_count == 0:
        raise HTTPException(status_code=404, detail="Nicht gefunden")
    return serialize(await db.controllers.find_one({"_id": oid}))


@router.delete("/admin/builder/controllers/{cid}")
async def delete_controller(cid: str, _: dict = Depends(require_admin)):
    await get_db().controllers.delete_one({"_id": to_oid(cid)})
    return {"ok": True}


# ---------------- Admin: categories ----------------
@router.get("/admin/builder/{controller_key}/categories")
async def admin_categories(controller_key: str, _: dict = Depends(require_admin)):
    docs = await get_db().builder_categories.find({"controller_key": controller_key}).sort("sort", 1).to_list(200)
    return [serialize(d) for d in docs]


@router.post("/admin/builder/categories")
async def create_category(p: BuilderCategoryInput, _: dict = Depends(require_admin)):
    data = p.model_dump(); data["created_at"] = now_utc()
    res = await get_db().builder_categories.insert_one(data); data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/builder/categories/{cid}")
async def update_category(cid: str, p: BuilderCategoryInput, _: dict = Depends(require_admin)):
    db = get_db(); oid = to_oid(cid)
    await db.builder_categories.update_one({"_id": oid}, {"$set": p.model_dump()})
    return serialize(await db.builder_categories.find_one({"_id": oid}))


@router.delete("/admin/builder/categories/{cid}")
async def delete_category(cid: str, _: dict = Depends(require_admin)):
    await get_db().builder_categories.delete_one({"_id": to_oid(cid)})
    return {"ok": True}


# ---------------- Admin: products (+variants embedded) ----------------
@router.get("/admin/builder/{controller_key}/products")
async def admin_products(controller_key: str, _: dict = Depends(require_admin)):
    docs = await get_db().builder_products.find({"controller_key": controller_key}).sort("sort", 1).to_list(500)
    return [serialize(d) for d in docs]


@router.post("/admin/builder/products")
async def create_product(p: BuilderProductInput, _: dict = Depends(require_admin)):
    data = p.model_dump(); data["created_at"] = now_utc()
    res = await get_db().builder_products.insert_one(data); data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/builder/products/{pid}")
async def update_product(pid: str, p: BuilderProductInput, _: dict = Depends(require_admin)):
    db = get_db(); oid = to_oid(pid)
    r = await db.builder_products.update_one({"_id": oid}, {"$set": p.model_dump()})
    if r.matched_count == 0:
        raise HTTPException(status_code=404, detail="Nicht gefunden")
    return serialize(await db.builder_products.find_one({"_id": oid}))


@router.delete("/admin/builder/products/{pid}")
async def delete_product(pid: str, _: dict = Depends(require_admin)):
    await get_db().builder_products.delete_one({"_id": to_oid(pid)})
    return {"ok": True}

import secrets

from fastapi import APIRouter, Depends, HTTPException

from ..db import get_db, now_utc, serialize, to_oid
from ..models import ConfigCategoryInput, ConfigOptionInput, SavedConfigInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["configurator"])


async def _merge_dolibarr_price(option: dict) -> dict:
    pid = option.get("dolibarr_product_id")
    if pid:
        cache = await get_db().dolibarr_product_cache.find_one({"dolibarr_product_id": str(pid)})
        if cache:
            option["price"] = cache.get("price_ttc") or cache.get("price") or option.get("price")
            option["sku"] = cache.get("ref") or option.get("sku")
            option["stock"] = cache.get("stock")
            option["from_dolibarr"] = True
    return option


@router.get("/configurator/{ctype}")
async def get_configurator(ctype: str):
    if ctype not in ("ps5", "pc"):
        raise HTTPException(status_code=404, detail="Unbekannter Konfigurator")
    db = get_db()
    cats = await db.config_categories.find({"configurator": ctype, "active": True}).sort("sort", 1).to_list(100)
    opts = await db.config_options.find({"configurator": ctype, "active": True}).sort("sort", 1).to_list(500)
    categories = []
    for c in cats:
        c = serialize(c)
        c_opts = []
        for o in opts:
            if o.get("category_key") == c["key"]:
                o = await _merge_dolibarr_price(dict(o))
                c_opts.append(serialize(o))
        c["options"] = c_opts
        categories.append(c)
    return {"configurator": ctype, "categories": categories}


@router.post("/configurator/save")
async def save_configuration(payload: SavedConfigInput):
    db = get_db()
    config_id = secrets.token_urlsafe(8)
    doc = payload.model_dump()
    doc["config_id"] = config_id
    doc["created_at"] = now_utc()
    await db.saved_configurations.insert_one(doc)
    return {"ok": True, "config_id": config_id}


@router.get("/configurator/saved/{config_id}")
async def get_saved(config_id: str):
    doc = await get_db().saved_configurations.find_one({"config_id": config_id})
    if not doc:
        raise HTTPException(status_code=404, detail="Konfiguration nicht gefunden")
    return serialize(doc)


# ---------------- Admin: categories ----------------
@router.get("/admin/configurator/{ctype}/categories")
async def admin_categories(ctype: str, _: dict = Depends(require_admin)):
    docs = await get_db().config_categories.find({"configurator": ctype}).sort("sort", 1).to_list(100)
    return [serialize(d) for d in docs]


@router.post("/admin/configurator/categories")
async def create_category(payload: ConfigCategoryInput, _: dict = Depends(require_admin)):
    data = payload.model_dump()
    data["created_at"] = now_utc()
    res = await get_db().config_categories.insert_one(data)
    data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/configurator/categories/{cat_id}")
async def update_category(cat_id: str, payload: ConfigCategoryInput, _: dict = Depends(require_admin)):
    db = get_db()
    await db.config_categories.update_one({"_id": to_oid(cat_id)}, {"$set": payload.model_dump()})
    return serialize(await db.config_categories.find_one({"_id": to_oid(cat_id)}))


@router.delete("/admin/configurator/categories/{cat_id}")
async def delete_category(cat_id: str, _: dict = Depends(require_admin)):
    db = get_db()
    cat = await db.config_categories.find_one({"_id": to_oid(cat_id)})
    if not cat:
        raise HTTPException(status_code=404, detail="Kategorie nicht gefunden")
    await db.config_categories.delete_one({"_id": to_oid(cat_id)})
    # cascade: remove options belonging to this category (no orphans)
    await db.config_options.delete_many({"configurator": cat.get("configurator"), "category_key": cat.get("key")})
    return {"ok": True}


# ---------------- Admin: options ----------------
@router.get("/admin/configurator/{ctype}/options")
async def admin_options(ctype: str, _: dict = Depends(require_admin)):
    docs = await get_db().config_options.find({"configurator": ctype}).sort("sort", 1).to_list(500)
    return [serialize(d) for d in docs]


@router.post("/admin/configurator/options")
async def create_option(payload: ConfigOptionInput, _: dict = Depends(require_admin)):
    data = payload.model_dump()
    data["created_at"] = now_utc()
    res = await get_db().config_options.insert_one(data)
    data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/configurator/options/{opt_id}")
async def update_option(opt_id: str, payload: ConfigOptionInput, _: dict = Depends(require_admin)):
    db = get_db()
    await db.config_options.update_one({"_id": to_oid(opt_id)}, {"$set": payload.model_dump()})
    return serialize(await db.config_options.find_one({"_id": to_oid(opt_id)}))


@router.delete("/admin/configurator/options/{opt_id}")
async def delete_option(opt_id: str, _: dict = Depends(require_admin)):
    await get_db().config_options.delete_one({"_id": to_oid(opt_id)})
    return {"ok": True}

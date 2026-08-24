from fastapi import APIRouter, Depends

from ..db import get_db, now_utc, serialize
from ..models import SettingsInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["settings"])

# fields safe to expose publicly (no secrets / API keys)
PUBLIC_FIELDS = [
    "company_name", "tagline", "email", "phone", "address", "city", "region",
    "postal_code", "country", "service_area", "opening_hours", "social_links",
    "ga_measurement_id", "seo_default_title", "seo_default_description",
    "impressum_html", "datenschutz_html", "legal_reviewed",
]


@router.get("/settings")
async def public_settings():
    doc = await get_db().settings.find_one({"_id": "site"}) or {}
    return {k: doc.get(k) for k in PUBLIC_FIELDS}


@router.get("/admin/settings")
async def admin_settings(_: dict = Depends(require_admin)):
    doc = await get_db().settings.find_one({"_id": "site"}) or {}
    doc.pop("_id", None)
    return serialize(doc)


@router.put("/admin/settings")
async def update_settings(payload: SettingsInput, _: dict = Depends(require_admin)):
    db = get_db()
    data = {k: v for k, v in payload.model_dump().items() if v is not None}
    data["updated_at"] = now_utc()
    await db.settings.update_one({"_id": "site"}, {"$set": data}, upsert=True)
    doc = await db.settings.find_one({"_id": "site"})
    doc.pop("_id", None)
    return serialize(doc)

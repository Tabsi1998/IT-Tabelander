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
    "canonical_base_url",
    "pc_builder_title", "pc_builder_subtitle", "pc_builder_note",
    "impressum_html", "datenschutz_html", "legal_reviewed",
    "logo_light_url", "logo_dark_url",
]

SECRET_FIELDS = ("dolibarr_api_key", "google_places_api_key")


def _admin_response(doc: dict) -> dict:
    result = dict(doc)
    result.pop("_id", None)
    for field in SECRET_FIELDS:
        result[f"{field}_configured"] = bool(result.pop(field, ""))
    return serialize(result)


@router.get("/settings")
async def public_settings():
    doc = await get_db().settings.find_one({"_id": "site"}) or {}
    return {k: doc.get(k) for k in PUBLIC_FIELDS}


@router.get("/admin/settings")
async def admin_settings(_: dict = Depends(require_admin)):
    doc = await get_db().settings.find_one({"_id": "site"}) or {}
    return _admin_response(doc)


@router.put("/admin/settings")
async def update_settings(payload: SettingsInput, _: dict = Depends(require_admin)):
    db = get_db()
    data = {k: v for k, v in payload.model_dump().items() if v is not None}
    unset = {}
    clear_flags = {
        "clear_dolibarr_api_key": "dolibarr_api_key",
        "clear_google_places_api_key": "google_places_api_key",
    }
    for flag, field in clear_flags.items():
        if data.pop(flag, False):
            data.pop(field, None)
            unset[field] = ""
    for field in SECRET_FIELDS:
        if field in data and not str(data[field]).strip():
            data.pop(field)
    data["updated_at"] = now_utc()
    update = {"$set": data}
    if unset:
        update["$unset"] = unset
    await db.settings.update_one({"_id": "site"}, update, upsert=True)
    doc = await db.settings.find_one({"_id": "site"})
    return _admin_response(doc)

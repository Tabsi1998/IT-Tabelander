from fastapi import APIRouter, Depends, HTTPException

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
    "impressum_html", "datenschutz_html", "legal_reviewed",
    "logo_light_url", "logo_dark_url",
]

SECRET_FIELDS = ("dolibarr_api_key", "google_places_api_key")


def _admin_response(doc: dict) -> dict:
    allowed = set(SettingsInput.model_fields)
    result = {key: value for key, value in doc.items() if key in allowed}
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
async def update_settings(payload: SettingsInput, admin: dict = Depends(require_admin)):
    db = get_db()
    data = {k: v for k, v in payload.model_dump().items() if v is not None}
    if admin.get("role") != "super_admin":
        existing = await db.settings.find_one({"_id": "site"}) or {}
        requested_base = data.get("dolibarr_base_url")
        current_base = str(existing.get("dolibarr_base_url") or "").strip().rstrip("/")
        api_suffix = "/api/index.php"
        if current_base.lower().endswith(api_suffix):
            current_base = current_base[:-len(api_suffix)].rstrip("/")
        changes_base = (
            requested_base is not None
            and str(requested_base).rstrip("/") != current_base
        )
        changes_key = bool(str(data.get("dolibarr_api_key") or "").strip())
        clears_key = data.get("clear_dolibarr_api_key") is True
        if changes_base or changes_key or clears_key:
            raise HTTPException(
                status_code=403,
                detail="Nur Super-Admins dürfen Dolibarr-URL oder API-Key ändern.",
            )
        # Never write protected fields from a non-super-admin request. This
        # also prevents a stale full-form submission from reverting a newer
        # super-admin change.
        data.pop("dolibarr_base_url", None)
        data.pop("dolibarr_api_key", None)
        data.pop("clear_dolibarr_api_key", None)
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

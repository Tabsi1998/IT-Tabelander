import nh3
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, EmailStr

import logging

from .. import mailer, site_data
from ..db import get_db, now_utc, serialize
from ..models import SettingsInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["settings"])
logger = logging.getLogger("it-tabelander.settings")
CONTENT_FIELDS = ("dolibarr_content_category_id", "dolibarr_imprint_article_id",
                  "dolibarr_privacy_article_id", "dolibarr_terms_article_id")

# fields safe to expose publicly (no secrets / API keys)
PUBLIC_FIELDS = [
    "company_name", "tagline", "email", "phone", "address", "city", "region",
    "postal_code", "country", "service_area", "opening_hours", "social_links",
    "ga_measurement_id", "seo_default_title", "seo_default_description",
    "canonical_base_url",
    "impressum_html", "datenschutz_html", "legal_reviewed",
    "logo_light_url", "logo_dark_url",
]

SECRET_FIELDS = ("dolibarr_api_key", "smtp_password")
# Where mail goes out and with which account: super admins only, like the
# Dolibarr key (#38).
SMTP_PROTECTED = ("smtp_host", "smtp_port", "smtp_security", "smtp_username")
# Rendered as HTML by the website; nh3 drops scripts, event handlers and
# javascript: links before they reach a browser (#32).
HTML_FIELDS = ("impressum_html", "datenschutz_html")


def _admin_response(doc: dict) -> dict:
    allowed = set(SettingsInput.model_fields)
    result = {key: value for key, value in doc.items() if key in allowed}
    for field in SECRET_FIELDS:
        result[f"{field}_configured"] = bool(result.pop(field, ""))
    return serialize(result)


@router.get("/settings")
async def public_settings():
    doc = await get_db().settings.find_one({"_id": "site"}) or {}
    result = {k: doc.get(k) for k in PUBLIC_FIELDS}
    try:
        # Company data, hours and legal texts live in Dolibarr (#74).
        result.update(await site_data.public_overlay())
    except Exception as exc:  # noqa: BLE001
        logger.warning("Dolibarr site data unavailable: %s", type(exc).__name__)
    for field in HTML_FIELDS:
        if result.get(field):
            result[field] = nh3.clean(str(result[field]))
    return result


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
        changes_smtp = any(
            field in data and data[field] != existing.get(field) for field in SMTP_PROTECTED
        ) or bool(str(data.get("smtp_password") or "").strip()) or data.get("clear_smtp_password") is True
        if changes_smtp:
            raise HTTPException(
                status_code=403,
                detail="Nur Super-Admins dürfen den Mail-Zugang ändern.",
            )
        for field in (*SMTP_PROTECTED, "smtp_password", "clear_smtp_password"):
            data.pop(field, None)
    unset = {}
    clear_flags = {
        "clear_dolibarr_api_key": "dolibarr_api_key",
        "clear_smtp_password": "smtp_password",
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
    if any(field in data for field in CONTENT_FIELDS) or "dolibarr_base_url" in data or "dolibarr_api_key" in data:
        await site_data.forget(db)
    doc = await db.settings.find_one({"_id": "site"})
    return _admin_response(doc)


class TestMailInput(BaseModel):
    to: EmailStr | None = None


@router.post("/admin/settings/test-mail")
async def send_test_mail(payload: TestMailInput, admin: dict = Depends(require_admin)):
    """The "Test-Mail senden" button: proves the SMTP settings (#38)."""
    recipient = str(payload.to or admin.get("email") or "")
    if not recipient:
        raise HTTPException(status_code=400, detail="Bitte eine Empfänger-Adresse angeben.")
    try:
        await mailer.send_mail(
            recipient,
            "Test-Mail der Website",
            "Diese Test-Mail kommt von der IT-Tabelander-Website.\n\n"
            "Wenn du sie liest, funktioniert der E-Mail-Versand: Warnungen zu Anfragen, "
            "die nicht in Dolibarr ankommen, erreichen dich.\n",
        )
    except (mailer.MailNotConfigured, mailer.MailError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from None
    return {"ok": True, "to": recipient, "message": f"Test-Mail an {recipient} gesendet."}

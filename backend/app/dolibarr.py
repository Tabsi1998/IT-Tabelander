"""Dolibarr REST API integration layer.

Config (base URL, API key, enabled) is read from the admin settings document
first, then falls back to environment variables. The frontend never talks to
Dolibarr directly. Demo mode is used when no API key is configured.
"""
import logging
import os
import re
from urllib.parse import quote, urlencode

import httpx

from .db import get_db, now_utc

logger = logging.getLogger("dolibarr")


async def get_config() -> dict:
    s = await get_db().settings.find_one({"_id": "site"}) or {}
    api_key = (s.get("dolibarr_api_key") or os.environ.get("DOLIBARR_API_KEY", "")).strip()
    base = (s.get("dolibarr_base_url") or os.environ.get("DOLIBARR_BASE_URL", "")).rstrip("/")
    base = re.sub(r"/api/index\.php$", "", base, flags=re.IGNORECASE)
    enabled_flag = s.get("dolibarr_enabled")
    if enabled_flag is None:
        enabled_flag = os.environ.get("DOLIBARR_ENABLED", "false").lower() == "true"
    try:
        timeout = float(s.get("dolibarr_timeout_seconds") or os.environ.get("DOLIBARR_TIMEOUT_SECONDS", "8"))
    except (TypeError, ValueError):
        timeout = 8.0
    country_code = str(s.get("dolibarr_country_code") or os.environ.get("DOLIBARR_COUNTRY_CODE", "AT")).upper()
    site_base = str(
        s.get("canonical_base_url")
        or os.environ.get("CANONICAL_BASE_URL", "https://it.tabelander.co.at")
    ).rstrip("/")
    public_ticket_enabled = bool(s.get("dolibarr_public_ticket_enabled", False))
    ticket_categories = s.get("dolibarr_ticket_categories") or {}
    return {"api_key": api_key, "base": base, "enabled": bool(enabled_flag) and bool(api_key),
            "timeout": min(max(timeout, 1), 60), "country_code": country_code,
            "site_base": site_base, "public_ticket_enabled": public_ticket_enabled,
            "ticket_categories": ticket_categories}


def _headers(cfg):
    return {"DOLAPIKEY": cfg["api_key"], "Accept": "application/json", "Content-Type": "application/json"}


def _response_detail(response: httpx.Response) -> str:
    """Return a short Dolibarr error without ever exposing request headers."""
    try:
        payload = response.json()
    except ValueError:
        payload = None
    detail = ""
    if isinstance(payload, dict):
        error = payload.get("error")
        if isinstance(error, dict):
            detail = str(error.get("message") or error.get("code") or "")
        elif error:
            detail = str(error)
        detail = detail or str(payload.get("message") or payload.get("detail") or "")
    if not detail:
        detail = re.sub(r"<[^>]+>", " ", response.text or "")
    return " ".join(detail.split())[:500]


def _error_info(exc: Exception, cfg: dict, action: str) -> dict:
    status = None
    detail = ""
    if isinstance(exc, httpx.HTTPStatusError):
        status = exc.response.status_code
        detail = _response_detail(exc.response)
        if status == 401:
            message = "Dolibarr lehnt den API-Key ab (HTTP 401)."
        elif status == 403:
            message = f"Dolibarr verweigert den Zugriff beim {action} (HTTP 403). Bitte die Berechtigungen des API-Benutzers prüfen."
        elif status == 404:
            message = "Dolibarr-API nicht gefunden (HTTP 404). Bitte die Basis-URL prüfen; sie darf nicht mit /api/index.php enden."
        else:
            message = f"Dolibarr meldet beim {action} HTTP {status}."
    elif isinstance(exc, httpx.TimeoutException):
        message = f"Dolibarr antwortet beim {action} nicht innerhalb von {cfg['timeout']:g} Sekunden."
    elif isinstance(exc, httpx.RequestError):
        message = f"Dolibarr ist beim {action} nicht erreichbar ({type(exc).__name__})."
    elif isinstance(exc, ValueError):
        message = f"Dolibarr lieferte beim {action} unerwartete Daten."
        detail = str(exc)
    else:
        message = f"{action.capitalize()} fehlgeschlagen ({type(exc).__name__})."
    if detail and cfg.get("api_key"):
        detail = detail.replace(cfg["api_key"], "***")
    return {"message": message, "detail": detail, "http_status": status,
            "error_type": type(exc).__name__}


async def is_enabled() -> bool:
    return (await get_config())["enabled"]


async def test_connection() -> dict:
    cfg = await get_config()
    if not cfg["enabled"]:
        return {"connected": False, "demo": True,
                "message": "Dolibarr im Demo-Modus. API-Key in den Einstellungen hinterlegen."}
    try:
        action = "Prüfen der Dolibarr-API"
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as c:
            checks = {}
            for key, label, path in (
                ("api", "Dolibarr-API", "status"),
                ("thirdparties", "Interessenten/Firmen", "thirdparties"),
                ("tickets", "Tickets", "tickets"),
            ):
                action = f"Prüfen von {label}"
                response = await c.get(
                    f"{cfg['base']}/api/index.php/{path}",
                    headers=_headers(cfg),
                    params=None if path == "status" else {"limit": 1, "page": 0},
                )
                if key == "thirdparties" and response.status_code == 404:
                    detail = _response_detail(response)
                    if re.search(
                        r"\bno third part(?:y|ies) found\b", detail, re.IGNORECASE
                    ):
                        # Dolibarr returns 404 (instead of []) for an empty
                        # third-party collection. The endpoint itself is valid.
                        checks[key] = True
                        continue
                response.raise_for_status()
                checks[key] = True
            return {"connected": True, "demo": False,
                    "checks": checks,
                    "message": "API, Interessenten/Firmen und Tickets sind erreichbar."}
    except Exception as exc:  # noqa: BLE001
        info = _error_info(exc, cfg, action)
        logger.warning("Dolibarr connection failed: %s: %s", info["error_type"], info["detail"])
        return {"connected": False, "demo": False, **info}


REQUEST_TYPE_LABELS = {
    "repair": "Reparatur",
    "pc_build": "PC-Neubau",
    "pc_upgrade": "PC-Aufrüstung",
    "controller_custom": "Controller-Umbau",
    "consulting": "Beratung",
    "other": "Sonstige Anfrage",
}

DEVICE_SOURCE_LABELS = {
    "new_controller": "Neuen Controller mitbestellen",
    "send_in": "Vorhandenen Controller einsenden",
    "unsure": "Noch unsicher",
}


def _remote_id(payload) -> str:
    """Extract the id returned by the different supported Dolibarr versions."""
    if isinstance(payload, (str, int)) and str(payload).strip():
        return str(payload).strip()
    if isinstance(payload, dict):
        for key in ("id", "rowid", "ref"):
            if payload.get(key) not in (None, ""):
                return str(payload[key]).strip()
    if isinstance(payload, list) and payload:
        return _remote_id(payload[0])
    raise ValueError("Dolibarr-Antwort enthält keine ID.")


def _sync_error(exc: Exception, cfg: dict, stage: str, action: str,
                thirdparty_id: str | None = None) -> dict:
    info = _error_info(exc, cfg, action)
    error = {
        "type": info["error_type"],
        "message": info["message"],
        "detail": info["detail"],
    }
    logger.warning("Dolibarr inquiry sync failed at %s: %s: %s",
                   stage, error["type"], error["detail"])
    return {
        "created": False,
        "synced": False,
        "demo": False,
        "stage": stage,
        "error": error,
        "http_status": info["http_status"],
        "thirdparty_id": thirdparty_id,
        "ticket_id": None,
        "ticket_ref": None,
        "attempted_at": now_utc(),
    }


def _sync_disabled() -> dict:
    return {
        "created": False,
        "synced": False,
        "demo": True,
        "stage": "disabled",
        "error": None,
        "http_status": None,
        "thirdparty_id": None,
        "ticket_id": None,
        "ticket_ref": None,
        "attempted_at": now_utc(),
    }


async def _lookup_thirdparty(client: httpx.AsyncClient, cfg: dict, email: str) -> str | None:
    """Use Dolibarr's official email endpoint; a 404 means no match."""
    response = await client.get(
        f"{cfg['base']}/api/index.php/thirdparties/email/{quote(email, safe='')}",
        headers=_headers(cfg),
    )
    if response.status_code == 404:
        return None
    response.raise_for_status()
    payload = response.json()
    if payload in (None, [], {}):
        return None
    return _remote_id(payload)


async def _create_thirdparty(client: httpx.AsyncClient, cfg: dict, contact: dict) -> str:
    """Create a prospect (client=2) using Dolibarr's automatic customer code."""
    payload = {
        "name": contact.get("company_name") or contact.get("name") or contact.get("email"),
        "email": contact.get("email", ""),
        "phone_mobile": contact.get("phone", ""),
        "client": 2,
        # Dolibarr interprets -1 as "generate with the configured module".
        "code_client": "-1",
        "country_code": contact.get("country_code") or cfg["country_code"],
        "address": contact.get("address", ""),
        "zip": contact.get("postal_code", ""),
        "town": contact.get("city", ""),
        "url": contact.get("website", ""),
        "tva_intra": contact.get("vat_id", ""),
        # Austrian labels: idprof1 tax number, idprof2 court,
        # idprof3 company-register number and idprof5 EORI.
        "idprof1": contact.get("tax_number", ""),
        "idprof2": contact.get("court", ""),
        "idprof3": contact.get("company_registration", ""),
        "idprof5": contact.get("eori", ""),
        "particulier": 0 if contact.get("contact_type") == "business" else 1,
        "status": 1,
    }
    response = await client.post(
        f"{cfg['base']}/api/index.php/thirdparties",
        headers=_headers(cfg), json=payload,
    )
    response.raise_for_status()
    return _remote_id(response.json())


async def _enrich_thirdparty(
    client: httpx.AsyncClient, cfg: dict, thirdparty_id: str, contact: dict,
) -> None:
    """Fill customer-supplied details without clearing existing Dolibarr data."""
    detail_fields = (
        "company_name", "address", "postal_code", "city", "website", "vat_id",
        "tax_number", "company_registration", "court", "eori",
    )
    if not any(str(contact.get(field) or "").strip() for field in detail_fields):
        return
    mapping = {
        "company_name": "name",
        "phone": "phone_mobile",
        "address": "address",
        "postal_code": "zip",
        "city": "town",
        "country_code": "country_code",
        "website": "url",
        "vat_id": "tva_intra",
        "tax_number": "idprof1",
        "court": "idprof2",
        "company_registration": "idprof3",
        "eori": "idprof5",
    }
    payload = {
        remote: str(contact.get(local) or "").strip()
        for local, remote in mapping.items()
        if str(contact.get(local) or "").strip()
    }
    if not payload:
        return
    response = await client.put(
        f"{cfg['base']}/api/index.php/thirdparties/{quote(str(thirdparty_id), safe='')}",
        headers=_headers(cfg), json=payload,
    )
    response.raise_for_status()


async def _create_ticket(client: httpx.AsyncClient, cfg: dict, *, subject: str,
                         message: str, email: str, thirdparty_id: str,
                         track_id: str | None = None,
                         classification: dict | None = None) -> str:
    payload = {
        "subject": subject,
        "message": message,
        "fk_soc": thirdparty_id,
        "origin_email": email,
    }
    if track_id:
        payload["track_id"] = track_id
    payload.update(classification or {})
    response = await client.post(
        f"{cfg['base']}/api/index.php/tickets",
        headers=_headers(cfg), json=payload,
    )
    response.raise_for_status()
    return _remote_id(response.json())


async def _lookup_ticket_by_track_id(
    client: httpx.AsyncClient, cfg: dict, track_id: str
) -> dict | None:
    """Recover a ticket created before a lost/timeout response."""
    response = await client.get(
        f"{cfg['base']}/api/index.php/tickets/track_id/{quote(track_id, safe='')}",
        headers=_headers(cfg),
    )
    if response.status_code == 404:
        return None
    response.raise_for_status()
    payload = response.json()
    if not isinstance(payload, dict):
        raise ValueError("Die Ticket-Suche liefert kein Ticketobjekt.")
    return payload


async def _sync_ticket_with_client(client: httpx.AsyncClient, cfg: dict, *,
                                   subject: str, message: str, contact: dict,
                                   previous: dict | None = None,
                                   track_id: str | None = None,
                                   classification: dict | None = None) -> dict:
    previous = previous or {}
    if previous.get("synced") or previous.get("ticket_id") or previous.get("ticket_ref"):
        return {**previous, "created": True, "synced": True, "stage": "complete",
                "error": None, "http_status": None}

    if track_id:
        try:
            existing_ticket = await _lookup_ticket_by_track_id(client, cfg, track_id)
        except Exception as exc:  # noqa: BLE001
            return _sync_error(
                exc, cfg, "ticket_lookup", "Suchen des bestehenden Tickets",
                previous.get("thirdparty_id"),
            )
        if existing_ticket:
            ticket_id = _remote_id(existing_ticket)
            thirdparty_id = existing_ticket.get("fk_soc") or previous.get("thirdparty_id")
            timestamp = now_utc()
            return {
                "created": True,
                "synced": True,
                "recovered": True,
                "demo": False,
                "stage": "complete",
                "error": None,
                "http_status": None,
                "thirdparty_id": str(thirdparty_id) if thirdparty_id else None,
                "ticket_id": ticket_id,
                "ticket_ref": str(existing_ticket.get("ref") or ticket_id),
                "ticket_track_id": track_id,
                "attempted_at": timestamp,
                "synced_at": timestamp,
            }

    email = str(contact.get("email") or "").strip().lower()
    thirdparty_id = previous.get("thirdparty_id")
    if not thirdparty_id:
        try:
            thirdparty_id = await _lookup_thirdparty(client, cfg, email)
        except Exception as exc:  # noqa: BLE001
            return _sync_error(exc, cfg, "thirdparty_lookup", "Suchen des Interessenten")
    if not thirdparty_id:
        try:
            thirdparty_id = await _create_thirdparty(client, cfg, contact)
        except Exception as exc:  # noqa: BLE001
            return _sync_error(exc, cfg, "thirdparty_create", "Anlegen des Interessenten")
    else:
        try:
            await _enrich_thirdparty(client, cfg, str(thirdparty_id), contact)
        except Exception as exc:  # noqa: BLE001
            # The customer and their local inquiry already exist. Optional
            # profile enrichment must not prevent creating the actual ticket.
            logger.info("Dolibarr third-party enrichment skipped: %s", type(exc).__name__)

    try:
        ticket_id = await _create_ticket(
            client, cfg, subject=subject, message=message,
            email=email, thirdparty_id=str(thirdparty_id), track_id=track_id,
            classification=classification,
        )
    except Exception as exc:  # noqa: BLE001
        return _sync_error(exc, cfg, "ticket_create", "Anlegen des Tickets", str(thirdparty_id))

    ticket_ref = ticket_id
    if track_id:
        try:
            created_ticket = await _lookup_ticket_by_track_id(client, cfg, track_id)
            if created_ticket:
                ticket_ref = str(created_ticket.get("ref") or ticket_id)
        except Exception:  # noqa: BLE001
            # The ticket is already created. A cosmetic reference lookup must
            # never turn a successful sync into a failed customer inquiry.
            logger.info("Dolibarr ticket reference lookup failed after creation")
    return {
        "created": True,
        "synced": True,
        "demo": False,
        "stage": "complete",
        "error": None,
        "http_status": None,
        "thirdparty_id": str(thirdparty_id),
        "ticket_id": ticket_id,
        # Preserve the old response key used by existing admin code.
        "ticket_ref": ticket_ref,
        "ticket_track_id": track_id,
        "attempted_at": now_utc(),
        "synced_at": now_utc(),
    }


def inquiry_subject(inquiry: dict) -> str:
    kind = REQUEST_TYPE_LABELS.get(inquiry.get("request_type"), "Website-Anfrage")
    device = str(inquiry.get("device_type") or "").strip()
    suffix = f": {device}" if device else ""
    reference = str(inquiry.get("ref") or "").strip()
    return f"{kind}{suffix} ({reference})" if reference else f"{kind}{suffix}"


def inquiry_track_id(inquiry: dict) -> str:
    """Stable Dolibarr tracking id for retry-safe ticket creation."""
    reference = str(inquiry.get("ref") or "")
    cleaned = re.sub(r"[^A-Za-z0-9]", "", reference).upper()
    if not cleaned:
        cleaned = re.sub(r"[^A-Za-z0-9]", "", str(inquiry.get("request_id") or "")).upper()
    return f"IT{cleaned}"[:16]


TICKET_TYPE_CODES = {
    "repair": "ISSUE",
    "pc_build": "COM",
    "pc_upgrade": "REQUEST",
    "controller_custom": "REQUEST",
    "consulting": "COM",
    "other": "OTHER",
}


def _ticket_classification(inquiry: dict, cfg: dict) -> dict:
    request_type = str(inquiry.get("request_type") or "other")
    result = {
        "type_code": TICKET_TYPE_CODES.get(request_type, "OTHER"),
        "severity_code": "NORMAL",
    }
    category = str((cfg.get("ticket_categories") or {}).get(request_type) or "").strip()
    if category:
        result["category_code"] = category.upper()
    return result


def _public_ticket_url(cfg: dict, track_id: str, email: str) -> str | None:
    if not cfg.get("public_ticket_enabled") or not cfg.get("base") or not email:
        return None
    query = urlencode({"track_id": track_id, "email": email})
    return f"{cfg['base']}/public/ticket/view.php?{query}"


def format_inquiry_message(inquiry: dict) -> str:
    """Build the complete, readable plain-text ticket body."""
    contact = inquiry.get("contact") or {}
    type_label = REQUEST_TYPE_LABELS.get(inquiry.get("request_type"), inquiry.get("request_type") or "–")
    attachment_lines = []
    site_base = str(inquiry.get("_site_base") or "").rstrip("/")
    for attachment in inquiry.get("attachments") or []:
        url = attachment.get("url") if isinstance(attachment, dict) else str(attachment)
        if url:
            attachment_lines.append(f"- {site_base + url if site_base and url.startswith('/') else url}")
    if not attachment_lines:
        attachment_lines = [
            f"- Medien-ID {attachment_id}"
            for attachment_id in inquiry.get("attachment_ids") or []
        ]
    lines = [
        f"Anfrage-Referenz: {inquiry.get('ref') or '–'}",
        f"Anfrageart: {type_label}",
        f"Quelle: {inquiry.get('source') or '–'}",
        f"Gerät / Bereich: {inquiry.get('device_type') or '–'}",
        f"Geräteherkunft: {DEVICE_SOURCE_LABELS.get(inquiry.get('device_source'), inquiry.get('device_source')) or '–'}",
        f"Hersteller: {inquiry.get('manufacturer') or '–'}",
        f"Modell: {inquiry.get('model') or '–'}",
        f"Probleme / Symptome: {', '.join(inquiry.get('issues') or []) or '–'}",
        f"Gewünschte Leistungen: {', '.join(inquiry.get('desired_services') or []) or '–'}",
        f"Budget: {inquiry.get('budget') or '–'}",
        f"Zeitrahmen: {inquiry.get('timeframe') or '–'}",
        "",
        "Beschreibung:",
        str(inquiry.get("description") or "–"),
        "",
        "Anhänge:",
        *(attachment_lines or ["–"]),
        "",
        "Kontakt:",
        f"Name: {contact.get('name') or '–'}",
        f"E-Mail: {contact.get('email') or '–'}",
        f"Telefon: {contact.get('phone') or '–'}",
        f"Bevorzugter Kontakt: {contact.get('preferred_contact') or '–'}",
    ]
    optional_contact = [
        ("Kontaktart", "Firma" if contact.get("contact_type") == "business" else "Privatperson"),
        ("Firma", contact.get("company_name")),
        ("Adresse", contact.get("address")),
        ("PLZ / Ort", " ".join(filter(None, [contact.get("postal_code"), contact.get("city")]))),
        ("Land", contact.get("country_code")),
        ("Website", contact.get("website")),
        ("UID", contact.get("vat_id")),
        ("Firmenbuchnummer", contact.get("company_registration")),
        ("Steuernummer", contact.get("tax_number")),
        ("Gerichtsstand", contact.get("court")),
        ("EORI", contact.get("eori")),
    ]
    lines.extend(f"{label}: {value}" for label, value in optional_contact if value)
    if inquiry.get("request_id"):
        lines.extend(["", f"Request-ID: {inquiry['request_id']}"])
    return "\n".join(lines)


async def create_ticket_for_inquiry(inquiry: dict, previous: dict | None = None) -> dict:
    """Best-effort Dolibarr sync. The local inquiry must already be persisted."""
    cfg = {"api_key": "", "timeout": 8.0}
    stage = "configuration"
    action = "Laden der Dolibarr-Konfiguration"
    try:
        cfg = await get_config()
        if not cfg["enabled"]:
            return _sync_disabled()
        stage = "connection"
        action = "Synchronisieren der Anfrage"
        ticket_inquiry = {**inquiry, "_site_base": cfg.get("site_base", "")}
        track_id = inquiry_track_id(ticket_inquiry)
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
            result = await _sync_ticket_with_client(
                client, cfg,
                subject=inquiry_subject(ticket_inquiry),
                message=format_inquiry_message(ticket_inquiry),
                contact=inquiry.get("contact") or {},
                previous=previous,
                track_id=track_id or None,
                classification=_ticket_classification(ticket_inquiry, cfg),
            )
            if result.get("synced") and track_id:
                result["ticket_public_url"] = _public_ticket_url(
                    cfg, track_id, str((inquiry.get("contact") or {}).get("email") or ""),
                )
            return result
    except Exception as exc:  # noqa: BLE001
        return _sync_error(exc, cfg, stage, action,
                           (previous or {}).get("thirdparty_id"))


async def create_ticket_for_repair(repair: dict, previous: dict | None = None) -> dict:
    """Legacy alias retained for old imports."""
    return await create_ticket_for_inquiry(repair, previous=previous)

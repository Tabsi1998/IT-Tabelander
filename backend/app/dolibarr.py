"""Dolibarr REST API integration layer.

Config (base URL, API key, enabled) is read from the admin settings document
first, then falls back to environment variables. The frontend never talks to
Dolibarr directly. Demo mode is used when no API key is configured.
"""
import base64
import logging
import os
import re
from datetime import datetime, timezone
from pathlib import Path
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
            version = None
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
                if key == "api":
                    version = ((response.json() or {}).get("success") or {}).get("dolibarr_version")
            return {"connected": True, "demo": False, "version": version or None,
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
    "contact": "Kontaktnachricht",
}

# The device choices of the inquiry form, in words (ticket subject, label #72).
DEVICE_LABELS = {
    "pc": "Desktop-PC",
    "notebook": "Notebook",
    "playstation": "PlayStation",
    "xbox": "Xbox",
    "switch": "Switch",
    "controller": "Controller",
    "other": "Sonstiges",
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


async def _create_ticket(client: httpx.AsyncClient, cfg: dict, *, subject: str,
                         message: str, email: str, thirdparty_id: str | None,
                         track_id: str | None = None,
                         classification: dict | None = None,
                         notify: bool = True) -> str:
    payload = {"subject": subject, "message": message}
    if thirdparty_id:
        payload.update({"fk_soc": thirdparty_id, "socid": thirdparty_id})
    payload.update({
        "origin_email": email,
        # Dolibarr's ticket module sends the customer its own confirmation
        # with the tracking link (TICKET_URL_PUBLIC_INTERFACE) (#40). It goes
        # to the third party's address, or to origin_email without one.
        "notify_tiers_at_create": 1 if notify else 0,
    })
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


async def _upload_ticket_document(
    client: httpx.AsyncClient, cfg: dict, ticket_id: str, attachment: dict,
) -> None:
    """Attach one inquiry photo to the ticket through Dolibarr's document API (#37)."""
    from .routers.media import UPLOAD_DIR

    filename = Path(str(attachment.get("url") or "")).name
    if not filename:
        raise ValueError("Anhang ohne Dateiname.")
    content = (Path(UPLOAD_DIR) / filename).read_bytes()
    response = await client.post(
        f"{cfg['base']}/api/index.php/documents/upload",
        headers=_headers(cfg),
        json={
            "filename": f"anfrage-foto-{attachment.get('id') or filename}.webp",
            "modulepart": "ticket",
            # Tickets are addressed by their numeric id here.
            "ref": str(ticket_id),
            "filecontent": base64.b64encode(content).decode("ascii"),
            "fileencoding": "base64",
            "overwriteifexists": 1,
        },
    )
    response.raise_for_status()


def attachments_of(doc: dict) -> list[dict]:
    """Photos of a record; early versions stored bare URLs."""
    result = []
    for item in doc.get("attachments") or []:
        if isinstance(item, dict) and (item.get("id") or item.get("url")):
            result.append(item)
        elif isinstance(item, str) and item:
            result.append({"id": item, "url": item})
    return result


async def _find_callback_event(client: httpx.AsyncClient, cfg: dict, ticket_id: str) -> str | None:
    """A callback created by an attempt whose answer got lost."""
    response = await client.get(
        f"{cfg['base']}/api/index.php/agendaevents",
        headers=_headers(cfg),
        params={"sqlfilters": f"(t.fk_element:=:{int(ticket_id)}) and (t.elementtype:=:'ticket') "
                              "and (t.code:=:'AC_TEL')", "limit": 1},
    )
    if response.status_code == 404:
        return None
    response.raise_for_status()
    found = response.json()
    return _remote_id(found) if found else None


async def _create_callback_event(client: httpx.AsyncClient, cfg: dict, *, ticket_id: str,
                                 thirdparty_id: str | None, callback: dict) -> str:
    """The customer's callback wish as a phone call in Dolibarr's agenda (#73)."""
    existing = await _find_callback_event(client, cfg, ticket_id)
    if existing:
        return existing
    # The event belongs to the user who created the ticket: the website's
    # own API user. That needs only "create own events" (users/info would
    # need "modify own user"), and an admin's agenda shows every event.
    ticket = await client.get(f"{cfg['base']}/api/index.php/tickets/{int(ticket_id)}",
                              headers=_headers(cfg), params={"contact_list": 0})
    ticket.raise_for_status()
    owner = (ticket.json() or {}).get("fk_user_create")
    if not owner:
        raise ValueError("Das Ticket nennt keinen anlegenden Benutzer.")
    start = int(datetime.fromisoformat(str(callback["at"])).timestamp())
    payload = {
        "userownerid": str(owner),
        "type_code": "AC_TEL",
        "label": callback["label"],
        "datep": start,
        "datef": start + 15 * 60,
        "fulldayevent": 0,
        "percentage": 0,
        "note_private": callback["note"],
        "elementtype": "ticket",
        # "fk_element" is locked in the API; "elementid" fills it.
        "elementid": int(ticket_id),
    }
    if thirdparty_id:
        payload["socid"] = int(thirdparty_id)
    response = await client.post(f"{cfg['base']}/api/index.php/agendaevents",
                                 headers=_headers(cfg), json=payload)
    response.raise_for_status()
    return _remote_id(response.json())


def _ticket_state(previous: dict) -> dict:
    """The parts of an earlier attempt that a retry must keep."""
    return {
        key: previous.get(key)
        for key in ("thirdparty_id", "existing_customer", "ticket_id", "ticket_ref",
                    "ticket_track_id", "documents_uploaded", "callback_event_id")
        if previous.get(key) not in (None, "", [])
    }


async def _sync_ticket_with_client(client: httpx.AsyncClient, cfg: dict, *,
                                   subject: str, message: str, contact: dict,
                                   previous: dict | None = None,
                                   track_id: str | None = None,
                                   classification: dict | None = None,
                                   attachments: list | None = None,
                                   create_thirdparty: bool = True,
                                   notify: bool = True,
                                   callback: dict | None = None) -> dict:
    """Third party, ticket, photos, callback - each stage resumes where an
    attempt stopped. Without create_thirdparty (contact messages) a known
    customer is linked and a stranger stays without third party (#42)."""
    state = _ticket_state(previous or {})

    def failed(exc: Exception, stage: str, action: str) -> dict:
        result = _sync_error(exc, cfg, stage, action, state.get("thirdparty_id"))
        result.update(state)
        return result

    if not state.get("ticket_id") and track_id:
        try:
            existing_ticket = await _lookup_ticket_by_track_id(client, cfg, track_id)
        except Exception as exc:  # noqa: BLE001
            return failed(exc, "ticket_lookup", "Suchen des bestehenden Tickets")
        if existing_ticket:
            thirdparty_id = existing_ticket.get("fk_soc") or state.get("thirdparty_id")
            state.update({
                "recovered": True,
                "thirdparty_id": str(thirdparty_id) if thirdparty_id else None,
                "ticket_id": _remote_id(existing_ticket),
                "ticket_ref": str(existing_ticket.get("ref") or _remote_id(existing_ticket)),
                "ticket_track_id": track_id,
            })

    if not state.get("ticket_id"):
        email = str(contact.get("email") or "").strip().lower()
        if not state.get("thirdparty_id"):
            try:
                found = await _lookup_thirdparty(client, cfg, email)
            except Exception as exc:  # noqa: BLE001
                return failed(exc, "thirdparty_lookup", "Suchen des Interessenten")
            if found:
                # An existing customer is only linked, never changed: an
                # anonymous form must not rewrite master data (#36).
                state.update({"thirdparty_id": str(found), "existing_customer": True})
            elif not create_thirdparty:
                state["existing_customer"] = False
            else:
                try:
                    state["thirdparty_id"] = await _create_thirdparty(client, cfg, contact)
                except Exception as exc:  # noqa: BLE001
                    return failed(exc, "thirdparty_create", "Anlegen des Interessenten")
                state["existing_customer"] = False
        ticket_message = message
        if state.get("existing_customer"):
            ticket_message = (
                "Hinweis: Der Kunde war in Dolibarr schon vorhanden und wurde nicht geändert. "
                "Die Kontaktdaten unten stammen aus dem Formular.\n\n" + message
            )
        try:
            ticket_id = await _create_ticket(
                client, cfg, subject=subject, message=ticket_message,
                email=email,
                thirdparty_id=str(state["thirdparty_id"]) if state.get("thirdparty_id") else None,
                track_id=track_id, classification=classification, notify=notify,
            )
        except Exception as exc:  # noqa: BLE001
            return failed(exc, "ticket_create", "Anlegen des Tickets")
        state.update({"ticket_id": ticket_id, "ticket_ref": ticket_id, "ticket_track_id": track_id})
        if track_id:
            try:
                created_ticket = await _lookup_ticket_by_track_id(client, cfg, track_id)
                if created_ticket:
                    state["ticket_ref"] = str(created_ticket.get("ref") or ticket_id)
            except Exception:  # noqa: BLE001
                # The ticket exists. A cosmetic reference lookup must never
                # turn a successful sync into a failed customer inquiry.
                logger.info("Dolibarr ticket reference lookup failed after creation")

    uploaded = list(state.get("documents_uploaded") or [])
    for attachment in attachments or []:
        attachment_id = str(attachment.get("id") or attachment.get("url") or "")
        if attachment_id in uploaded:
            continue
        try:
            await _upload_ticket_document(client, cfg, state["ticket_id"], attachment)
        except Exception as exc:  # noqa: BLE001
            state["documents_uploaded"] = uploaded
            return failed(exc, "documents", "Anhängen der Fotos an das Ticket")
        uploaded.append(attachment_id)
    state["documents_uploaded"] = uploaded

    if callback and not state.get("callback_event_id"):
        try:
            state["callback_event_id"] = await _create_callback_event(
                client, cfg, ticket_id=str(state["ticket_id"]),
                thirdparty_id=state.get("thirdparty_id"), callback=callback,
            )
        except Exception as exc:  # noqa: BLE001
            return failed(exc, "callback", "Anlegen des Rückruf-Termins")

    timestamp = now_utc()
    return {
        **state,
        "created": True,
        "synced": True,
        "demo": False,
        "stage": "complete",
        "error": None,
        "http_status": None,
        "thirdparty_id": str(state["thirdparty_id"]) if state.get("thirdparty_id") else None,
        "ticket_id": str(state["ticket_id"]),
        # Preserve the old response key used by existing admin code.
        "ticket_ref": str(state.get("ticket_ref") or state["ticket_id"]),
        "attempted_at": timestamp,
        "synced_at": timestamp,
    }


def device_text(inquiry: dict) -> str:
    """"Notebook Lenovo ThinkPad T14": the device in words, as far as known."""
    code = str(inquiry.get("device_type") or "").strip()
    maker = str(inquiry.get("manufacturer") or "").strip()
    model = str(inquiry.get("model") or "").strip()
    device = DEVICE_LABELS.get(code, code)
    if code == "other" and (maker or model):
        device = ""
    return " ".join(part for part in (device, maker, model) if part)[:160]


def inquiry_subject(inquiry: dict) -> str:
    kind = REQUEST_TYPE_LABELS.get(inquiry.get("request_type"), "Website-Anfrage")
    device = device_text(inquiry)
    suffix = f": {device}" if device else ""
    reference = str(inquiry.get("ref") or "").strip()
    return f"{kind}{suffix} ({reference})" if reference else f"{kind}{suffix}"


def subject_without_ref(subject: str, ref: str) -> str:
    """A ticket subject for the label: without the "(ANF-...)" at its end."""
    text = str(subject or "").strip()
    if ref and text.endswith(f"({ref})"):
        text = text[: -len(f"({ref})")].rstrip()
    return text


def inquiry_track_id(inquiry: dict) -> str:
    """Stable Dolibarr tracking id for retry-safe ticket creation.

    New inquiries carry a random one (``track_id``), because it also opens the
    status view from the customer's confirmation mail (#43). Older records
    derive it from their reference as before.
    """
    if inquiry.get("track_id"):
        return str(inquiry["track_id"])
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
    "contact": "COM",
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


# Dolibarr ticket states in the words the customer sees (#43).
TICKET_STATUS_STEPS = {
    0: "eingegangen", 1: "eingegangen", 2: "eingegangen",
    3: "in_arbeit", 5: "wartet_auf_dich", 7: "pausiert",
    8: "abgeschlossen", 9: "abgebrochen",
}
# The ticket's extra field "Gerät abholbereit" (yes/no), created by the owner
# under Tickets -> Einstellungen -> Ergänzende Attribute (#78).
PICKUP_FIELD = "options_abholbereit"
PROPOSAL_VALIDATED = 1


def _is_yes(value) -> bool:
    return str(value or "").strip().lower() in ("1", "true", "yes", "ja", "on")


async def _offer_waiting(client: httpx.AsyncClient, cfg: dict, ticket_id: str, thirdparty_id) -> bool:
    """A released, not yet accepted proposal linked to this ticket (#78)."""
    if not thirdparty_id or str(thirdparty_id) in ("0", "-1"):
        return False
    response = await client.get(
        f"{cfg['base']}/api/index.php/proposals", headers=_headers(cfg),
        params={"thirdparty_ids": str(thirdparty_id), "sqlfilters": f"(t.fk_statut:=:{PROPOSAL_VALIDATED})",
                "loadlinkedobjects": 1, "limit": 50},
    )
    if response.status_code in (403, 404):
        # 404: no open proposal. 403: the website user may not read
        # proposals; the status then simply never says "Angebot bereit".
        return False
    response.raise_for_status()
    for proposal in response.json() or []:
        linked = (proposal.get("linkedObjectsIds") or {}).get("ticket") or {}
        ids = linked.values() if isinstance(linked, dict) else linked
        if str(ticket_id) in {str(value) for value in ids}:
            return True
    return False


async def fetch_ticket_status(ticket_id: str) -> dict | None:
    """The customer-visible state of one ticket, or None without Dolibarr."""
    cfg = await get_config()
    if not cfg["enabled"]:
        return None
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        response = await client.get(
            f"{cfg['base']}/api/index.php/tickets/{quote(str(ticket_id), safe='')}",
            headers=_headers(cfg),
            params={"contact_list": 0},
        )
        if response.status_code == 404:
            return None
        response.raise_for_status()
        ticket = response.json()
        try:
            code = int(ticket.get("status", ticket.get("fk_statut", 0)))
        except (TypeError, ValueError):
            code = 0
        step = TICKET_STATUS_STEPS.get(code, "eingegangen")
        if code not in (8, 9):
            # Ready for pickup beats everything open; an offer waiting for
            # the customer's answer beats the plain ticket state (#78).
            if _is_yes((ticket.get("array_options") or {}).get(PICKUP_FIELD)):
                step = "abholbereit"
            elif await _offer_waiting(client, cfg, str(ticket_id), ticket.get("fk_soc") or ticket.get("socid")):
                step = "angebot_bereit"
    return {
        "step": step,
        "origin_email": str(ticket.get("origin_email") or "").strip().lower(),
        "updated": _iso_time(ticket.get("date_modification") or ticket.get("tms") or ticket.get("datec")),
        "closed": _iso_time(ticket.get("date_close")),
    }


async def fetch_ticket_subject(ticket_id: str) -> str | None:
    """The ticket's subject as the owner keeps it in Dolibarr (label #72)."""
    cfg = await get_config()
    if not cfg["enabled"]:
        return None
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        response = await client.get(
            f"{cfg['base']}/api/index.php/tickets/{quote(str(ticket_id), safe='')}",
            headers=_headers(cfg),
            params={"contact_list": 0},
        )
        if response.status_code == 404:
            return None
        response.raise_for_status()
        return str(response.json().get("subject") or "").strip() or None


def _iso_time(value) -> str | None:
    """Dolibarr sends points in time as Unix seconds (as number or text)."""
    try:
        seconds = int(value)
    except (TypeError, ValueError):
        return None
    if seconds <= 0:
        return None
    return datetime.fromtimestamp(seconds, tz=timezone.utc).isoformat()


def _public_ticket_url(cfg: dict, track_id: str, email: str) -> str | None:
    if not cfg.get("public_ticket_enabled") or not cfg.get("base") or not email:
        return None
    query = urlencode({"track_id": track_id, "email": email})
    return f"{cfg['base']}/public/ticket/view.php?{query}"


def callback_text(value) -> str:
    """The wished time as the customer's browser sent it (own UTC offset)."""
    return datetime.fromisoformat(str(value)).strftime("%d.%m.%Y um %H:%M Uhr")


def format_contact_message(inquiry: dict) -> str:
    """A contact message as ticket body: the text, then who wrote it (#42)."""
    contact = inquiry.get("contact") or {}
    lines = [
        f"Kontaktnachricht über die Website ({inquiry.get('ref') or '–'})",
        "",
        str(inquiry.get("description") or "–"),
        "",
        "Kontakt:",
        f"Name: {contact.get('name') or '–'}",
        f"E-Mail: {contact.get('email') or '–'}",
        f"Telefon: {contact.get('phone') or '–'}",
    ]
    if inquiry.get("callback_at"):
        lines.append(f"Rückruf gewünscht: {callback_text(inquiry['callback_at'])} (Termin im Kalender)")
    return "\n".join(lines)


def format_inquiry_message(inquiry: dict) -> str:
    """Build the complete, readable plain-text ticket body."""
    if inquiry.get("request_type") == "contact":
        return format_contact_message(inquiry)
    contact = inquiry.get("contact") or {}
    type_label = REQUEST_TYPE_LABELS.get(inquiry.get("request_type"), inquiry.get("request_type") or "–")
    photo_count = len(inquiry.get("attachments") or inquiry.get("attachment_ids") or [])
    attachment_lines = [f"- {photo_count} Foto(s), am Ticket unter „Dokumente“"] if photo_count else []
    lines = [
        f"Anfrage-Referenz: {inquiry.get('ref') or '–'}",
        f"Anfrageart: {type_label}",
        f"Quelle: {inquiry.get('source') or '–'}",
        f"Gerät / Bereich: {DEVICE_LABELS.get(inquiry.get('device_type'), inquiry.get('device_type')) or '–'}",
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
        "Fotos:",
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
    if inquiry.get("callback_at"):
        lines.append(f"Rückruf gewünscht: {callback_text(inquiry['callback_at'])} (Termin im Kalender)")
    lines.append("Bewertungsbitte nach Abschluss: "
                 + ("ja, eine Mail mit Link, sobald das Ticket geschlossen ist" if inquiry.get("review_ok") else "nein"))
    if inquiry.get("request_id"):
        lines.extend(["", f"Request-ID: {inquiry['request_id']}"])
    return "\n".join(lines)


def _callback(inquiry: dict) -> dict | None:
    if not inquiry.get("callback_at"):
        return None
    contact = inquiry.get("contact") or {}
    name = contact.get("name") or contact.get("email") or "Kunde"
    return {
        "at": inquiry["callback_at"],
        "label": f"Rückruf: {name} ({inquiry.get('ref') or '–'})",
        "note": "\n".join([
            f"Telefon: {contact.get('phone') or '–'}",
            f"Wunschzeit: {callback_text(inquiry['callback_at'])}",
            f"Anfrage: {inquiry.get('ref') or '–'}",
        ]),
    }


async def create_ticket_for_inquiry(inquiry: dict, previous: dict | None = None, *,
                                    notify: bool = True) -> dict:
    """Best-effort Dolibarr sync. The local inquiry must already be persisted.

    notify=False hands old records over without a confirmation mail to the
    customer (migration, #41).
    """
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
                attachments=attachments_of(inquiry),
                create_thirdparty=inquiry.get("request_type") != "contact",
                notify=notify,
                callback=_callback(inquiry),
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

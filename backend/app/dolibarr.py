"""Dolibarr REST API integration layer.

Config (base URL, API key, enabled) is read from the admin settings document
first, then falls back to environment variables. The frontend never talks to
Dolibarr directly. Demo mode is used when no API key is configured.
"""
import logging
import os
import re

import httpx

from .db import get_db, now_utc

logger = logging.getLogger("dolibarr")
PRODUCT_PAGE_SIZE = 50
MAX_PRODUCT_PAGES = 100


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
    return {"api_key": api_key, "base": base, "enabled": bool(enabled_flag) and bool(api_key),
            "timeout": min(max(timeout, 1), 60), "country_code": country_code}


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
            message = "Dolibarr verweigert den Produktzugriff (HTTP 403). Dem API-Benutzer fehlt „Produkte/Dienstleistungen lesen“ (produit/lire)."
        elif status == 404:
            message = "Dolibarr-API nicht gefunden (HTTP 404). Bitte die Basis-URL prüfen; sie darf nicht mit /api/index.php enden."
        else:
            message = f"Dolibarr meldet beim {action} HTTP {status}."
    elif isinstance(exc, httpx.TimeoutException):
        message = f"Dolibarr antwortet beim {action} nicht innerhalb von {cfg['timeout']:g} Sekunden."
    elif isinstance(exc, httpx.RequestError):
        message = f"Dolibarr ist beim {action} nicht erreichbar ({type(exc).__name__})."
    elif isinstance(exc, ValueError):
        message = f"Dolibarr lieferte beim {action} unerwartete Produktdaten."
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
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as c:
            r = await c.get(f"{cfg['base']}/api/index.php/status", headers=_headers(cfg))
            r.raise_for_status()
            products = await c.get(
                f"{cfg['base']}/api/index.php/products", headers=_headers(cfg),
                params={"limit": 1, "page": 0, "sortfield": "t.ref", "sortorder": "ASC"},
            )
            products.raise_for_status()
            payload = products.json()
            if not (isinstance(payload, list) or
                    (isinstance(payload, dict) and isinstance(payload.get("data"), list))):
                raise ValueError("Die Produkt-API liefert weder eine Liste noch ein Datenobjekt.")
            return {"connected": True, "demo": False,
                    "message": "Verbindung und Produktzugriff erfolgreich."}
    except Exception as exc:  # noqa: BLE001
        info = _error_info(exc, cfg, "Verbindungstest")
        logger.warning("Dolibarr connection failed: %s: %s", info["error_type"], info["detail"])
        return {"connected": False, "demo": False, **info}


def _as_float(value) -> float:
    if value in (None, ""):
        return 0.0
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


def _normalize(p: dict) -> dict:
    product_id = str(p.get("id") or p.get("rowid") or "").strip()
    if not product_id:
        raise ValueError(f"Produkt ohne ID (Ref: {p.get('ref') or 'unbekannt'})")
    return {
        "dolibarr_product_id": product_id, "ref": p.get("ref", ""),
        "label": p.get("label", ""), "description": p.get("description", "") or "",
        "price": _as_float(p.get("price")), "price_ttc": _as_float(p.get("price_ttc")),
        "vat_rate": p.get("tva_tx", ""), "stock": p.get("stock_reel"),
        "status": p.get("status"), "updated_at": now_utc(),
    }


async def _fetch_products(client: httpx.AsyncClient, cfg: dict) -> list[dict]:
    products: list[dict] = []
    for page in range(MAX_PRODUCT_PAGES):
        response = await client.get(
            f"{cfg['base']}/api/index.php/products", headers=_headers(cfg),
            params={
                "limit": PRODUCT_PAGE_SIZE, "page": page, "sortfield": "t.ref",
                # Full warehouse stock expansion is very slow on larger
                # Dolibarr installations and is not required for pricing.
                "sortorder": "ASC", "includestockdata": 0,
            },
        )
        response.raise_for_status()
        payload = response.json()
        if isinstance(payload, dict) and isinstance(payload.get("data"), list):
            batch = payload["data"]
        elif isinstance(payload, list):
            batch = payload
        else:
            raise ValueError("Die Produkt-API liefert keine Produktliste.")
        if any(not isinstance(product, dict) for product in batch):
            raise ValueError("Die Produktliste enthält ungültige Einträge.")
        products.extend(batch)
        if len(batch) < PRODUCT_PAGE_SIZE:
            return products
    raise ValueError(f"Produktliste überschreitet {PRODUCT_PAGE_SIZE * MAX_PRODUCT_PAGES} Einträge.")


async def sync_products() -> dict:
    db = get_db()
    cfg = await get_config()
    started = now_utc()
    if not cfg["enabled"]:
        log = {"type": "product_sync", "status": "demo", "demo": True,
               "message": "Demo-Sync: Kein Dolibarr-API-Key konfiguriert.",
               "updated_count": 0, "started_at": started, "finished_at": now_utc()}
        await db.sync_logs.insert_one(dict(log)); return log
    try:
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as c:
            products = await _fetch_products(c, cfg)
        count = 0
        imported_ids = []
        for p in products:
            n = _normalize(p)
            await db.dolibarr_product_cache.update_one(
                {"dolibarr_product_id": n["dolibarr_product_id"]}, {"$set": n}, upsert=True)
            imported_ids.append(n["dolibarr_product_id"])
            count += 1
        stale_filter = ({"dolibarr_product_id": {"$nin": imported_ids}} if imported_ids else {})
        stale = await db.dolibarr_product_cache.delete_many(stale_filter)
        log = {"type": "product_sync", "status": "success", "demo": False,
               "message": f"{count} Produkte synchronisiert; {stale.deleted_count} veraltete Cache-Einträge entfernt.",
               "updated_count": count, "removed_count": stale.deleted_count,
               "started_at": started, "finished_at": now_utc()}
    except Exception as exc:  # noqa: BLE001
        info = _error_info(exc, cfg, "Produktsync")
        logger.error("Dolibarr sync error: %s: %s", info["error_type"], info["detail"])
        log = {"type": "product_sync", "status": "error", "demo": False,
               **info, "updated_count": 0,
               "started_at": started, "finished_at": now_utc()}
    await db.sync_logs.insert_one(dict(log)); return log


async def _create_thirdparty(client, cfg, contact) -> str | None:
    """Create a prospect (thirdparty) and return its id."""
    try:
        payload = {
            "name": contact.get("name") or contact.get("email"),
            "email": contact.get("email", ""),
            "phone": contact.get("phone", ""),
            "client": "2",           # 2 = prospect
            "code_client": "-1",
            "country_code": cfg["country_code"],
        }
        r = await client.post(f"{cfg['base']}/api/index.php/thirdparties",
                              headers=_headers(cfg), json=payload)
        r.raise_for_status()
        return str(r.json())
    except Exception as exc:  # noqa: BLE001
        logger.warning("Dolibarr thirdparty failed: %s", type(exc).__name__)
        return None


async def create_lead(data: dict, kind: str = "repair") -> dict:
    """Best-effort: create a prospect + ticket in Dolibarr. Never raises."""
    cfg = await get_config()
    if not cfg["enabled"]:
        return {"created": False, "demo": True, "thirdparty_id": None, "ticket_ref": None}
    contact = data.get("contact", {})
    try:
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
            tp_id = await _create_thirdparty(client, cfg, contact)
            ticket = {
                "subject": data.get("subject", "Website-Anfrage"),
                "message": data.get("message", ""),
                "type_code": "COM_REQUEST", "category_code": "OTHER",
                "severity_code": "NORMAL", "email": contact.get("email", ""),
            }
            if tp_id:
                ticket["fk_soc"] = tp_id
            r = await client.post(f"{cfg['base']}/api/index.php/tickets",
                                  headers=_headers(cfg), json=ticket)
            r.raise_for_status()
            return {"created": True, "demo": False, "thirdparty_id": tp_id, "ticket_ref": r.json()}
    except Exception as exc:  # noqa: BLE001
        logger.warning("Dolibarr lead failed: %s", type(exc).__name__)
        return {"created": False, "demo": False, "thirdparty_id": None, "ticket_ref": None}


async def create_ticket_for_repair(repair: dict) -> dict:
    c = repair.get("contact", {})
    return await create_lead({
        "subject": f"Reparaturanfrage: {repair.get('device_type', 'Gerät')} ({repair.get('ref','')})",
        "message": f"Fehler: {', '.join(repair.get('issues', []))}\n{repair.get('description', '')}",
        "contact": c,
    }, kind="repair")

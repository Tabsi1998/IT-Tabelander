"""Dolibarr REST API integration layer.

Config (base URL, API key, enabled) is read from the admin settings document
first, then falls back to environment variables. The frontend never talks to
Dolibarr directly. Demo mode is used when no API key is configured.
"""
import logging
import os

import httpx

from .db import get_db, now_utc

logger = logging.getLogger("dolibarr")


async def get_config() -> dict:
    s = await get_db().settings.find_one({"_id": "site"}) or {}
    api_key = (s.get("dolibarr_api_key") or os.environ.get("DOLIBARR_API_KEY", "")).strip()
    base = (s.get("dolibarr_base_url") or os.environ.get("DOLIBARR_BASE_URL", "")).rstrip("/")
    enabled_flag = s.get("dolibarr_enabled")
    if enabled_flag is None:
        enabled_flag = os.environ.get("DOLIBARR_ENABLED", "false").lower() == "true"
    return {"api_key": api_key, "base": base, "enabled": bool(enabled_flag) and bool(api_key)}


def _timeout() -> float:
    try:
        return float(os.environ.get("DOLIBARR_TIMEOUT_SECONDS", "8"))
    except ValueError:
        return 8.0


def _headers(cfg):
    return {"DOLAPIKEY": cfg["api_key"], "Accept": "application/json", "Content-Type": "application/json"}


async def is_enabled() -> bool:
    return (await get_config())["enabled"]


async def test_connection() -> dict:
    cfg = await get_config()
    if not cfg["enabled"]:
        return {"connected": False, "demo": True,
                "message": "Dolibarr im Demo-Modus. API-Key in den Einstellungen hinterlegen."}
    try:
        async with httpx.AsyncClient(timeout=_timeout()) as c:
            r = await c.get(f"{cfg['base']}/api/index.php/status", headers=_headers(cfg))
            r.raise_for_status()
            return {"connected": True, "demo": False, "message": "Verbindung erfolgreich."}
    except Exception as exc:  # noqa: BLE001
        logger.warning("Dolibarr connection failed: %s", type(exc).__name__)
        return {"connected": False, "demo": False, "message": "Dolibarr ist derzeit nicht erreichbar."}


def _normalize(p: dict) -> dict:
    return {
        "dolibarr_product_id": str(p.get("id", "")), "ref": p.get("ref", ""),
        "label": p.get("label", ""), "description": p.get("description", "") or "",
        "price": float(p.get("price", 0) or 0), "price_ttc": float(p.get("price_ttc", 0) or 0),
        "vat_rate": p.get("tva_tx", ""), "stock": p.get("stock_reel"),
        "status": p.get("status"), "updated_at": now_utc(),
    }


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
        async with httpx.AsyncClient(timeout=_timeout()) as c:
            r = await c.get(f"{cfg['base']}/api/index.php/products",
                            headers=_headers(cfg), params={"limit": 200, "sortfield": "t.ref"})
            r.raise_for_status(); products = r.json()
        count = 0
        for p in products:
            n = _normalize(p)
            await db.dolibarr_product_cache.update_one(
                {"dolibarr_product_id": n["dolibarr_product_id"]}, {"$set": n}, upsert=True)
            count += 1
        log = {"type": "product_sync", "status": "success", "demo": False,
               "message": f"{count} Produkte synchronisiert.", "updated_count": count,
               "started_at": started, "finished_at": now_utc()}
    except Exception as exc:  # noqa: BLE001
        logger.error("Dolibarr sync error: %s", type(exc).__name__)
        log = {"type": "product_sync", "status": "error", "demo": False,
               "message": "Synchronisierung fehlgeschlagen.", "updated_count": 0,
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
            "country_code": os.environ.get("DOLIBARR_COUNTRY_CODE", "AT"),
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
        async with httpx.AsyncClient(timeout=_timeout()) as client:
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

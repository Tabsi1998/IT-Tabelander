"""Dolibarr REST API integration layer.

The frontend never talks to Dolibarr directly. All access is server-side,
with caching, timeouts and graceful degradation. When DOLIBARR_ENABLED is
false (or no API key is set), the layer runs in DEMO mode: it returns clearly
flagged placeholder data and records demo sync runs, so the public website
keeps working until a real API key is configured in the admin/env.
"""
import logging
import os

import httpx

from .db import get_db, now_utc

logger = logging.getLogger("dolibarr")


def is_enabled() -> bool:
    return os.environ.get("DOLIBARR_ENABLED", "false").lower() == "true" and bool(
        os.environ.get("DOLIBARR_API_KEY", "").strip()
    )


def _base_url() -> str:
    return os.environ.get("DOLIBARR_BASE_URL", "").rstrip("/")


def _headers() -> dict:
    return {
        "DOLAPIKEY": os.environ.get("DOLIBARR_API_KEY", ""),
        "Accept": "application/json",
    }


def _timeout() -> float:
    try:
        return float(os.environ.get("DOLIBARR_TIMEOUT_SECONDS", "8"))
    except ValueError:
        return 8.0


async def test_connection() -> dict:
    if not is_enabled():
        return {"connected": False, "demo": True,
                "message": "Dolibarr ist im Demo-Modus. API-Key in den Einstellungen hinterlegen."}
    try:
        async with httpx.AsyncClient(timeout=_timeout()) as client:
            r = await client.get(f"{_base_url()}/api/index.php/status", headers=_headers())
            r.raise_for_status()
            return {"connected": True, "demo": False, "message": "Verbindung erfolgreich."}
    except Exception as exc:  # noqa: BLE001
        logger.warning("Dolibarr connection failed: %s", type(exc).__name__)
        return {"connected": False, "demo": False,
                "message": "Dolibarr ist derzeit nicht erreichbar."}


async def fetch_products(limit: int = 100) -> list:
    async with httpx.AsyncClient(timeout=_timeout()) as client:
        r = await client.get(
            f"{_base_url()}/api/index.php/products",
            headers=_headers(),
            params={"limit": limit, "sortfield": "t.ref", "sortorder": "ASC"},
        )
        r.raise_for_status()
        return r.json()


def _normalize(p: dict) -> dict:
    return {
        "dolibarr_product_id": str(p.get("id", "")),
        "ref": p.get("ref", ""),
        "label": p.get("label", ""),
        "description": p.get("description", "") or "",
        "price": float(p.get("price", 0) or 0),
        "price_ttc": float(p.get("price_ttc", 0) or 0),
        "vat_rate": p.get("tva_tx", ""),
        "stock": p.get("stock_reel"),
        "status": p.get("status"),
        "updated_at": now_utc(),
    }


async def sync_products() -> dict:
    """Sync Dolibarr products into the local cache collection. Returns a log dict."""
    db = get_db()
    started = now_utc()
    if not is_enabled():
        log = {
            "type": "product_sync", "status": "demo", "demo": True,
            "message": "Demo-Sync: Kein Dolibarr-API-Key konfiguriert. Es wurden keine echten Produkte geladen.",
            "updated_count": 0, "started_at": started, "finished_at": now_utc(),
        }
        await db.sync_logs.insert_one(dict(log))
        return log
    try:
        products = await fetch_products()
        count = 0
        for p in products:
            norm = _normalize(p)
            await db.dolibarr_product_cache.update_one(
                {"dolibarr_product_id": norm["dolibarr_product_id"]},
                {"$set": norm}, upsert=True,
            )
            count += 1
        log = {
            "type": "product_sync", "status": "success", "demo": False,
            "message": f"{count} Produkte synchronisiert.",
            "updated_count": count, "started_at": started, "finished_at": now_utc(),
        }
    except Exception as exc:  # noqa: BLE001
        logger.error("Dolibarr sync error: %s", type(exc).__name__)
        log = {
            "type": "product_sync", "status": "error", "demo": False,
            "message": "Synchronisierung fehlgeschlagen. Dolibarr nicht erreichbar oder API-Key ungültig.",
            "updated_count": 0, "started_at": started, "finished_at": now_utc(),
        }
    await db.sync_logs.insert_one(dict(log))
    return log


async def create_ticket_for_repair(repair: dict) -> dict:
    """Best-effort: create a Dolibarr ticket for a repair request.
    Never raises to the caller; returns a status dict."""
    if not is_enabled():
        return {"created": False, "demo": True, "ref": None}
    try:
        c = repair.get("contact", {})
        payload = {
            "subject": f"Reparaturanfrage: {repair.get('device_type', 'Gerät')}",
            "message": repair.get("description", ""),
            "type_code": "COM_REQUEST",
            "category_code": "OTHER",
            "severity_code": "NORMAL",
            "email": c.get("email", ""),
        }
        async with httpx.AsyncClient(timeout=_timeout()) as client:
            r = await client.post(f"{_base_url()}/api/index.php/tickets",
                                  headers=_headers(), json=payload)
            r.raise_for_status()
            return {"created": True, "demo": False, "ref": r.json()}
    except Exception as exc:  # noqa: BLE001
        logger.warning("Dolibarr ticket creation failed: %s", type(exc).__name__)
        return {"created": False, "demo": False, "ref": None}

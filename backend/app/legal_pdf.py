"""The legal texts as PDF to download (#128).

The Dolibarr module "IT-Tabelander PDF" lays each public legal text out in
the design of the documents and files it in Dolibarr's documents (ECM) as
IT-Tabelander/Unterlagen/<slug>.pdf. The website fetches exactly these files
through the API (documents/download, modulepart ecm) and hands them on.

Only the fixed list below is ever asked for: the path never comes from the
request. A page that is missing or still a draft has no PDF (the router
checks that). The copy is kept for ten minutes, a missing file as well.
"""
import asyncio
import base64
import binascii
import logging
from datetime import timedelta

import httpx

from . import dolibarr
from .db import now_utc

logger = logging.getLogger("it-tabelander.legal-pdf")

FOLDER = "IT-Tabelander/Unterlagen"
# Legal page -> file name in the folder; the same names the Dolibarr module uses.
SLUGS = {
    "impressum": "impressum-ergaenzung",
    "datenschutz": "datenschutz",
    "nutzungsbedingungen": "nutzungsbedingungen",
    "widerruf": "widerruf",
    "agb-unternehmer": "agb-unternehmer",
}
MAX_BYTES = 10 * 1024 * 1024
CACHE_TTL = timedelta(minutes=10)
RIGHT_HINT = "Dem Website-Benutzer in Dolibarr das Recht „Dokumente (ECM): lesen/herunterladen“ geben."

_cache: dict[str, tuple] = {}  # kind -> (fetched_at, bytes or None)
_problems: dict[str, str] = {}  # kind -> what the owner has to change, for the admin
_lock = asyncio.Lock()


def file_name(kind: str) -> str:
    """The name the visitor's browser saves."""
    return f"IT-Tabelander-{SLUGS[kind]}.pdf"


def _pdf(payload: dict) -> bytes | None:
    """The file from Dolibarr's answer, if it is a PDF of sensible size."""
    try:
        content = base64.b64decode(str(payload.get("content") or ""), validate=True)
    except (binascii.Error, ValueError):
        return None
    if not content.startswith(b"%PDF-") or len(content) > MAX_BYTES:
        return None
    return content


async def _download(kind: str) -> bytes | None:
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        return None
    try:
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
            response = await client.get(
                f"{cfg['base']}/api/index.php/documents/download", headers=dolibarr._headers(cfg),
                params={"modulepart": "ecm", "original_file": f"{FOLDER}/{SLUGS[kind]}.pdf"})
    except httpx.HTTPError as exc:
        logger.warning("Legal PDF %s unavailable: %s", kind, type(exc).__name__)
        return None
    if response.status_code == 404:
        return None  # not filed (yet)
    if response.status_code in (401, 403):
        logger.warning("Legal PDF %s: Dolibarr refuses (%s)", kind, response.status_code)
        _problems[kind] = RIGHT_HINT
        return None
    if response.status_code != 200:
        logger.warning("Legal PDF %s: Dolibarr answers HTTP %s", kind, response.status_code)
        return None
    try:
        content = _pdf(response.json() or {})
    except ValueError:
        content = None
    if content is None:
        logger.warning("Legal PDF %s is no PDF or too large", kind)
    return content


async def fetch(kind: str) -> bytes | None:
    """The PDF of a legal page, or None when there is none."""
    if kind not in SLUGS:
        return None
    cached = _cache.get(kind)
    if cached and now_utc() - cached[0] < CACHE_TTL:
        return cached[1]
    async with _lock:
        cached = _cache.get(kind)
        if cached and now_utc() - cached[0] < CACHE_TTL:
            return cached[1]
        _problems.pop(kind, None)
        content = await _download(kind)
        _cache[kind] = (now_utc(), content)
        return content


async def available(kind: str) -> bool:
    return await fetch(kind) is not None


async def overview() -> dict:
    """For the admin: which page has a PDF, and what keeps one away."""
    found = {kind: await available(kind) for kind in SLUGS}
    return {"files": {kind: f"{FOLDER}/{slug}.pdf" for kind, slug in SLUGS.items()},
            "available": found, "problem": next(iter(_problems.values()), None)}


def forget() -> None:
    """Drop the copies, e.g. after the Dolibarr connection changed."""
    _cache.clear()
    _problems.clear()

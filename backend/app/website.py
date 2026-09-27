"""Serving the built website and, until milestone 4, the old admin (#54),
with the address, preview image and business data for search engines (#53).

start.sh builds both into one directory: the website's prerendered pages at
the top, the admin as admin.html with its files next to it.
"""
import html
import json
import logging
import os
import re
from pathlib import Path

from fastapi import HTTPException
from fastapi.responses import FileResponse, HTMLResponse, RedirectResponse

from .db import get_db

logger = logging.getLogger("it-tabelander.website")

# Addresses of the old multi-page website keep working (#54): search engines
# and old links land in the right place of the one-pager.
OLD_ADDRESSES = {
    "leistungen": "/#leistungen",
    "pc-reparatur": "/leistungen/pc-reparatur",
    "notebook-reparatur": "/leistungen/notebook-reparatur",
    "pc-aufruestung": "/leistungen/pc-aufruestung",
    "konsolen-reparatur": "/leistungen/konsolen-reparatur",
    "controller-reparatur": "/leistungen/controller-reparatur",
    "gaming-pc": "/leistungen/pc-bau",
    "gaming-pc-konfigurator": "/?kontakt=anfrage#kontakt",
    "ps5-controller-konfigurator": "/?kontakt=anfrage#kontakt",
    "ueber-mich": "/#ueber",
    "bewertungen": "/#bewertungen",
    "kontakt": "/?kontakt=nachricht#kontakt",
    "anfrage": "/?kontakt=anfrage#kontakt",
    "reparatur": "/?kontakt=anfrage#kontakt",
    "status": "/?kontakt=status#kontakt",
    "impressum": "/rechtliches/impressum",
    "datenschutz": "/rechtliches/datenschutz",
    "nutzungsbedingungen": "/rechtliches/nutzungsbedingungen",
    "rechtliches": "/rechtliches/impressum",
}
LEGAL_PAGES = ("impressum", "datenschutz", "nutzungsbedingungen")
DAYS = {"Montag": "Monday", "Dienstag": "Tuesday", "Mittwoch": "Wednesday", "Donnerstag": "Thursday",
        "Freitag": "Friday", "Samstag": "Saturday", "Sonntag": "Sunday"}
TIME_RANGE = re.compile(r"(\d{1,2})[:.](\d{2})\s*(?:–|-|bis)\s*(\d{1,2})[:.](\d{2})")
NO_CACHE = {"Cache-Control": "no-cache"}


def _inside(root: Path, relative: str) -> Path | None:
    candidate = (root / relative).resolve()
    return candidate if candidate == root or root in candidate.parents else None


def opening_hours_spec(hours: list[dict]) -> list[dict]:
    """"09:00–12:00, 13:00–17:00" per day as schema.org data; free text such
    as "nach Vereinbarung" is left out rather than guessed."""
    spec = []
    for item in hours or []:
        day = DAYS.get(str(item.get("day") or ""))
        if not day:
            continue
        for match in TIME_RANGE.finditer(str(item.get("hours") or "")):
            opens = f"{int(match.group(1)):02d}:{match.group(2)}"
            closes = f"{int(match.group(3)):02d}:{match.group(4)}"
            spec.append({"@type": "OpeningHoursSpecification", "dayOfWeek": day, "opens": opens, "closes": closes})
    return spec


def business_data(base: str, settings: dict, cache: dict) -> dict:
    """The company as schema.org LocalBusiness, from Dolibarr's data (#53, #74)."""
    company = cache.get("company") or {}
    data = {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        "name": company.get("name") or "IT-Tabelander",
        "url": base + "/",
        "image": base + "/brand/og-image.png",
        "logo": base + "/brand/apple-touch-icon.png",
        "description": "Reparatur, Aufrüstung und PCs nach Wunsch für Notebooks, PCs, Konsolen und Controller.",
    }
    if company.get("phone"):
        data["telephone"] = company["phone"]
    if company.get("email"):
        data["email"] = company["email"]
    address = {key: value for key, value in {
        "streetAddress": company.get("address"), "postalCode": company.get("zip"),
        "addressLocality": company.get("town"), "addressCountry": company.get("country_code"),
    }.items() if value}
    if address:
        data["address"] = {"@type": "PostalAddress", **address}
    area = str(settings.get("service_area") or "").strip()
    if area:
        data["areaServed"] = area
    hours = opening_hours_spec(cache.get("opening_hours") or [])
    if hours:
        data["openingHoursSpecification"] = hours
    links = [settings.get("google_review_url")]
    networks = company.get("socialnetworks") or {}
    if isinstance(networks, dict):
        links += [value for value in networks.values() if isinstance(value, str) and value.startswith("https://")]
    links = [link for link in links if link]
    if links:
        data["sameAs"] = links
    return data


async def seo_data() -> tuple[str, dict, dict]:
    """Base address, website settings and the cached Dolibarr copy. Only the
    copy: a page must never wait for Dolibarr."""
    base = os.environ.get("CANONICAL_BASE_URL", "https://it.tabelander.co.at")
    settings, cache = {}, {}
    try:
        db = get_db()
        settings = await db.settings.find_one({"_id": "site"}) or {}
        cache = await db.site_cache.find_one({"_id": "dolibarr_site_data"}) or {}
    except Exception as exc:  # noqa: BLE001
        logger.warning("SEO data unavailable: %s", type(exc).__name__)
    base = str(settings.get("canonical_base_url") or base).rstrip("/")
    return base, settings, cache


def with_seo(page: str, *, base: str, canonical_path: str, extra: str = "") -> str:
    url = html.escape(base + canonical_path, quote=True)
    head = (f'<link rel="canonical" href="{url}" />\n    <meta property="og:url" content="{url}" />'
            + (f"\n    {extra}" if extra else ""))
    page = page.replace('content="/brand/og-image.png"', f'content="{html.escape(base, quote=True)}/brand/og-image.png"')
    return page.replace("<!--app-head-->", head, 1)


def json_ld(data: dict) -> str:
    text = json.dumps(data, ensure_ascii=False).replace("</", "<\\/")
    return f'<script type="application/ld+json">{text}</script>'


async def respond(requested_path: str, build_dir: Path):
    """The answer for one address outside /api."""
    root = build_dir.resolve()
    clean = requested_path.strip("/")
    if clean == "admin" or clean.startswith("admin/"):
        admin = root / "admin.html"
        if not admin.is_file():
            # Before the first combined build the admin still is index.html.
            admin = root / "index.html"
        if not admin.is_file():
            raise HTTPException(status_code=503, detail="Frontend-Build ist noch nicht vorhanden")
        return FileResponse(admin, headers=NO_CACHE)
    if clean in OLD_ADDRESSES:
        return RedirectResponse(OLD_ADDRESSES[clean], status_code=301)

    candidate = _inside(root, clean)
    if candidate is None:
        raise HTTPException(status_code=404, detail="Datei nicht gefunden")
    if clean and candidate.is_file() and candidate.suffix != ".html":
        return FileResponse(candidate)

    status, canonical, extra = 200, None, ""
    page = None
    if clean == "":
        page, canonical = root / "index.html", "/"
    elif clean.startswith("rechtliches/") and clean.split("/", 1)[1] in LEGAL_PAGES:
        page, canonical = root / clean / "index.html", "/" + clean
    elif re.fullmatch(r"leistungen/[a-z0-9-]{1,80}", clean):
        # The same one-pager with one tab open: search engines index the page once.
        page, canonical = root / "index.html", "/"
    elif clean == "status/view.php":
        page, canonical, extra = root / "index.html", "/", '<meta name="robots" content="noindex" />'
    else:
        page, status = root / "404.html", 404
    if not page.is_file():
        if not (root / "index.html").is_file():
            raise HTTPException(status_code=503, detail="Frontend-Build ist noch nicht vorhanden")
        page = root / "index.html"  # an older build without prerendered pages
    text = page.read_text(encoding="utf-8")
    if canonical:
        base, settings, cache = await seo_data()
        if canonical == "/" and clean == "":
            extra = json_ld(business_data(base, settings, cache))
        text = with_seo(text, base=base, canonical_path=canonical, extra=extra)
    return HTMLResponse(text, status_code=status, headers=NO_CACHE)

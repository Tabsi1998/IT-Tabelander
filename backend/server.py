import logging
import os

from dotenv import load_dotenv

load_dotenv()

from fastapi import FastAPI  # noqa: E402
from fastapi.middleware.cors import CORSMiddleware  # noqa: E402
from fastapi.responses import PlainTextResponse, Response  # noqa: E402

from app.db import get_db  # noqa: E402
from app.seed import run_all_seeds  # noqa: E402
from app.routers import (auth, configurator, contact, dashboard,  # noqa: E402
                         dolibarr_router, faqs, media, repairs, reviews,
                         services, settings)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("it-tabelander")

app = FastAPI(title="IT-Tabelander API", version="1.0.0")

# Allow the emergent preview/prod domains, the production domain and localhost,
# with credentials so httpOnly cookie auth works. Bearer token is a fallback.
app.add_middleware(
    CORSMiddleware,
    allow_origin_regex=r"https?://(localhost(:\d+)?|.*\.emergentagent\.com|.*\.emergent\.host|it\.tabelander\.co\.at)",
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

for r in (auth, services, faqs, reviews, settings, repairs, contact,
          configurator, media, dolibarr_router, dashboard):
    app.include_router(r.router)


@app.on_event("startup")
async def startup():
    try:
        await run_all_seeds()
        logger.info("Seeding completed")
    except Exception as exc:  # noqa: BLE001
        logger.error("Seeding failed: %s", exc)


@app.get("/api/health")
async def health():
    try:
        await get_db().command("ping")
        db_ok = True
    except Exception:
        db_ok = False
    return {"status": "ok", "db": db_ok}


@app.get("/api/seo/sitemap.xml")
async def sitemap():
    base = os.environ.get("CANONICAL_BASE_URL", "https://it.tabelander.co.at").rstrip("/")
    static_paths = [
        "/", "/leistungen", "/pc-reparatur", "/notebook-reparatur", "/pc-aufruestung",
        "/konsolen-reparatur", "/controller-reparatur", "/gaming-pc",
        "/gaming-pc-konfigurator", "/ps5-controller-konfigurator", "/ueber-mich",
        "/bewertungen", "/kontakt", "/impressum", "/datenschutz",
    ]
    urls = "".join(f"<url><loc>{base}{p}</loc><changefreq>weekly</changefreq></url>" for p in static_paths)
    xml = (f'<?xml version="1.0" encoding="UTF-8"?>'
           f'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>')
    return Response(content=xml, media_type="application/xml")


@app.get("/api/seo/robots.txt", response_class=PlainTextResponse)
async def robots():
    base = os.environ.get("CANONICAL_BASE_URL", "https://it.tabelander.co.at").rstrip("/")
    return f"User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: {base}/api/seo/sitemap.xml\n"

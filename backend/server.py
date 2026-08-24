import logging
import os
from contextlib import asynccontextmanager
from pathlib import Path
from xml.sax.saxutils import escape

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent / ".env")

from fastapi import FastAPI, HTTPException, Request  # noqa: E402
from fastapi.middleware.cors import CORSMiddleware  # noqa: E402
from fastapi.responses import FileResponse, JSONResponse, PlainTextResponse, Response  # noqa: E402

from app.db import close_client, get_db  # noqa: E402
from app.seed import run_all_seeds  # noqa: E402
from app.seed_builder import seed_builder  # noqa: E402
from app.routers import (auth, builder, configurator, contact, dashboard,  # noqa: E402
                         dolibarr_router, faqs, media, repairs, reviews,
                         services, settings)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("it-tabelander")
FRONTEND_BUILD_DIR = Path(__file__).resolve().parents[1] / "frontend" / "build"


@asynccontextmanager
async def lifespan(application: FastAPI):
    application.state.startup_ready = False
    try:
        try:
            await run_all_seeds()
            await seed_builder()
            application.state.startup_ready = True
            logger.info("Seeding completed")
        except Exception as exc:  # noqa: BLE001
            logger.exception("Seeding failed: %s", exc)
        yield
    finally:
        await close_client()


app = FastAPI(title="IT-Tabelander API", version="1.0.0", lifespan=lifespan)

# Same-origin production traffic does not need CORS. These origins cover local
# development and optional explicitly configured external frontends.
cors_origins = [
    origin.strip().rstrip("/")
    for origin in os.environ.get("CORS_ORIGINS", "https://it.tabelander.co.at").split(",")
    if origin.strip()
]
app.add_middleware(
    CORSMiddleware,
    allow_origins=cors_origins,
    allow_origin_regex=r"https?://(localhost|127\.0\.0\.1)(:\d+)?",
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

for r in (auth, services, faqs, reviews, settings, repairs, contact,
          configurator, builder, media, dolibarr_router, dashboard):
    app.include_router(r.router)


@app.get("/api/health")
async def health(request: Request):
    try:
        await get_db().command("ping")
    except Exception:
        return JSONResponse(
            status_code=503,
            content={"status": "unavailable", "db": False},
        )
    if not getattr(request.app.state, "startup_ready", False):
        return JSONResponse(
            status_code=503,
            content={"status": "unavailable", "db": True},
        )
    return {"status": "ok", "db": True}


@app.get("/sitemap.xml")
@app.get("/api/seo/sitemap.xml")
async def sitemap():
    settings_doc = await get_db().settings.find_one({"_id": "site"}) or {}
    base = str(settings_doc.get("canonical_base_url") or
               os.environ.get("CANONICAL_BASE_URL", "https://it.tabelander.co.at")).rstrip("/")
    static_paths = [
        "/", "/leistungen", "/pc-reparatur", "/notebook-reparatur", "/pc-aufruestung",
        "/konsolen-reparatur", "/controller-reparatur", "/gaming-pc",
        "/gaming-pc-konfigurator", "/ps5-controller-konfigurator", "/ueber-mich",
        "/bewertungen", "/kontakt", "/impressum", "/datenschutz",
    ]
    urls = "".join(f"<url><loc>{escape(base + p)}</loc><changefreq>weekly</changefreq></url>" for p in static_paths)
    xml = (f'<?xml version="1.0" encoding="UTF-8"?>'
           f'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>')
    return Response(content=xml, media_type="application/xml")


@app.get("/robots.txt", response_class=PlainTextResponse)
@app.get("/api/seo/robots.txt", response_class=PlainTextResponse)
async def robots():
    settings_doc = await get_db().settings.find_one({"_id": "site"}) or {}
    base = str(settings_doc.get("canonical_base_url") or
               os.environ.get("CANONICAL_BASE_URL", "https://it.tabelander.co.at")).rstrip("/")
    return f"User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: {base}/sitemap.xml\n"


@app.get("/{requested_path:path}", include_in_schema=False)
async def frontend_app(requested_path: str):
    """Serve the production React build and its client-side routes."""
    if requested_path == "api" or requested_path.startswith("api/"):
        raise HTTPException(status_code=404, detail="API-Endpunkt nicht gefunden")
    build_root = FRONTEND_BUILD_DIR.resolve()
    candidate = (build_root / requested_path).resolve()
    if candidate != build_root and build_root not in candidate.parents:
        raise HTTPException(status_code=404, detail="Datei nicht gefunden")
    if requested_path and candidate.is_file():
        return FileResponse(candidate)
    index = build_root / "index.html"
    if not index.is_file():
        raise HTTPException(status_code=503, detail="Frontend-Build ist noch nicht vorhanden")
    return FileResponse(index, headers={"Cache-Control": "no-cache"})

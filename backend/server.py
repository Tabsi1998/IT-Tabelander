import asyncio
import logging
import os
import time
from collections import defaultdict, deque
from contextlib import asynccontextmanager
from pathlib import Path
from xml.sax.saxutils import escape

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent / ".env")

from fastapi import FastAPI, HTTPException, Request  # noqa: E402
from fastapi.middleware.cors import CORSMiddleware  # noqa: E402
from fastapi.responses import FileResponse, JSONResponse, PlainTextResponse, Response  # noqa: E402

from app.db import close_client, get_db, now_utc  # noqa: E402
from app.seed import run_all_seeds  # noqa: E402
from app.routers import (auth, contact, dashboard,  # noqa: E402
                         dolibarr_router, faqs, media, repairs, reviews,
                         services, settings)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("it-tabelander")
FRONTEND_BUILD_DIR = Path(__file__).resolve().parents[1] / "frontend" / "build"


class RequestBodyTooLarge(Exception):
    pass


class PublicRequestGuardMiddleware:
    """Small in-process guard for the anonymous write endpoints.

    The deployment intentionally runs one backend worker. Limits therefore
    apply consistently without another service and reset on a deliberate
    restart. Application-level field/file limits remain the final safety net.
    """

    RULES = {
        ("POST", "/api/inquiries"): (10, 60 * 60, 128 * 1024),
        ("POST", "/api/repairs"): (10, 60 * 60, 128 * 1024),
        ("POST", "/api/uploads/repair-attachment"): (
            30, 60 * 60, 9 * 1024 * 1024,
        ),
    }

    def __init__(self, application):
        self.application = application
        self.hits = defaultdict(deque)
        self.lock = asyncio.Lock()
        self.request_count = 0

    async def __call__(self, scope, receive, send):
        if scope.get("type") != "http":
            await self.application(scope, receive, send)
            return
        rule = self.RULES.get((scope.get("method"), scope.get("path")))
        if not rule:
            await self.application(scope, receive, send)
            return

        limit, window_seconds, max_body_bytes = rule
        headers = {
            key.decode("latin-1").lower(): value.decode("latin-1")
            for key, value in scope.get("headers", [])
        }
        content_length = headers.get("content-length")
        if content_length:
            try:
                parsed_length = int(content_length)
                if parsed_length < 0:
                    raise ValueError
                if parsed_length > max_body_bytes:
                    await JSONResponse(
                        status_code=413,
                        content={"detail": "Anfrage ist zu groß"},
                    )(scope, receive, send)
                    return
            except ValueError:
                await JSONResponse(
                    status_code=400,
                    content={"detail": "Ungültige Content-Length"},
                )(scope, receive, send)
                return

        client = scope.get("client")
        client_ip = client[0] if client else "unknown"
        path = scope.get("path")
        rate_group = "/api/inquiries" if path in ("/api/inquiries", "/api/repairs") else path
        key = (client_ip, rate_group)
        now = time.monotonic()
        async with self.lock:
            hits = self.hits[key]
            cutoff = now - window_seconds
            while hits and hits[0] <= cutoff:
                hits.popleft()
            if len(hits) >= limit:
                retry_after = max(1, int(window_seconds - (now - hits[0])))
                await JSONResponse(
                    status_code=429,
                    content={
                        "detail": "Zu viele Anfragen. Bitte später erneut versuchen."
                    },
                    headers={"Retry-After": str(retry_after)},
                )(scope, receive, send)
                return
            hits.append(now)
            self.request_count += 1
            if self.request_count >= 100:
                self.request_count = 0
                for stored_key, stored_hits in list(self.hits.items()):
                    while stored_hits and stored_hits[0] <= cutoff:
                        stored_hits.popleft()
                    if not stored_hits:
                        self.hits.pop(stored_key, None)

        received_bytes = 0

        async def limited_receive():
            nonlocal received_bytes
            message = await receive()
            if message.get("type") == "http.request":
                received_bytes += len(message.get("body") or b"")
                if received_bytes > max_body_bytes:
                    raise RequestBodyTooLarge
            return message

        try:
            await self.application(scope, limited_receive, send)
        except RequestBodyTooLarge:
            # Do not drain an unbounded chunked body. Closing the connection
            # prevents remaining bytes from being reused as another request.
            await JSONResponse(
                status_code=413,
                content={"detail": "Anfrage ist zu groß"},
                headers={"Connection": "close"},
            )(scope, receive, send)


async def run_attachment_maintenance(*, startup_cutoff=None) -> dict:
    totals = {"claims": 0, "deletions": 0, "expired": 0}
    for key, handler, cutoff_name in (
        ("claims", media.recover_repair_attachment_claims, "claimed_before"),
        ("deletions", media.recover_interrupted_media_deletions, "deleting_before"),
    ):
        for _batch in range(20):
            kwargs = {cutoff_name: startup_cutoff} if startup_cutoff else {}
            processed = await handler(limit=500, **kwargs)
            totals[key] += processed
            if processed < 500:
                break
    for _batch in range(20):
        processed = await media.cleanup_expired_repair_attachments(limit=500)
        totals["expired"] += processed
        if processed < 500:
            break
    return totals


async def maintenance_loop(startup_cutoff) -> None:
    first_run = True
    while True:
        try:
            if not first_run:
                totals = await run_attachment_maintenance()
                if any(totals.values()):
                    logger.info("Attachment maintenance: %s", totals)
            dolibarr_total = 0
            for _batch in range(20):
                recovered = await repairs.recover_stale_dolibarr_syncs(
                    started_before=startup_cutoff if first_run else None,
                    limit=10,
                )
                dolibarr_total += recovered
                if recovered < 10:
                    break
            if dolibarr_total:
                logger.info("Recovered %s interrupted Dolibarr syncs", dolibarr_total)
        except asyncio.CancelledError:
            raise
        except Exception as exc:  # noqa: BLE001
            logger.exception("Periodic maintenance failed: %s", exc)
        first_run = False
        await asyncio.sleep(5 * 60)


@asynccontextmanager
async def lifespan(application: FastAPI):
    application.state.startup_ready = False
    startup_cutoff = now_utc()
    maintenance_task = None
    try:
        try:
            await run_all_seeds()
            try:
                totals = await run_attachment_maintenance(startup_cutoff=startup_cutoff)
                if any(totals.values()):
                    logger.info("Startup attachment maintenance: %s", totals)
            except Exception as exc:  # noqa: BLE001
                # Runtime maintenance retries. Serving an expired unlinked
                # draft is still denied by the media route itself.
                logger.exception("Startup attachment maintenance failed: %s", exc)
            application.state.startup_ready = True
            maintenance_task = asyncio.create_task(
                maintenance_loop(startup_cutoff),
                name="it-tabelander-maintenance",
            )
            logger.info("Seeding completed")
        except Exception as exc:  # noqa: BLE001
            logger.exception("Seeding failed: %s", exc)
            raise
        yield
    finally:
        if maintenance_task:
            maintenance_task.cancel()
            try:
                await maintenance_task
            except asyncio.CancelledError:
                pass
        await close_client()


app = FastAPI(title="IT-Tabelander API", version="1.0.0", lifespan=lifespan)
app.add_middleware(PublicRequestGuardMiddleware)

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
          media, dolibarr_router, dashboard):
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
        "/anfrage", "/ueber-mich", "/bewertungen", "/kontakt", "/impressum", "/datenschutz",
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

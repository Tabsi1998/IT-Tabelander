import asyncio
from pathlib import Path

import httpx
import pytest
from bson import ObjectId
from fastapi import HTTPException
from fastapi.responses import FileResponse
from pydantic import ValidationError

import server
from app import dolibarr
from app.models import SettingsInput
from app import db as db_module
from app.routers import auth as auth_router
from app.routers import media as media_router
from app.routers import repairs as repairs_router
from app.routers import settings as settings_router
from app.routers.settings import _admin_response


def test_admin_settings_never_return_secret_values():
    result = _admin_response({
        "_id": "site",
        "company_name": "IT-Tabelander",
        "dolibarr_api_key": "dolibarr-secret",
        "google_places_api_key": "retired-secret",
    })

    assert "dolibarr_api_key" not in result
    assert result["dolibarr_api_key_configured"] is True
    # The Places key belongs to a removed feature and is never echoed (#33).
    assert "google_places_api_key" not in result
    assert "google_places_api_key_configured" not in result


def test_canonical_url_requires_http_scheme():
    with pytest.raises(ValidationError):
        SettingsInput(canonical_base_url="javascript:alert(1)")
    assert SettingsInput(canonical_base_url="https://it.tabelander.co.at/").canonical_base_url == "https://it.tabelander.co.at"


def test_dolibarr_url_accepts_lan_http_and_normalizes_api_suffix():
    settings = SettingsInput(
        dolibarr_base_url="http://192.168.2.44:8080/dolibarr/api/index.php/"
    )

    assert settings.dolibarr_base_url == "http://192.168.2.44:8080/dolibarr"


@pytest.mark.parametrize("url", [
    "ftp://erp.example.test",
    "http://user:password@erp.example.test/dolibarr",
    "https://erp.example.test/dolibarr?token=secret",
    "https://erp.example.test/dolibarr#settings",
    "http://bad host/dolibarr",
    "http://erp.example.test:invalid/dolibarr",
])
def test_dolibarr_url_rejects_unsafe_or_invalid_values(url):
    with pytest.raises(ValidationError):
        SettingsInput(dolibarr_base_url=url)


def test_only_super_admin_can_change_dolibarr_endpoint_or_key(monkeypatch):
    class SettingsCollection:
        def __init__(self):
            self.doc = {
                "_id": "site",
                "company_name": "Alt",
                "dolibarr_base_url": "http://192.168.2.10/dolibarr/api/index.php/",
                "dolibarr_api_key": "existing-secret",
            }
            self.updates = []

        async def find_one(self, *_args, **_kwargs):
            return dict(self.doc)

        async def update_one(self, _query, update, upsert=False):
            self.updates.append((update, upsert))
            self.doc.update(update.get("$set", {}))
            for field in update.get("$unset", {}):
                self.doc.pop(field, None)

    class SiteCache:
        dropped = 0

        async def delete_one(self, _query):
            SiteCache.dropped += 1

    class Database:
        settings = SettingsCollection()
        site_cache = SiteCache()

    database = Database()
    monkeypatch.setattr(settings_router, "get_db", lambda: database)

    with pytest.raises(HTTPException) as exc:
        asyncio.run(settings_router.update_settings(
            SettingsInput(dolibarr_base_url="http://192.168.2.11/dolibarr"),
            {"role": "admin"},
        ))
    assert exc.value.status_code == 403

    with pytest.raises(HTTPException) as exc:
        asyncio.run(settings_router.update_settings(
            SettingsInput(dolibarr_api_key="replacement-secret"),
            {"role": "staff"},
        ))
    assert exc.value.status_code == 403
    with pytest.raises(HTTPException) as exc:
        asyncio.run(settings_router.update_settings(
            SettingsInput(clear_dolibarr_api_key=True),
            {"role": "content_manager"},
        ))
    assert exc.value.status_code == 403
    assert database.settings.updates == []

    response = asyncio.run(settings_router.update_settings(
        SettingsInput(
            company_name="Neu",
            dolibarr_base_url="http://192.168.2.10/dolibarr",
        ),
        {"role": "admin"},
    ))
    assert response["company_name"] == "Neu"
    assert database.settings.doc["dolibarr_api_key"] == "existing-secret"
    assert "dolibarr_base_url" not in database.settings.updates[-1][0]["$set"]

    response = asyncio.run(settings_router.update_settings(
        SettingsInput(
            dolibarr_base_url="http://192.168.2.99/dolibarr",
            dolibarr_api_key="super-secret",
        ),
        {"role": "super_admin"},
    ))
    assert response["dolibarr_base_url"] == "http://192.168.2.99/dolibarr"
    assert response["dolibarr_api_key_configured"] is True


def test_sitemap_lists_inquiry_and_omits_removed_builders(monkeypatch):
    class SettingsCollection:
        async def find_one(self, *_args, **_kwargs):
            return {
                "canonical_base_url": "https://example.test",
            }

    class Database:
        settings = SettingsCollection()

    monkeypatch.setattr(server, "get_db", lambda: Database())
    response = asyncio.run(server.sitemap())
    xml = response.body.decode("utf-8")

    assert "https://example.test/anfrage" in xml
    assert "gaming-pc-konfigurator" not in xml
    assert "ps5-controller-konfigurator" not in xml


def test_removed_public_write_routes_are_not_registered():
    route_methods = {
        (route.path, method)
        for route in server.app.routes
        for method in (getattr(route, "methods", None) or set())
    }
    assert ("/api/contact", "POST") not in route_methods
    assert not any(path.startswith("/api/admin/contact") for path, _method in route_methods)
    for removed in ("/api/auth/forgot-password", "/api/auth/reset-password"):
        assert (removed, "POST") not in route_methods
    assert not any(
        path.startswith(("/api/builder", "/api/configurator"))
        for path, _method in route_methods
    )


def test_seed_failure_aborts_application_startup(monkeypatch):
    closed = []

    async def broken_seed():
        raise RuntimeError("index creation failed")

    async def close_database():
        closed.append(True)

    monkeypatch.setattr(server, "run_all_seeds", broken_seed)
    monkeypatch.setattr(server, "close_client", close_database)

    async def run():
        with pytest.raises(RuntimeError, match="index creation failed"):
            async with server.lifespan(server.app):
                raise AssertionError("startup must not reach the serving state")

    asyncio.run(run())
    assert closed == [True]


def test_frontend_spa_and_static_files_are_served(tmp_path, monkeypatch):
    (tmp_path / "index.html").write_text("SPA", encoding="utf-8")
    (tmp_path / "asset.txt").write_text("asset", encoding="utf-8")
    monkeypatch.setattr(server, "FRONTEND_BUILD_DIR", tmp_path)

    spa = asyncio.run(server.frontend_app("admin/einstellungen"))
    asset = asyncio.run(server.frontend_app("asset.txt"))

    assert isinstance(spa, FileResponse)
    assert Path(spa.path) == tmp_path / "index.html"
    assert Path(asset.path) == tmp_path / "asset.txt"
    with pytest.raises(HTTPException) as exc:
        asyncio.run(server.frontend_app("api/does-not-exist"))
    assert exc.value.status_code == 404


def test_dolibarr_permission_error_is_actionable_and_safe():
    request = httpx.Request("POST", "https://erp.example.test/api/index.php/tickets")
    response = httpx.Response(403, request=request, json={"error": {"message": "Forbidden"}})
    error = httpx.HTTPStatusError("forbidden", request=request, response=response)

    info = dolibarr._error_info(
        error,
        {"timeout": 8, "api_key": "must-not-leak"},
        "Anlegen des Tickets",
    )

    assert info["http_status"] == 403
    assert "Berechtigungen" in info["message"]
    assert "must-not-leak" not in str(info)


def test_public_request_guard_rejects_large_and_repeated_writes():
    async def run():
        accepted = []

        async def application(_scope, _receive, send):
            await _receive()
            accepted.append(True)
            await send({"type": "http.response.start", "status": 204, "headers": []})
            await send({"type": "http.response.body", "body": b""})

        guard = server.PublicRequestGuardMiddleware(application)
        guard.RULES = {("POST", "/api/inquiries"): (2, 3600, 10)}

        async def request(content_length: int | None, body: bytes = b""):
            sent = []
            headers = [] if content_length is None else [
                (b"content-length", str(content_length).encode())
            ]
            scope = {
                "type": "http",
                "method": "POST",
                "path": "/api/inquiries",
                "headers": headers,
                "client": ("192.0.2.10", 1234),
            }

            async def receive():
                return {"type": "http.request", "body": body, "more_body": False}

            async def send(message):
                sent.append(message)

            await guard(scope, receive, send)
            return sent[0]["status"]

        assert await request(11, b"x" * 11) == 413
        assert await request(None, b"x" * 11) == 413
        assert await request(10, b"x" * 10) == 204
        assert await request(10, b"x" * 10) == 429
        assert len(accepted) == 1

    asyncio.run(run())


def test_public_attachment_delete_is_bound_to_request_and_unclaimed(monkeypatch):
    media_id = ObjectId()

    class MediaCollection:
        query = None

        async def find_one_and_update(self, query, update, return_document=None):
            self.query = query
            return {
                "_id": media_id,
                "filename": "draft.webp",
                "attachment_deleting": update["$set"]["attachment_deleting"],
            }

        async def delete_one(self, query):
            class Result:
                deleted_count = 1
            return Result()

        async def update_one(self, *_args, **_kwargs):
            return None

    class Database:
        media = MediaCollection()

    removed = []
    database = Database()
    monkeypatch.setattr(media_router, "get_db", lambda: database)
    monkeypatch.setattr(media_router, "_remove_upload", removed.append)

    result = asyncio.run(media_router.delete_repair_attachment(
        str(media_id), "browser-request-12345678",
    ))

    assert result == {"ok": True}
    assert database.media.query["draft_request_id"] == "browser-request-12345678"
    assert database.media.query["attachment_claim"] == {"$exists": False}
    assert removed == ["draft.webp"]


def test_media_file_delete_failure_releases_database_claim(monkeypatch):
    media_id = ObjectId()

    class MediaCollection:
        released = None

        async def delete_one(self, _query):
            raise AssertionError("Database document must remain while file deletion fails")

        async def update_one(self, query, update):
            self.released = (query, update)

    class Database:
        media = MediaCollection()

    database = Database()
    monkeypatch.setattr(
        media_router,
        "_remove_upload",
        lambda _filename: (_ for _ in ()).throw(PermissionError("read-only filesystem")),
    )

    with pytest.raises(PermissionError, match="read-only filesystem"):
        asyncio.run(media_router._finish_media_deletion(
            database,
            {
                "_id": media_id,
                "filename": "draft.webp",
                "attachment_deleting": "delete-token",
            },
            "delete-token",
        ))

    query, update = database.media.released
    assert query == {"_id": media_id, "attachment_deleting": "delete-token"}
    assert "attachment_deleting" in update["$unset"]


def test_expired_attachment_cleanup_rechecks_free_state_atomically(monkeypatch):
    media_id = ObjectId()

    class Cursor:
        def limit(self, _limit):
            return self

        async def to_list(self, _limit):
            return [{"_id": media_id, "filename": "expired.webp"}]

    class MediaCollection:
        delete_query = None

        def find(self, _query):
            return Cursor()

        async def find_one_and_update(self, query, update, return_document=None):
            self.delete_query = query
            return {
                "_id": media_id,
                "filename": "expired.webp",
                "attachment_deleting": update["$set"]["attachment_deleting"],
            }

        async def delete_one(self, query):
            class Result:
                deleted_count = 1
            return Result()

        async def update_one(self, *_args, **_kwargs):
            return None

    class Database:
        media = MediaCollection()

    removed = []
    database = Database()
    monkeypatch.setattr(media_router, "get_db", lambda: database)
    monkeypatch.setattr(media_router, "_remove_upload", removed.append)

    count = asyncio.run(media_router.cleanup_expired_repair_attachments())

    assert count == 1
    assert database.media.delete_query["linked_at"] == {"$exists": False}
    assert database.media.delete_query["attachment_claim"] == {"$exists": False}
    assert removed == ["expired.webp"]


def test_inquiry_claims_every_attachment_before_persistence():
    media_ids = [ObjectId(), ObjectId()]

    class MediaCollection:
        queries = []

        async def find_one_and_update(self, query, update, return_document=None):
            self.queries.append((query, update, return_document))
            return {"_id": query["_id"], "url": f"/api/media/{query['_id']}.webp"}

    class Database:
        media = MediaCollection()

    database = Database()
    attachments, claimed_ids = asyncio.run(repairs_router._claim_attachments(
        database,
        [str(media_id) for media_id in media_ids],
        "browser-request-12345678",
        "claim-token",
    ))

    assert claimed_ids == media_ids
    assert [item["id"] for item in attachments] == [str(media_id) for media_id in media_ids]
    assert all(
        query["attachment_claim"] == {"$exists": False}
        and update["$set"]["attachment_claim"] == "claim-token"
        for query, update, _return_document in database.media.queries
    )


# ---------------- milestone 1 ----------------
def _call(method, path, tmp_path=None, monkeypatch=None):
    """Send one request through the ASGI app without running the lifespan."""
    async def run():
        transport = httpx.ASGITransport(app=server.app)
        async with httpx.AsyncClient(transport=transport, base_url="https://testserver") as client:
            return await client.request(method, path)
    return asyncio.run(run())


def test_mongo_client_returns_timezone_aware_datetimes(monkeypatch):
    captured = {}

    class FakeClient:
        def __init__(self, url, **kwargs):
            captured.update(kwargs)

    monkeypatch.setattr(db_module, "AsyncMongoClient", FakeClient)
    monkeypatch.setattr(db_module, "_client", None)
    monkeypatch.setenv("MONGO_URL", "mongodb://example.invalid:27017")
    db_module.get_client()
    monkeypatch.setattr(db_module, "_client", None)

    assert captured["tz_aware"] is True


def test_security_headers_and_hidden_api_docs(tmp_path, monkeypatch):
    (tmp_path / "index.html").write_text("SPA", encoding="utf-8")
    monkeypatch.setattr(server, "FRONTEND_BUILD_DIR", tmp_path)

    page = _call("GET", "/")
    head = _call("HEAD", "/")
    docs = _call("GET", "/openapi.json")

    assert page.status_code == 200 and page.text == "SPA"
    assert head.status_code == 200
    for name in ("strict-transport-security", "x-content-type-options", "referrer-policy",
                 "x-frame-options", "permissions-policy", "content-security-policy-report-only"):
        assert name in page.headers, name
    assert page.headers["x-frame-options"] == "DENY"
    assert server.app.openapi_url is None and server.app.docs_url is None
    assert docs.text == "SPA"  # the SPA shell, never the API schema


def test_unknown_api_addresses_answer_404_for_every_method():
    for method in ("GET", "POST", "PUT", "PATCH", "DELETE"):
        response = _call(method, "/api/no-such-address")
        assert response.status_code == 404, (method, response.status_code)
        assert "strict-transport-security" in response.headers


def test_public_legal_html_is_sanitized(monkeypatch):
    class SettingsCollection:
        async def find_one(self, *_args, **_kwargs):
            return {
                "impressum_html": '<p onclick="steal()">Hallo<script>alert(1)</script></p>'
                                  '<a href="javascript:alert(2)">x</a>',
                "datenschutz_html": "<h2>Daten</h2>",
            }

    class Database:
        settings = SettingsCollection()

    monkeypatch.setattr(settings_router, "get_db", lambda: Database())
    result = asyncio.run(settings_router.public_settings())

    assert "script" not in result["impressum_html"]
    assert "onclick" not in result["impressum_html"]
    assert "javascript:" not in result["impressum_html"]
    assert "Hallo" in result["impressum_html"]
    assert result["datenschutz_html"] == "<h2>Daten</h2>"


def test_image_conversion_runs_in_a_worker_thread(monkeypatch):
    used = []

    async def fake_threadpool(function, *args):
        used.append(function)
        return {"filename": "x.webp", "size": 1}

    class Upload:
        content_type = "image/png"

        async def read(self, _size):
            return b"png-bytes"

    monkeypatch.setattr(media_router, "run_in_threadpool", fake_threadpool)
    result = asyncio.run(media_router._store_image(Upload()))

    assert used == [media_router._convert_image]
    assert result["filename"] == "x.webp"


class _AttemptStore:
    """Minimal async stand-in for the login_attempts collection."""

    def __init__(self):
        self.docs = {}

    async def find_one(self, query):
        return self.docs.get(query["identifier"])

    async def update_one(self, query, update, upsert=False):
        doc = dict(self.docs.get(query["identifier"], {"identifier": query["identifier"]}))
        doc.update(update.get("$set", {}))
        for key in update.get("$unset", {}):
            doc.pop(key, None)
        self.docs[query["identifier"]] = doc


def _attempts(monkeypatch):
    store = _AttemptStore()

    class Database:
        login_attempts = store

    monkeypatch.setattr(auth_router, "get_db", lambda: Database())
    return store


def test_login_lock_expires_and_counting_starts_fresh(monkeypatch):
    from datetime import timedelta

    store = _attempts(monkeypatch)
    for _ in range(auth_router.MAX_ATTEMPTS):
        asyncio.run(auth_router._register_failure("1.2.3.4:a@b.c", auth_router.MAX_ATTEMPTS))
    with pytest.raises(HTTPException) as locked:
        asyncio.run(auth_router._check_lockout("1.2.3.4:a@b.c"))
    assert locked.value.status_code == 429

    # Fifteen minutes later the lock is over and one more mistake does not
    # lock again: counting starts from one.
    past = auth_router.now_utc() - timedelta(minutes=auth_router.LOCK_MINUTES + 1)
    store.docs["1.2.3.4:a@b.c"].update(locked_until=past, updated_at=past)
    asyncio.run(auth_router._check_lockout("1.2.3.4:a@b.c"))
    asyncio.run(auth_router._register_failure("1.2.3.4:a@b.c", auth_router.MAX_ATTEMPTS))
    assert store.docs["1.2.3.4:a@b.c"]["count"] == 1
    assert "locked_until" not in store.docs["1.2.3.4:a@b.c"]
    asyncio.run(auth_router._check_lockout("1.2.3.4:a@b.c"))


def test_email_lock_needs_many_more_failures_than_the_address_lock(monkeypatch):
    store = _attempts(monkeypatch)
    for _ in range(auth_router.MAX_ATTEMPTS):
        asyncio.run(auth_router._register_failure("email:owner@b.c", auth_router.MAX_ATTEMPTS_PER_EMAIL))
    asyncio.run(auth_router._check_lockout("email:owner@b.c"))
    assert store.docs["email:owner@b.c"]["count"] == auth_router.MAX_ATTEMPTS
    assert auth_router.MAX_ATTEMPTS_PER_EMAIL >= 4 * auth_router.MAX_ATTEMPTS


def test_login_response_never_contains_the_token():
    source = Path(auth_router.__file__).read_text(encoding="utf-8")
    login_body = source.split("async def login", 1)[1].split("@router", 1)[0]
    assert '"access_token"' not in login_body
    assert "update_backend_env" not in source


def test_customer_photos_are_only_served_to_their_draft_or_the_admin(tmp_path, monkeypatch):
    (tmp_path / "draft.webp").write_bytes(b"x")
    (tmp_path / "linked.webp").write_bytes(b"y")
    docs = {
        "draft.webp": {"filename": "draft.webp", "kind": "repair_attachment",
                       "draft_request_id": "browser-1234"},
        "linked.webp": {"filename": "linked.webp", "kind": "repair_attachment",
                        "linked_at": "2026-09-27"},
    }

    class Media:
        async def find_one(self, query):
            return docs.get(query["filename"])

    class Database:
        media = Media()

    async def not_admin(_request):
        return False

    monkeypatch.setattr(media_router, "UPLOAD_DIR", str(tmp_path))
    monkeypatch.setattr(media_router, "get_db", lambda: Database())
    monkeypatch.setattr(media_router, "_is_admin", not_admin)

    own = asyncio.run(media_router.serve_media("draft.webp", None, request_id="browser-1234"))
    assert isinstance(own, FileResponse)
    for name, request_id in (("draft.webp", None), ("draft.webp", "someone-else"), ("linked.webp", None)):
        with pytest.raises(HTTPException) as denied:
            asyncio.run(media_router.serve_media(name, None, request_id=request_id))
        assert denied.value.status_code == 404

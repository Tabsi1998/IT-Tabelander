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
from app import runtime_env
from app.routers import media as media_router
from app.routers import repairs as repairs_router
from app.routers import settings as settings_router
from app.routers.settings import _admin_response
from dotenv import dotenv_values


def test_admin_settings_never_return_secret_values():
    result = _admin_response({
        "_id": "site",
        "company_name": "IT-Tabelander",
        "dolibarr_api_key": "dolibarr-secret",
        "google_places_api_key": "google-secret",
    })

    assert "dolibarr_api_key" not in result
    assert "google_places_api_key" not in result
    assert result["dolibarr_api_key_configured"] is True
    assert result["google_places_api_key_configured"] is True


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

    class Database:
        settings = SettingsCollection()

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


def test_admin_credentials_are_written_atomically(tmp_path, monkeypatch):
    env_path = tmp_path / ".env"
    env_path.write_text("ADMIN_EMAIL=old@example.test\nADMIN_PASSWORD=old\n", encoding="utf-8")
    monkeypatch.setattr(runtime_env, "BACKEND_ENV_PATH", env_path)
    monkeypatch.setenv("ADMIN_EMAIL", "old@example.test")
    monkeypatch.setenv("ADMIN_PASSWORD", "old")

    runtime_env.update_backend_env({
        "ADMIN_EMAIL": "new@example.test",
        "ADMIN_PASSWORD": "new password with spaces",
    })

    values = dotenv_values(env_path)
    assert values["ADMIN_EMAIL"] == "new@example.test"
    assert values["ADMIN_PASSWORD"] == "new password with spaces"


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

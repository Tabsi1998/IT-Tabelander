import asyncio
from pathlib import Path

import httpx
import pytest
from fastapi import HTTPException
from fastapi.responses import FileResponse
from pydantic import ValidationError

import server
from app import dolibarr
from app.models import SettingsInput
from app import runtime_env
from app.routers.builder import _public_controller
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
    request = httpx.Request("GET", "https://erp.example.test/api/index.php/products")
    response = httpx.Response(403, request=request, json={"error": {"message": "Forbidden"}})
    error = httpx.HTTPStatusError("forbidden", request=request, response=response)

    info = dolibarr._error_info(
        error,
        {"timeout": 8, "api_key": "must-not-leak"},
        "Produktsync",
    )

    assert info["http_status"] == 403
    assert "Produkte/Dienstleistungen lesen" in info["message"]
    assert "must-not-leak" not in str(info)


def test_dolibarr_product_sync_paginates_and_accepts_rowid():
    async def run():
        async def handler(request):
            page = int(request.url.params["page"])
            start = page * dolibarr.PRODUCT_PAGE_SIZE
            count = dolibarr.PRODUCT_PAGE_SIZE if page == 0 else 1
            return httpx.Response(200, json=[
                {"rowid": start + index + 1, "ref": f"P-{start + index + 1}", "price": "invalid"}
                for index in range(count)
            ])

        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            return await dolibarr._fetch_products(client, {
                "base": "https://erp.example.test",
                "api_key": "secret",
            })

    products = asyncio.run(run())
    normalized = dolibarr._normalize(products[-1])
    assert len(products) == dolibarr.PRODUCT_PAGE_SIZE + 1
    assert normalized["dolibarr_product_id"] == str(dolibarr.PRODUCT_PAGE_SIZE + 1)
    assert normalized["price"] == 0.0


def test_public_controller_does_not_expose_bdm_revisions():
    result = _public_controller({
        "key": "dualsense",
        "name": "PS5 DualSense",
        "versions": [{"code": "BDM-030", "label": "BDM-030"}],
    })

    assert "versions" not in result

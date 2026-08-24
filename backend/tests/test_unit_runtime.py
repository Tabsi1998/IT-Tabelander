import asyncio
from pathlib import Path

import pytest
from fastapi import HTTPException
from fastapi.responses import FileResponse
from pydantic import ValidationError

import server
from app.models import SettingsInput
from app import runtime_env
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

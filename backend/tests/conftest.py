import asyncio
import os
import re
import secrets
import uuid
from pathlib import Path
from urllib.parse import urlparse

import pytest
import requests
from dotenv import dotenv_values
from pymongo import AsyncMongoClient

from app.db import now_utc
from app.security import hash_password

REPO_ROOT = Path(__file__).resolve().parents[2]
FRONTEND_ENV_PATH = REPO_ROOT / "frontend" / ".env"
BACKEND_ENV_PATH = REPO_ROOT / "backend" / ".env"

frontend_env = dotenv_values(FRONTEND_ENV_PATH)
BASE_URL = (
    os.environ.get("REACT_APP_BACKEND_URL")
    or frontend_env.get("REACT_APP_BACKEND_URL")
    or "http://localhost:8001"
).rstrip("/")

INTEGRATION_FILES = {"test_api.py", "test_regression_iter2.py"}
# Scenarios against a real Dolibarr; they also need the integration settings.
DOLIBARR_FILES = {"test_dolibarr_runtime.py"}


def _test_database_env():
    """Use explicitly selected test services without editing backend/.env."""
    env = dotenv_values(BACKEND_ENV_PATH)
    for key in ("MONGO_URL", "DB_NAME"):
        if key in os.environ:
            env[key] = os.environ[key]
    return env


def _integration_safety_error() -> str | None:
    if os.environ.get("IT_TABELANDER_RUN_INTEGRATION") != "1":
        return "Integrationstests benötigen IT_TABELANDER_RUN_INTEGRATION=1"
    host = (urlparse(BASE_URL).hostname or "").lower()
    if host not in {"localhost", "127.0.0.1", "::1"}:
        return "Integrationstests sind nur gegen localhost erlaubt"
    database_name = str(_test_database_env().get("DB_NAME") or "")
    if "test" not in database_name.lower():
        return "Integrationstests benötigen eine DB_NAME mit 'test' im Namen"
    return None


def pytest_collection_modifyitems(items):
    safety_error = _integration_safety_error()
    dolibarr_error = safety_error
    if not dolibarr_error and os.environ.get("IT_TABELANDER_RUN_DOLIBARR") != "1":
        dolibarr_error = "Dolibarr-Szenarien benötigen IT_TABELANDER_RUN_DOLIBARR=1 und einen Test-Dolibarr"
    for item in items:
        name = Path(str(item.fspath)).name
        if name in INTEGRATION_FILES and safety_error:
            item.add_marker(pytest.mark.skip(reason=safety_error))
        if name in DOLIBARR_FILES and dolibarr_error:
            item.add_marker(pytest.mark.skip(reason=dolibarr_error))


@pytest.fixture(scope="session")
def base_url():
    return BASE_URL


@pytest.fixture(scope="session")
def test_credentials():
    env = _test_database_env()
    safety_error = _integration_safety_error()
    if safety_error:
        pytest.skip(safety_error)
    email = f"integration-{uuid.uuid4().hex}@example.com"
    password = f"Test-{secrets.token_urlsafe(18)}"

    async def create_test_admin():
        client = AsyncMongoClient(env["MONGO_URL"])
        try:
            await client[env["DB_NAME"]].users.insert_one({
                "email": email,
                "password_hash": hash_password(password),
                "name": "Integration Test Admin",
                "role": "super_admin",
                "created_at": now_utc(),
            })
        finally:
            await client.close()

    async def remove_test_admin():
        client = AsyncMongoClient(env["MONGO_URL"])
        try:
            database = client[env["DB_NAME"]]
            await database.users.delete_one({"email": email})
            await database.login_attempts.delete_many({
                "identifier": {"$regex": re.escape(email)}
            })
        finally:
            await client.close()

    asyncio.run(create_test_admin())
    yield {"email": email, "password": password}
    asyncio.run(remove_test_admin())


@pytest.fixture(scope="session")
def api_client():
    s = requests.Session()
    s.headers.update({"Content-Type": "application/json"})
    return s


@pytest.fixture(scope="session")
def admin_client(test_credentials):
    """A logged-in session: the login only sets httpOnly cookies (#32)."""
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
    if r.status_code != 200:
        pytest.fail(f"Admin login failed {r.status_code}: {r.text[:400]}")
    if "access_token" not in s.cookies:
        pytest.fail("Login did not set the access_token cookie")
    return s

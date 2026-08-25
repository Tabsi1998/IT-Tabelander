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


def _integration_safety_error() -> str | None:
    if os.environ.get("IT_TABELANDER_RUN_INTEGRATION") != "1":
        return "Integrationstests benötigen IT_TABELANDER_RUN_INTEGRATION=1"
    host = (urlparse(BASE_URL).hostname or "").lower()
    if host not in {"localhost", "127.0.0.1", "::1"}:
        return "Integrationstests sind nur gegen localhost erlaubt"
    database_name = str(dotenv_values(BACKEND_ENV_PATH).get("DB_NAME") or "")
    if "test" not in database_name.lower():
        return "Integrationstests benötigen eine DB_NAME mit 'test' im Namen"
    return None


def pytest_collection_modifyitems(items):
    safety_error = _integration_safety_error()
    if not safety_error:
        return
    skip = pytest.mark.skip(reason=safety_error)
    for item in items:
        if Path(str(item.fspath)).name in INTEGRATION_FILES:
            item.add_marker(skip)


@pytest.fixture(scope="session")
def base_url():
    return BASE_URL


@pytest.fixture(scope="session")
def test_credentials():
    env = dotenv_values(BACKEND_ENV_PATH)
    safety_error = _integration_safety_error()
    if safety_error:
        pytest.skip(safety_error)
    email = f"integration-{uuid.uuid4().hex}@example.test"
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
def auth_token(test_credentials):
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
    if r.status_code != 200:
        pytest.fail(f"Admin login failed {r.status_code}: {r.text[:400]}")
    tok = r.json().get("access_token")
    if not tok:
        pytest.fail("No access_token in login response")
    return tok


@pytest.fixture(scope="session")
def admin_client(auth_token):
    s = requests.Session()
    s.headers.update({"Content-Type": "application/json",
                      "Authorization": f"Bearer {auth_token}"})
    return s

import os
from pathlib import Path

import pytest
import requests
from dotenv import dotenv_values

REPO_ROOT = Path(__file__).resolve().parents[2]
FRONTEND_ENV_PATH = REPO_ROOT / "frontend" / ".env"
BACKEND_ENV_PATH = REPO_ROOT / "backend" / ".env"

frontend_env = dotenv_values(FRONTEND_ENV_PATH)
BASE_URL = (
    os.environ.get("REACT_APP_BACKEND_URL")
    or frontend_env.get("REACT_APP_BACKEND_URL")
    or "http://localhost:8001"
).rstrip("/")


@pytest.fixture(scope="session")
def base_url():
    return BASE_URL


@pytest.fixture(scope="session")
def test_credentials():
    env = dotenv_values(BACKEND_ENV_PATH)
    email = str(env.get("ADMIN_EMAIL") or "").strip()
    password = str(env.get("ADMIN_PASSWORD") or "").strip()
    if not email or not password:
        pytest.skip(f"ADMIN_EMAIL/ADMIN_PASSWORD fehlen in {BACKEND_ENV_PATH}")
    return {"email": email, "password": password}


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

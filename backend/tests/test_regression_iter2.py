"""Iteration-2 regression tests for lockout, admin ID guards, and authentication."""
import os
import re
import uuid
from pathlib import Path

import pytest
import requests
from dotenv import dotenv_values

REPO_ROOT = Path(__file__).resolve().parents[2]
BACKEND_ENV_PATH = REPO_ROOT / "backend" / ".env"
FRONTEND_ENV_PATH = REPO_ROOT / "frontend" / ".env"

frontend_env = dotenv_values(FRONTEND_ENV_PATH)
BASE_URL = (
    os.environ.get("REACT_APP_BACKEND_URL")
    or frontend_env.get("REACT_APP_BACKEND_URL")
    or "http://localhost:8001"
).rstrip("/")

FAKE_OID = "0123456789abcdef01234567"   # valid ObjectId format, non-existent
BAD_OID = "not-an-objectid"


# ---------------- REGRESSION FIX 2: lockout returns 429, never 500 ----------------
class TestLockout:
    def test_isolated_test_admin_lockout_then_recovery(self, test_credentials):
        """5 wrong passwords -> 401s, 6th -> 429 (not 500). Then clear attempts and login works."""
        import asyncio
        from pymongo import AsyncMongoClient

        email = test_credentials["email"]

        async def _clear():
            env = dotenv_values(BACKEND_ENV_PATH)
            cli = AsyncMongoClient(env["MONGO_URL"])
            try:
                res = await cli[env["DB_NAME"]].login_attempts.delete_many({
                    "identifier": {"$regex": re.escape(email)}
                })
                return res.deleted_count
            finally:
                await cli.close()

        codes = []
        cleared = 0
        try:
            for _ in range(5):
                r = requests.post(f"{BASE_URL}/api/auth/login",
                                  json={"email": email, "password": "DefinitelyWrong123!"}, timeout=30)
                codes.append(r.status_code)
            r6 = requests.post(f"{BASE_URL}/api/auth/login",
                               json={"email": email, "password": "DefinitelyWrong123!"}, timeout=30)
            codes.append(r6.status_code)
            assert 500 not in codes, f"500 returned during lockout flow: {codes}"
            assert codes[:5] == [401] * 5, codes
            assert codes[5] == 429, f"expected 429 lockout, got {codes}"

            # While locked, even the CORRECT password must be blocked with 429 (not 500)
            rl = requests.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
            assert rl.status_code == 429, f"expected 429 while locked, got {rl.status_code}"
        finally:
            # Always clear attempts so a failed assertion/request cannot leave the admin locked.
            cleared = asyncio.run(_clear())

        assert cleared >= 1

        ok = requests.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
        assert ok.status_code == 200, f"admin login broken after cleanup: {ok.status_code} {ok.text[:200]}"


# ---------------- Auth playbook checks ----------------
class TestAuthPlaybook:
    def test_bcrypt_hash_format(self, test_credentials):
        import asyncio
        from pymongo import AsyncMongoClient

        async def _get():
            env = dotenv_values(BACKEND_ENV_PATH)
            cli = AsyncMongoClient(env["MONGO_URL"])
            try:
                return await cli[env["DB_NAME"]].users.find_one(
                    {"email": test_credentials["email"]}
                )
            finally:
                await cli.close()
        user = asyncio.run(_get())
        assert user is not None, "admin user not seeded"
        assert user["password_hash"].startswith("$2b$"), user["password_hash"][:10]

    def test_login_sets_httponly_cookies(self, test_credentials):
        r = requests.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
        assert r.status_code == 200
        raw = "; ".join(v for k, v in r.headers.items() if k.lower() == "set-cookie")
        combined = raw or str(r.raw.headers.getlist("Set-Cookie"))
        assert "access_token" in combined and "refresh_token" in combined, combined[:300]
        assert combined.lower().count("httponly") >= 2, combined[:300]

    def test_cors_allows_credentials_with_origin(self):
        """Verified against the app itself (ingress intercepts OPTIONS and answers with '*')."""
        origin = frontend_env.get("REACT_APP_BACKEND_URL") or BASE_URL
        r = requests.options("http://localhost:8001/api/auth/login",
                             headers={"Origin": origin,
                                      "Access-Control-Request-Method": "POST"}, timeout=30)
        assert r.status_code in (200, 204), r.status_code
        assert r.headers.get("access-control-allow-credentials") == "true"
        assert r.headers.get("access-control-allow-origin") == origin


# ---------------- Admin id validation: 404 unknown / 400 malformed ----------------
@pytest.mark.parametrize("resource", ["services", "faqs", "reviews"])
class TestAdminIdGuards:
    PAYLOADS = {
        "services": {"title": "TEST_x", "slug": f"test-{uuid.uuid4().hex[:6]}",
                     "short_description": "x", "description": "x", "category": "pc"},
        "faqs": {"question": "TEST_q", "answer": "a", "category": "allgemein"},
        "reviews": {"author": "TEST_a", "text": "t", "rating": 5},
    }

    def test_put_unknown_id_404(self, admin_client, resource):
        r = admin_client.put(f"{BASE_URL}/api/admin/{resource}/{FAKE_OID}",
                             json=self.PAYLOADS[resource], timeout=30)
        assert r.status_code == 404, f"{resource} PUT unknown -> {r.status_code} {r.text[:200]}"

    def test_put_malformed_id_400(self, admin_client, resource):
        r = admin_client.put(f"{BASE_URL}/api/admin/{resource}/{BAD_OID}",
                             json=self.PAYLOADS[resource], timeout=30)
        assert r.status_code in (400, 422), f"{resource} PUT malformed -> {r.status_code} {r.text[:200]}"

    def test_delete_unknown_id_404(self, admin_client, resource):
        r = admin_client.delete(f"{BASE_URL}/api/admin/{resource}/{FAKE_OID}", timeout=30)
        assert r.status_code == 404, f"{resource} DELETE unknown -> {r.status_code} {r.text[:200]}"

    def test_delete_malformed_id_400(self, admin_client, resource):
        r = admin_client.delete(f"{BASE_URL}/api/admin/{resource}/{BAD_OID}", timeout=30)
        assert r.status_code in (400, 422), f"{resource} DELETE malformed -> {r.status_code} {r.text[:200]}"

# End of regression tests.

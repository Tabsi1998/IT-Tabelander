"""Remove TEST_QA_* data created during UI testing."""
import os
from pathlib import Path

import requests
from dotenv import dotenv_values

REPO_ROOT = Path(__file__).resolve().parents[2]
BACKEND_ENV_PATH = REPO_ROOT / "backend" / ".env"
FRONTEND_ENV_PATH = REPO_ROOT / "frontend" / ".env"

frontend_env = dotenv_values(FRONTEND_ENV_PATH)
BASE = (
    os.environ.get("REACT_APP_BACKEND_URL")
    or frontend_env.get("REACT_APP_BACKEND_URL")
    or "http://localhost:8001"
).rstrip("/")
env = dotenv_values(BACKEND_ENV_PATH)

s = requests.Session()
r = s.post(f"{BASE}/api/auth/login",
           json={"email": env["ADMIN_EMAIL"], "password": env["ADMIN_PASSWORD"]}, timeout=30)
r.raise_for_status()
s.headers["Authorization"] = f"Bearer {r.json()['access_token']}"

# NOTE: admin routers expose only PUT/DELETE; listing happens through the public endpoints
for res, field in (("services", "title"), ("reviews", "author"), ("faqs", "question")):
    items = s.get(f"{BASE}/api/{res}", timeout=30).json()
    if isinstance(items, dict):
        items = items.get("items", [])
    for it in items if isinstance(items, list) else []:
        if str(it.get(field, "")).startswith("TEST_"):
            d = s.delete(f"{BASE}/api/admin/{res}/{it['id']}", timeout=30)
            print(res, it.get(field), "->", d.status_code)
print("cleanup done")

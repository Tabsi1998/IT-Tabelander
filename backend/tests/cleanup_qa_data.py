"""Remove TEST_QA_* data created during UI testing."""
import os

import requests
from dotenv import dotenv_values

BASE = (os.environ.get("REACT_APP_BACKEND_URL")
        or dotenv_values("/app/frontend/.env")["REACT_APP_BACKEND_URL"]).rstrip("/")
env = dotenv_values("/app/backend/.env")

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

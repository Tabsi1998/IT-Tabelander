"""IT-Tabelander backend API regression tests."""
import io
import uuid

import pytest
import requests
from PIL import Image

from conftest import BASE_URL


# ---------------- Health ----------------
class TestHealth:
    def test_health(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/health", timeout=30)
        assert r.status_code == 200
        assert r.json() == {"status": "ok", "db": True}

    def test_sitemap(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/seo/sitemap.xml", timeout=30)
        assert r.status_code == 200
        assert "<urlset" in r.text

    def test_robots(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/seo/robots.txt", timeout=30)
        assert r.status_code == 200
        assert "Disallow: /admin" in r.text


# ---------------- Auth ----------------
class TestAuth:
    def test_login_success_sets_cookies_and_token(self, test_credentials):
        s = requests.Session()
        r = s.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
        assert r.status_code == 200, r.text
        data = r.json()
        assert "access_token" in data and isinstance(data["access_token"], str)
        assert data["user"]["email"] == test_credentials["email"]
        assert data["user"]["role"] == "super_admin"
        assert "password_hash" not in data["user"]
        # httpOnly cookies
        cookie_header = r.headers.get("set-cookie", "")
        assert "access_token" in cookie_header
        assert "HttpOnly" in cookie_header or "httponly" in cookie_header
        # /me via cookie session
        me = s.get(f"{BASE_URL}/api/auth/me", timeout=30)
        assert me.status_code == 200
        assert me.json()["user"]["email"] == test_credentials["email"]

    def test_me_with_bearer(self, admin_client, test_credentials):
        r = admin_client.get(f"{BASE_URL}/api/auth/me", timeout=30)
        assert r.status_code == 200
        assert r.json()["user"]["email"] == test_credentials["email"]

    def test_me_unauthenticated(self, api_client):
        r = requests.get(f"{BASE_URL}/api/auth/me", timeout=30)
        assert r.status_code == 401

    def test_me_invalid_token(self):
        r = requests.get(f"{BASE_URL}/api/auth/me",
                         headers={"Authorization": "Bearer garbage.token.here"}, timeout=30)
        assert r.status_code == 401

    def test_wrong_password_401(self, test_credentials):
        r = requests.post(f"{BASE_URL}/api/auth/login",
                          json={"email": f"nobody-{uuid.uuid4().hex[:6]}@example.com",
                                "password": "WrongPass123!"}, timeout=30)
        assert r.status_code == 401
        assert "detail" in r.json()

    def test_bruteforce_lockout(self):
        email = f"lockme-{uuid.uuid4().hex[:8]}@example.com"
        codes = []
        for _ in range(6):
            r = requests.post(f"{BASE_URL}/api/auth/login",
                              json={"email": email, "password": "bad"}, timeout=30)
            codes.append(r.status_code)
        assert codes[:5] == [401] * 5, codes
        assert codes[5] == 429, f"Expected lockout 429 after 5 failures, got {codes}"

    def test_logout(self, test_credentials):
        s = requests.Session()
        s.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
        r = s.post(f"{BASE_URL}/api/auth/logout", timeout=30)
        assert r.status_code == 200 and r.json().get("ok") is True

    def test_forgot_password_no_enumeration(self):
        r = requests.post(f"{BASE_URL}/api/auth/forgot-password",
                          json={"email": "definitely-not-a-user@example.com"}, timeout=30)
        assert r.status_code == 200
        assert r.json().get("ok") is True


# ---------------- Services ----------------
class TestServices:
    created = []

    def test_public_services(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/services", timeout=30)
        assert r.status_code == 200
        data = r.json()
        assert isinstance(data, list) and len(data) >= 6
        slugs = {s["slug"] for s in data}
        assert "pc-reparatur" in slugs
        for s in data:
            assert "_id" not in s and "id" in s

    def test_get_service_by_slug(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/services/pc-reparatur", timeout=30)
        assert r.status_code == 200
        assert r.json()["slug"] == "pc-reparatur"

    def test_get_service_404(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/services/does-not-exist-xyz", timeout=30)
        assert r.status_code == 404

    def test_admin_service_requires_auth(self, api_client):
        r = requests.post(f"{BASE_URL}/api/admin/services", json={"title": "TEST_x"}, timeout=30)
        assert r.status_code == 401

    def test_service_crud(self, admin_client, api_client):
        payload = {"title": "TEST_Leistung Prüfung", "short_description": "kurz",
                   "long_description": "lang", "bullets": ["a", "b"], "sort": 99}
        r = admin_client.post(f"{BASE_URL}/api/admin/services", json=payload, timeout=30)
        assert r.status_code == 200, r.text
        doc = r.json()
        sid = doc["id"]
        assert doc["slug"] == "test-leistung-pruefung"
        # verify persisted
        g = api_client.get(f"{BASE_URL}/api/services/{doc['slug']}", timeout=30)
        assert g.status_code == 200 and g.json()["title"] == payload["title"]
        # update
        payload["title"] = "TEST_Leistung Updated"
        payload["slug"] = doc["slug"]
        u = admin_client.put(f"{BASE_URL}/api/admin/services/{sid}", json=payload, timeout=30)
        assert u.status_code == 200 and u.json()["title"] == "TEST_Leistung Updated"
        g2 = api_client.get(f"{BASE_URL}/api/services/{doc['slug']}", timeout=30)
        assert g2.json()["title"] == "TEST_Leistung Updated"
        # delete
        d = admin_client.delete(f"{BASE_URL}/api/admin/services/{sid}", timeout=30)
        assert d.status_code == 200
        assert api_client.get(f"{BASE_URL}/api/services/{doc['slug']}", timeout=30).status_code == 404


# ---------------- FAQs ----------------
class TestFaqs:
    def test_public_faqs(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/faqs", timeout=30)
        assert r.status_code == 200
        data = r.json()
        assert len(data) >= 8
        assert all("question" in f and "answer" in f for f in data)

    def test_faq_category_filter(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/faqs?category=reparatur", timeout=30)
        assert r.status_code == 200
        assert all(f["category"] == "reparatur" for f in r.json())

    def test_faq_crud(self, admin_client, api_client):
        p = {"question": "TEST_Frage?", "answer": "TEST_Antwort", "category": "test", "sort": 50}
        r = admin_client.post(f"{BASE_URL}/api/admin/faqs", json=p, timeout=30)
        assert r.status_code == 200
        fid = r.json()["id"]
        got = api_client.get(f"{BASE_URL}/api/faqs?category=test", timeout=30).json()
        assert any(f["id"] == fid for f in got)
        p["answer"] = "TEST_Antwort2"
        u = admin_client.put(f"{BASE_URL}/api/admin/faqs/{fid}", json=p, timeout=30)
        assert u.status_code == 200 and u.json()["answer"] == "TEST_Antwort2"
        assert admin_client.delete(f"{BASE_URL}/api/admin/faqs/{fid}", timeout=30).status_code == 200
        got2 = api_client.get(f"{BASE_URL}/api/faqs?category=test", timeout=30).json()
        assert not any(f["id"] == fid for f in got2)


# ---------------- Reviews ----------------
class TestReviews:
    def test_public_reviews_shape(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/reviews", timeout=30)
        assert r.status_code == 200
        d = r.json()
        assert set(["reviews", "average", "count", "google_place_configured"]).issubset(d)
        assert d["google_place_configured"] is False

    def test_review_crud_and_public_visibility(self, admin_client, api_client):
        p = {"author": "TEST_Kunde", "rating": 5, "text": "TEST_Sehr gut", "visible": True}
        r = admin_client.post(f"{BASE_URL}/api/admin/reviews", json=p, timeout=30)
        assert r.status_code == 200
        rid = r.json()["id"]
        pub = api_client.get(f"{BASE_URL}/api/reviews", timeout=30).json()
        assert any(x["id"] == rid for x in pub["reviews"])
        assert pub["count"] >= 1 and pub["average"] is not None
        # hide it
        p["visible"] = False
        u = admin_client.put(f"{BASE_URL}/api/admin/reviews/{rid}", json=p, timeout=30)
        assert u.status_code == 200 and u.json()["visible"] is False
        pub2 = api_client.get(f"{BASE_URL}/api/reviews", timeout=30).json()
        assert not any(x["id"] == rid for x in pub2["reviews"])
        assert admin_client.delete(f"{BASE_URL}/api/admin/reviews/{rid}", timeout=30).status_code == 200

    def test_review_rating_validation(self, admin_client):
        r = admin_client.post(f"{BASE_URL}/api/admin/reviews",
                              json={"author": "TEST_x", "rating": 9, "text": "t"}, timeout=30)
        assert r.status_code == 422


# ---------------- Repairs ----------------
class TestRepairs:
    def _payload(self, consent=True, honeypot=""):
        return {
            "device_type": "pc", "manufacturer": "Custom", "model": "TEST_Modell",
            "issues": ["startet nicht"], "description": "TEST_Beschreibung",
            "contact": {"name": "TEST_Max", "email": "test_max@example.com",
                        "phone": "+43123", "preferred_contact": "email"},
            "consent": consent, "honeypot": honeypot,
        }

    def test_create_repair_and_admin_flow(self, api_client, admin_client):
        r = api_client.post(f"{BASE_URL}/api/repairs", json=self._payload(), timeout=30)
        assert r.status_code == 200, r.text
        d = r.json()
        assert d["ok"] is True
        assert d["ref"].startswith("REP-") and len(d["ref"]) == 10
        rid = d["id"]
        lst = admin_client.get(f"{BASE_URL}/api/admin/repairs", timeout=30)
        assert lst.status_code == 200
        assert any(x["id"] == rid for x in lst.json())
        one = admin_client.get(f"{BASE_URL}/api/admin/repairs/{rid}", timeout=30)
        assert one.status_code == 200
        assert one.json()["status"] == "eingegangen"
        assert one.json()["contact"]["email"] == "test_max@example.com"
        # status update
        up = admin_client.patch(f"{BASE_URL}/api/admin/repairs/{rid}/status",
                                json={"status": "in_diagnose"}, timeout=30)
        assert up.status_code == 200
        assert admin_client.get(f"{BASE_URL}/api/admin/repairs/{rid}", timeout=30).json()["status"] == "in_diagnose"
        # invalid status
        bad = admin_client.patch(f"{BASE_URL}/api/admin/repairs/{rid}/status",
                                 json={"status": "nonsense"}, timeout=30)
        assert bad.status_code == 400
        # filter
        f = admin_client.get(f"{BASE_URL}/api/admin/repairs?status=in_diagnose", timeout=30)
        assert f.status_code == 200 and any(x["id"] == rid for x in f.json())
        # cleanup
        assert admin_client.delete(f"{BASE_URL}/api/admin/repairs/{rid}", timeout=30).status_code == 200
        assert admin_client.get(f"{BASE_URL}/api/admin/repairs/{rid}", timeout=30).status_code == 404

    def test_repair_without_consent_400(self, api_client):
        r = api_client.post(f"{BASE_URL}/api/repairs", json=self._payload(consent=False), timeout=30)
        assert r.status_code == 400

    def test_repair_honeypot_400(self, api_client):
        r = api_client.post(f"{BASE_URL}/api/repairs", json=self._payload(honeypot="bot"), timeout=30)
        assert r.status_code == 400

    def test_repair_invalid_email_422(self, api_client):
        p = self._payload()
        p["contact"]["email"] = "not-an-email"
        r = api_client.post(f"{BASE_URL}/api/repairs", json=p, timeout=30)
        assert r.status_code == 422

    def test_admin_repairs_requires_auth(self):
        assert requests.get(f"{BASE_URL}/api/admin/repairs", timeout=30).status_code == 401


# ---------------- Contact ----------------
class TestContact:
    def test_contact_create_and_admin(self, api_client, admin_client):
        p = {"name": "TEST_Anna", "email": "test_anna@example.com", "phone": "+43",
             "subject": "TEST_Betreff", "message": "TEST_Nachricht", "consent": True}
        r = api_client.post(f"{BASE_URL}/api/contact", json=p, timeout=30)
        assert r.status_code == 200 and r.json()["ok"] is True
        cid = r.json()["id"]
        lst = admin_client.get(f"{BASE_URL}/api/admin/contact", timeout=30)
        assert lst.status_code == 200
        item = next((x for x in lst.json() if x["id"] == cid), None)
        assert item and item["status"] == "neu" and item["message"] == "TEST_Nachricht"
        assert admin_client.patch(f"{BASE_URL}/api/admin/contact/{cid}/read", timeout=30).status_code == 200
        lst2 = admin_client.get(f"{BASE_URL}/api/admin/contact", timeout=30).json()
        assert next(x for x in lst2 if x["id"] == cid)["status"] == "gelesen"
        assert admin_client.delete(f"{BASE_URL}/api/admin/contact/{cid}", timeout=30).status_code == 200

    def test_contact_no_consent(self, api_client):
        r = api_client.post(f"{BASE_URL}/api/contact",
                            json={"name": "T", "email": "t@example.com", "message": "m",
                                  "consent": False}, timeout=30)
        assert r.status_code == 400

    def test_contact_honeypot(self, api_client):
        r = api_client.post(f"{BASE_URL}/api/contact",
                            json={"name": "T", "email": "t@example.com", "message": "m",
                                  "consent": True, "honeypot": "x"}, timeout=30)
        assert r.status_code == 400


# ---------------- Configurator ----------------
class TestConfigurator:
    def test_ps5_config(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/configurator/ps5", timeout=30)
        assert r.status_code == 200
        d = r.json()
        assert d["configurator"] == "ps5"
        assert len(d["categories"]) == 7
        keys = {c["key"] for c in d["categories"]}
        assert {"shell_front", "buttons", "special"}.issubset(keys)
        for c in d["categories"]:
            assert len(c["options"]) > 0, f"category {c['key']} has no options"
        front = next(c for c in d["categories"] if c["key"] == "shell_front")
        assert all(o.get("color_hex") for o in front["options"])

    def test_pc_config(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/configurator/pc", timeout=30)
        assert r.status_code == 200
        d = r.json()
        assert len(d["categories"]) == 9
        cpu = next(c for c in d["categories"] if c["key"] == "cpu")
        assert any(o["specs"].get("socket") == "AM5" for o in cpu["options"])
        mb = next(c for c in d["categories"] if c["key"] == "mainboard")
        assert any(o["specs"].get("socket") == "LGA1700" for o in mb["options"])

    def test_unknown_configurator(self, api_client):
        assert api_client.get(f"{BASE_URL}/api/configurator/xbox", timeout=30).status_code == 404

    def test_save_and_fetch_config(self, api_client):
        p = {"configurator": "ps5", "selections": {"shell_front": "Cyber-Orange"},
             "note": "TEST_note",
             "contact": {"name": "TEST_U", "email": "test_u@example.com"}}
        r = api_client.post(f"{BASE_URL}/api/configurator/save", json=p, timeout=30)
        assert r.status_code == 200
        cid = r.json()["config_id"]
        assert isinstance(cid, str) and len(cid) > 4
        g = api_client.get(f"{BASE_URL}/api/configurator/saved/{cid}", timeout=30)
        assert g.status_code == 200
        assert g.json()["selections"]["shell_front"] == "Cyber-Orange"
        assert g.json()["note"] == "TEST_note"

    def test_saved_config_404(self, api_client):
        assert api_client.get(f"{BASE_URL}/api/configurator/saved/zzzz-none", timeout=30).status_code == 404

    def test_admin_configurator_lists(self, admin_client):
        for ctype, n in (("ps5", 7), ("pc", 9)):
            r = admin_client.get(f"{BASE_URL}/api/admin/configurator/{ctype}/categories", timeout=30)
            assert r.status_code == 200 and len(r.json()) == n
            o = admin_client.get(f"{BASE_URL}/api/admin/configurator/{ctype}/options", timeout=30)
            assert o.status_code == 200 and len(o.json()) > 0

    def test_admin_option_crud(self, admin_client):
        p = {"configurator": "ps5", "category_key": "buttons", "name": "TEST_Option",
             "price": 12.5, "color_hex": "#ABCDEF", "sort": 900}
        r = admin_client.post(f"{BASE_URL}/api/admin/configurator/options", json=p, timeout=30)
        assert r.status_code == 200
        oid = r.json()["id"]
        pub = requests.get(f"{BASE_URL}/api/configurator/ps5", timeout=30).json()
        btn = next(c for c in pub["categories"] if c["key"] == "buttons")
        assert any(o["id"] == oid for o in btn["options"])
        p["price"] = 20.0
        u = admin_client.put(f"{BASE_URL}/api/admin/configurator/options/{oid}", json=p, timeout=30)
        assert u.status_code == 200 and u.json()["price"] == 20.0
        assert admin_client.delete(f"{BASE_URL}/api/admin/configurator/options/{oid}", timeout=30).status_code == 200


# ---------------- Dolibarr (demo mode) ----------------
class TestDolibarr:
    def test_status(self, admin_client):
        r = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/status", timeout=30)
        assert r.status_code == 200
        d = r.json()
        assert d["enabled"] is False
        assert d["connection"] is not None

    def test_sync_demo(self, admin_client):
        r = admin_client.post(f"{BASE_URL}/api/admin/dolibarr/sync", timeout=30)
        assert r.status_code == 200
        assert r.json().get("status") == "demo", r.text

    def test_products_empty(self, admin_client):
        r = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/products", timeout=30)
        assert r.status_code == 200 and isinstance(r.json(), list)

    def test_requires_auth(self):
        assert requests.get(f"{BASE_URL}/api/admin/dolibarr/status", timeout=30).status_code == 401


# ---------------- Media ----------------
def _png_bytes(color="red"):
    buf = io.BytesIO()
    Image.new("RGB", (300, 200), color).save(buf, "PNG")
    return buf.getvalue()


class TestMedia:
    def test_upload_list_serve_delete(self, auth_token):
        h = {"Authorization": f"Bearer {auth_token}"}
        files = {"file": ("test.png", _png_bytes(), "image/png")}
        r = requests.post(f"{BASE_URL}/api/admin/media", headers=h, files=files,
                          data={"alt": "TEST_alt"}, timeout=60)
        assert r.status_code == 200, r.text
        d = r.json()
        assert d["url"].startswith("/api/media/") and d["alt"] == "TEST_alt"
        mid = d["id"]
        serve = requests.get(f"{BASE_URL}{d['url']}", timeout=30)
        assert serve.status_code == 200
        assert serve.headers["content-type"] == "image/webp"
        lst = requests.get(f"{BASE_URL}/api/admin/media", headers=h, timeout=30)
        assert lst.status_code == 200 and any(m["id"] == mid for m in lst.json())
        dele = requests.delete(f"{BASE_URL}/api/admin/media/{mid}", headers=h, timeout=30)
        assert dele.status_code == 200
        assert requests.get(f"{BASE_URL}{d['url']}", timeout=30).status_code == 404

    def test_reject_non_image(self, auth_token):
        h = {"Authorization": f"Bearer {auth_token}"}
        files = {"file": ("bad.txt", b"hello", "text/plain")}
        r = requests.post(f"{BASE_URL}/api/admin/media", headers=h, files=files, timeout=30)
        assert r.status_code == 400

    def test_public_repair_attachment(self):
        files = {"file": ("attach.png", _png_bytes("blue"), "image/png")}
        r = requests.post(f"{BASE_URL}/api/uploads/repair-attachment", files=files, timeout=60)
        assert r.status_code == 200, r.text
        assert r.json()["url"].startswith("/api/media/")
        assert requests.get(f"{BASE_URL}{r.json()['url']}", timeout=30).status_code == 200

    def test_media_404(self):
        assert requests.get(f"{BASE_URL}/api/media/nonexistent.webp", timeout=30).status_code == 404

    def test_path_traversal_blocked(self):
        r = requests.get(f"{BASE_URL}/api/media/..%2F..%2Fbackend%2F.env", timeout=30)
        assert r.status_code in (400, 404), r.text[:200]


# ---------------- Settings ----------------
class TestSettings:
    def test_public_settings_no_secrets(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/settings", timeout=30)
        assert r.status_code == 200
        d = r.json()
        assert d["company_name"] == "IT-Tabelander"
        for secret in ("google_place_id", "dolibarr_api_key", "jwt_secret"):
            assert secret not in d

    def test_admin_settings_update(self, admin_client):
        cur = admin_client.get(f"{BASE_URL}/api/admin/settings", timeout=30)
        assert cur.status_code == 200
        original_phone = cur.json().get("phone") or ""
        u = admin_client.put(f"{BASE_URL}/api/admin/settings",
                             json={"phone": "+43 660 TEST"}, timeout=30)
        assert u.status_code == 200 and u.json()["phone"] == "+43 660 TEST"
        pub = requests.get(f"{BASE_URL}/api/settings", timeout=30).json()
        assert pub["phone"] == "+43 660 TEST"
        # restore
        admin_client.put(f"{BASE_URL}/api/admin/settings", json={"phone": original_phone}, timeout=30)

    def test_admin_settings_requires_auth(self):
        assert requests.get(f"{BASE_URL}/api/admin/settings", timeout=30).status_code == 401


# ---------------- Dashboard ----------------
class TestDashboard:
    def test_dashboard(self, admin_client):
        r = admin_client.get(f"{BASE_URL}/api/admin/dashboard", timeout=30)
        assert r.status_code == 200
        d = r.json()
        for k in ("new_repairs", "total_repairs", "contact_new", "active_services",
                  "ps5_options", "pc_options", "reviews_visible", "dolibarr_enabled"):
            assert k in d
        assert isinstance(d["total_repairs"], int)
        assert d["active_services"] >= 6
        assert d["ps5_options"] > 0 and d["pc_options"] > 0
        assert d["dolibarr_enabled"] is False

    def test_dashboard_requires_auth(self):
        assert requests.get(f"{BASE_URL}/api/admin/dashboard", timeout=30).status_code == 401

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
    def test_login_success_sets_cookies_only(self, test_credentials):
        s = requests.Session()
        r = s.post(f"{BASE_URL}/api/auth/login", json=test_credentials, timeout=30)
        assert r.status_code == 200, r.text
        data = r.json()
        # The token must never reach scripts, only the httpOnly cookie (#32).
        assert "access_token" not in data
        assert data["user"]["email"] == test_credentials["email"]
        assert data["user"]["role"] == "super_admin"
        assert "password_hash" not in data["user"]
        # httpOnly cookies
        cookie_header = r.headers.get("set-cookie", "")
        assert "access_token" in cookie_header
        assert "HttpOnly" in cookie_header or "httponly" in cookie_header
        assert "samesite=lax" in cookie_header.lower()
        # /me via cookie session
        me = s.get(f"{BASE_URL}/api/auth/me", timeout=30)
        assert me.status_code == 200
        assert me.json()["user"]["email"] == test_credentials["email"]

    def test_me_with_cookie_session(self, admin_client, test_credentials):
        r = admin_client.get(f"{BASE_URL}/api/auth/me", timeout=30)
        assert r.status_code == 200
        assert r.json()["user"]["email"] == test_credentials["email"]

    def test_me_unauthenticated(self, api_client):
        r = requests.get(f"{BASE_URL}/api/auth/me", timeout=30)
        assert r.status_code == 401

    def test_me_invalid_token(self):
        r = requests.get(f"{BASE_URL}/api/auth/me",
                         cookies={"access_token": "garbage.token.here"}, timeout=30)
        assert r.status_code == 401

    def test_bearer_header_is_not_accepted(self, admin_client):
        token = admin_client.cookies.get("access_token")
        r = requests.get(f"{BASE_URL}/api/auth/me",
                         headers={"Authorization": f"Bearer {token}"}, timeout=30)
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

    def test_forgot_password_is_removed(self):
        for path in ("forgot-password", "reset-password"):
            r = requests.post(f"{BASE_URL}/api/auth/{path}",
                              json={"email": "definitely-not-a-user@example.com"}, timeout=30)
            assert r.status_code == 404, (path, r.status_code)


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

    def test_faq_is_no_longer_edited_on_the_website(self, admin_client):
        """#81: the FAQ is kept in Dolibarr's knowledge base."""
        p = {"question": "TEST_Frage?", "answer": "TEST_Antwort", "category": "test", "sort": 50}
        assert admin_client.post(f"{BASE_URL}/api/admin/faqs", json=p, timeout=30).status_code == 404
        assert admin_client.delete(f"{BASE_URL}/api/admin/faqs/{uuid.uuid4().hex[:24]}", timeout=30).status_code == 404


# ---------------- Reviews ----------------
class TestReviews:
    def test_public_reviews_shape(self, api_client):
        r = api_client.get(f"{BASE_URL}/api/reviews", timeout=30)
        assert r.status_code == 200
        d = r.json()
        assert set(d) == {"reviews", "average", "count"}

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

    def test_demo_reviews_never_reach_the_public(self, admin_client, api_client):
        """#51: invented reviews are not allowed on the website."""
        p = {"author": "TEST_Demo", "rating": 5, "text": "TEST_Demo", "visible": True, "is_demo": True}
        rid = admin_client.post(f"{BASE_URL}/api/admin/reviews", json=p, timeout=30).json()["id"]
        try:
            pub = api_client.get(f"{BASE_URL}/api/reviews", timeout=30).json()
            assert not any(x["id"] == rid for x in pub["reviews"])
        finally:
            admin_client.delete(f"{BASE_URL}/api/admin/reviews/{rid}", timeout=30)

    def test_review_rating_validation(self, admin_client):
        r = admin_client.post(f"{BASE_URL}/api/admin/reviews",
                              json={"author": "TEST_x", "rating": 9, "text": "t"}, timeout=30)
        assert r.status_code == 422


# ---------------- Repairs ----------------
class TestRepairs:
    def _payload(self, consent=True, honeypot=""):
        return {
            "request_id": f"integration-{uuid.uuid4().hex}",
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
        assert d["ref"].startswith("ANF-") and len(d["ref"]) == 12
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


# ---------------- Legacy contact inbox ----------------
class TestContact:
    def test_contact_message_is_kept_until_dolibarr_takes_it(self, api_client, admin_client):
        """#42: without Dolibarr (as here) the message is stored and queued."""
        incomplete = api_client.post(
            f"{BASE_URL}/api/contact",
            json={"name": "TEST_Anna", "email": "test_anna@example.com", "message": "Test"},
            timeout=30,
        )
        assert incomplete.status_code == 422
        r = api_client.post(f"{BASE_URL}/api/contact", json={
            "request_id": f"integration-contact-{uuid.uuid4().hex}", "name": "TEST_Anna",
            "email": "test_anna@example.com", "message": "Habt ihr am Samstag offen?", "consent": True,
        }, timeout=30)
        assert r.status_code == 200, r.text
        assert r.json()["ref"].startswith("ANF-") and r.json()["dolibarr_synced"] is False
        stored = admin_client.get(f"{BASE_URL}/api/admin/inquiries/{r.json()['id']}", timeout=30).json()
        assert stored["request_type"] == "contact" and stored["auto_handover"] is True
        assert stored["queue"]["next_attempt_at"], stored
        assert admin_client.get(f"{BASE_URL}/api/admin/dolibarr/queue", timeout=30).json()["waiting"] >= 1

    def test_legacy_admin_inbox_is_removed(self, admin_client):
        assert admin_client.get(f"{BASE_URL}/api/admin/contact", timeout=30).status_code == 404


# ---------------- Removed builder APIs ----------------
class TestRemovedBuilderApis:
    def test_public_configurators_are_removed(self, api_client):
        assert api_client.get(f"{BASE_URL}/api/configurator/ps5", timeout=30).status_code == 404
        assert api_client.get(f"{BASE_URL}/api/builder/controllers", timeout=30).status_code == 404

    def test_admin_configurators_are_removed(self, admin_client):
        assert admin_client.get(f"{BASE_URL}/api/admin/configurator/pc/categories", timeout=30).status_code == 404
        assert admin_client.get(f"{BASE_URL}/api/admin/builder/controllers", timeout=30).status_code == 404


# ---------------- Dolibarr (demo mode) ----------------
class TestDolibarr:
    def test_status(self, admin_client):
        r = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/status", timeout=30)
        assert r.status_code == 200
        d = r.json()
        assert d["enabled"] is False
        assert d["connection"] is not None

        assert set(d["inquiries"]) == {"total", "synced", "pending", "failed"}

    def test_requires_auth(self):
        assert requests.get(f"{BASE_URL}/api/admin/dolibarr/status", timeout=30).status_code == 401


# ---------------- Media ----------------
def _png_bytes(color="red"):
    buf = io.BytesIO()
    Image.new("RGB", (300, 200), color).save(buf, "PNG")
    return buf.getvalue()


class TestMedia:
    def test_upload_list_serve_delete(self, admin_client):
        files = {"file": ("test.png", _png_bytes(), "image/png")}
        r = admin_client.post(f"{BASE_URL}/api/admin/media", files=files,
                              data={"alt": "TEST_alt"}, timeout=60)
        assert r.status_code == 200, r.text
        d = r.json()
        assert d["url"].startswith("/api/media/") and d["alt"] == "TEST_alt"
        mid = d["id"]
        serve = requests.get(f"{BASE_URL}{d['url']}", timeout=30)
        assert serve.status_code == 200
        assert serve.headers["content-type"] == "image/webp"
        lst = admin_client.get(f"{BASE_URL}/api/admin/media", timeout=30)
        assert lst.status_code == 200 and any(m["id"] == mid for m in lst.json())
        dele = admin_client.delete(f"{BASE_URL}/api/admin/media/{mid}", timeout=30)
        assert dele.status_code == 200
        assert requests.get(f"{BASE_URL}{d['url']}", timeout=30).status_code == 404

    def test_reject_non_image(self, admin_client):
        files = {"file": ("bad.txt", b"hello", "text/plain")}
        r = admin_client.post(f"{BASE_URL}/api/admin/media", files=files, timeout=30)
        assert r.status_code == 400

    def test_repair_attachment_only_for_its_own_draft(self, admin_client):
        """Customer photos are never public (#37): the draft sees its own
        preview, the admin sees everything, anybody else gets a 404."""
        request_id = "integration-upload-12345678"
        files = {"file": ("attach.png", _png_bytes("blue"), "image/png")}
        r = requests.post(
            f"{BASE_URL}/api/uploads/repair-attachment",
            files=files,
            data={"request_id": request_id},
            timeout=60,
        )
        assert r.status_code == 200, r.text
        uploaded = r.json()
        assert uploaded["url"].startswith("/api/media/")
        url = f"{BASE_URL}{uploaded['url']}"
        assert requests.get(url, params={"request_id": request_id}, timeout=30).status_code == 200
        assert requests.get(url, timeout=30).status_code == 404
        assert requests.get(url, params={"request_id": "integration-other-1234"}, timeout=30).status_code == 404
        assert admin_client.get(url, timeout=30).status_code == 200
        deleted = requests.delete(
            f"{BASE_URL}/api/uploads/repair-attachment/{uploaded['id']}",
            params={"request_id": request_id},
            timeout=30,
        )
        assert deleted.status_code == 200
        assert requests.get(url, params={"request_id": request_id}, timeout=30).status_code == 404

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
        for secret in ("google_place_id", "google_places_api_key", "dolibarr_api_key", "jwt_secret"):
            assert secret not in d

    def test_admin_settings_update(self, admin_client):
        cur = admin_client.get(f"{BASE_URL}/api/admin/settings", timeout=30)
        assert cur.status_code == 200
        assert "dolibarr_api_key" not in cur.json()
        assert isinstance(cur.json()["dolibarr_api_key_configured"], bool)
        assert "google_places_api_key_configured" not in cur.json()
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
        assert d["services"]["active"] >= 6
        assert d["dolibarr_enabled"] is False

    def test_dashboard_requires_auth(self):
        assert requests.get(f"{BASE_URL}/api/admin/dashboard", timeout=30).status_code == 401


# ---------------- The new admin (#57-#62) ----------------
class TestAdminMilestone4:
    def test_gallery_photo_lifecycle(self, admin_client, api_client):
        """#60: upload, thumbnail, hide, order, delete - the files go with it."""
        files = {"file": ("werkstatt.png", _png_bytes("green"), "image/png")}
        r = admin_client.post(f"{BASE_URL}/api/admin/gallery", files=files,
                              data={"caption": "TEST_Gaming-PC", "category": "pc_build", "visible": "true"}, timeout=60)
        assert r.status_code == 200, r.text
        photo = r.json()
        assert photo["category_label"] == "PC-Bau" and photo["thumb_url"] != photo["image_url"]
        assert api_client.get(f"{BASE_URL}{photo['thumb_url']}", timeout=30).status_code == 200
        public = api_client.get(f"{BASE_URL}/api/gallery", timeout=30).json()["items"]
        assert any(item["id"] == photo["id"] for item in public)

        hidden = admin_client.put(f"{BASE_URL}/api/admin/gallery/{photo['id']}", json={"visible": False}, timeout=30)
        assert hidden.status_code == 200 and hidden.json()["visible"] is False
        public = api_client.get(f"{BASE_URL}/api/gallery", timeout=30).json()["items"]
        assert not any(item["id"] == photo["id"] for item in public)
        bad = admin_client.put(f"{BASE_URL}/api/admin/gallery/{photo['id']}", json={"category": "unbekannt"}, timeout=30)
        assert bad.status_code == 422

        ids = [item["id"] for item in admin_client.get(f"{BASE_URL}/api/admin/gallery", timeout=30).json()["items"]]
        assert admin_client.post(f"{BASE_URL}/api/admin/gallery/order", json={"ids": list(reversed(ids))}, timeout=30).status_code == 200

        assert admin_client.delete(f"{BASE_URL}/api/admin/gallery/{photo['id']}", timeout=30).status_code == 200
        assert api_client.get(f"{BASE_URL}{photo['image_url']}", timeout=30).status_code == 404
        assert api_client.get(f"{BASE_URL}{photo['thumb_url']}", timeout=30).status_code == 404

    def test_gallery_is_admin_only(self, api_client):
        files = {"file": ("x.png", _png_bytes(), "image/png")}
        assert api_client.post(f"{BASE_URL}/api/admin/gallery", files=files, timeout=30).status_code == 401
        assert api_client.get(f"{BASE_URL}/api/admin/gallery", timeout=30).status_code == 401

    def test_service_edit_keeps_what_the_form_does_not_send(self, admin_client):
        """#58: a partial save never blanks other fields; the address stays."""
        created = admin_client.post(f"{BASE_URL}/api/admin/services", json={
            "title": f"TEST_Dienst {uuid.uuid4().hex[:6]}", "short_description": "bleibt", "seo_title": "bleibt auch",
        }, timeout=30).json()
        try:
            r = admin_client.put(f"{BASE_URL}/api/admin/services/{created['id']}",
                                 json={"title": "TEST_Neuer Name", "heading": "Neu", "slug": "anders"}, timeout=30)
            assert r.status_code == 200, r.text
            saved = r.json()
            assert saved["heading"] == "Neu" and saved["short_description"] == "bleibt" and saved["seo_title"] == "bleibt auch"
            assert saved["slug"] == created["slug"]
            listed = admin_client.get(f"{BASE_URL}/api/admin/services", timeout=30).json()
            order = [item["id"] for item in listed]
            order.remove(created["id"])
            order.insert(0, created["id"])
            assert admin_client.post(f"{BASE_URL}/api/admin/services/order", json={"ids": order}, timeout=30).status_code == 200
            assert admin_client.get(f"{BASE_URL}/api/admin/services", timeout=30).json()[0]["id"] == created["id"]
        finally:
            admin_client.delete(f"{BASE_URL}/api/admin/services/{created['id']}", timeout=30)

    def test_dashboard_says_what_waits_and_what_is_missing(self, admin_client):
        data = admin_client.get(f"{BASE_URL}/api/admin/dashboard", timeout=30).json()
        assert {"inquiries", "reviews", "gallery", "services", "dolibarr_enabled", "mail_configured"} <= set(data)
        assert isinstance(data["inquiries"]["waiting"], int) and isinstance(data["inquiries"]["recent"], list)

    def test_about_me_texts_reach_the_website(self, admin_client, api_client):
        r = admin_client.put(f"{BASE_URL}/api/admin/settings", json={
            "about_text": "TEST_Über mich", "about_qualifications": ["TEST_A", "TEST_A", " TEST_B "],
        }, timeout=30)
        assert r.status_code == 200, r.text
        public = api_client.get(f"{BASE_URL}/api/settings", timeout=30).json()
        assert public["about_text"] == "TEST_Über mich" and public["about_qualifications"] == ["TEST_A", "TEST_B"]
        bad = admin_client.put(f"{BASE_URL}/api/admin/settings", json={"about_photo_url": "https://fremd.example/bild.png"}, timeout=30)
        assert bad.status_code == 422
        admin_client.put(f"{BASE_URL}/api/admin/settings", json={"about_text": "", "about_qualifications": []}, timeout=30)


# ---------------- Review request and device label (#71, #72) ----------------
def _website_db():
    from pymongo import MongoClient

    from conftest import _test_database_env

    env = _test_database_env()
    return MongoClient(env["MONGO_URL"], tz_aware=True)[env["DB_NAME"]]


def _plant_invite(days_left=60):
    """A link as the mail after a closed ticket carries it; only its hash is stored."""
    import hashlib
    import secrets
    from datetime import datetime, timedelta, timezone

    token = secrets.token_urlsafe(32)
    now = datetime.now(timezone.utc)
    invite = {"token_hash": hashlib.sha256(token.encode("utf-8")).hexdigest(), "ref": "ANF-TESTBEW1",
              "request_type": "repair", "created_at": now, "expires_at": now + timedelta(days=days_left)}
    _website_db().review_invites.insert_one(invite)
    return token, invite


class TestReviewRequestAndLabel:
    def test_a_review_through_the_personal_link_waits_for_the_release(self, admin_client, api_client):
        token, invite = _plant_invite()
        review_id = None
        try:
            check = api_client.post(f"{BASE_URL}/api/review-invites/check", json={"token": token}, timeout=30)
            assert check.status_code == 200, check.text
            assert check.json() == {"ref": "ANF-TESTBEW1", "request_type_label": "Reparatur"}
            body = {"token": token, "rating": 4, "text": "TEST_Schnell und ehrlich", "author": "TEST_Max M.",
                    "publish_ok": False}
            assert api_client.post(f"{BASE_URL}/api/review-invites/submit", json=body, timeout=30).status_code == 400
            stored = api_client.post(f"{BASE_URL}/api/review-invites/submit", json={**body, "publish_ok": True}, timeout=30)
            assert stored.status_code == 200, stored.text
            # One review per link.
            assert api_client.post(f"{BASE_URL}/api/review-invites/submit", json={**body, "publish_ok": True},
                                   timeout=30).status_code == 404
            assert api_client.post(f"{BASE_URL}/api/review-invites/check", json={"token": token}, timeout=30).status_code == 404

            listed = admin_client.get(f"{BASE_URL}/api/admin/reviews", timeout=30).json()
            mine = next(item for item in listed if item["text"] == "TEST_Schnell und ehrlich")
            review_id = mine["id"]
            assert listed[0]["id"] == review_id, "a review waiting for the release comes first"
            assert mine["pending"] is True and mine["visible"] is False
            assert mine["source"] == "Website" and mine["inquiry_ref"] == "ANF-TESTBEW1"
            assert not any(item["id"] == review_id for item in api_client.get(f"{BASE_URL}/api/reviews", timeout=30).json()["reviews"])
            assert admin_client.get(f"{BASE_URL}/api/admin/dashboard", timeout=30).json()["reviews"]["pending"] >= 1

            released = admin_client.put(f"{BASE_URL}/api/admin/reviews/{review_id}", json={
                "author": mine["author"], "rating": mine["rating"], "text": mine["text"], "visible": True}, timeout=30)
            assert released.status_code == 200 and "pending" not in released.json()
            shown = next(item for item in api_client.get(f"{BASE_URL}/api/reviews", timeout=30).json()["reviews"]
                         if item["id"] == review_id)
            # A visitor sees the review, not how it is kept (order, inquiry, flags).
            assert set(shown) <= {"id", "author", "rating", "text", "source", "source_url", "review_date", "created_at"}
        finally:
            _website_db().review_invites.delete_one({"token_hash": invite["token_hash"]})
            if review_id:
                admin_client.delete(f"{BASE_URL}/api/admin/reviews/{review_id}", timeout=30)

    def test_an_expired_or_unknown_link_is_gone(self, api_client):
        token, invite = _plant_invite(days_left=-1)
        try:
            for known in (token, "x" * 43):
                check = api_client.post(f"{BASE_URL}/api/review-invites/check", json={"token": known}, timeout=30)
                assert check.status_code == 404 and "abgelaufen" in check.json()["detail"]
            submit = api_client.post(f"{BASE_URL}/api/review-invites/submit", json={
                "token": token, "rating": 5, "text": "TEST_zu spät", "author": "TEST_Eva", "publish_ok": True}, timeout=30)
            assert submit.status_code == 404
        finally:
            _website_db().review_invites.delete_one({"token_hash": invite["token_hash"]})

    def test_review_requests_are_the_admins_business(self, admin_client, api_client):
        assert api_client.get(f"{BASE_URL}/api/admin/review-invites", timeout=30).status_code == 401
        assert api_client.post(f"{BASE_URL}/api/admin/review-invites/run", timeout=30).status_code == 401
        overview = admin_client.get(f"{BASE_URL}/api/admin/review-invites", timeout=30).json()
        assert {"waiting", "sent", "answered", "last_error"} <= set(overview)
        run = admin_client.post(f"{BASE_URL}/api/admin/review-invites/run", timeout=60).json()
        assert {"checked", "sent", "stopped", "failed", "problem"} <= set(run)

    def test_the_device_label_names_the_device_and_links_to_the_status(self, admin_client, api_client):
        created = api_client.post(f"{BASE_URL}/api/inquiries", json={
            "request_id": f"integration-{uuid.uuid4().hex}", "request_type": "repair", "device_type": "notebook",
            "manufacturer": "Lenovo", "model": "ThinkPad T14", "description": "TEST_Startet nicht mehr.",
            "contact": {"name": "TEST_Eva", "email": "test_eva@example.com"}, "consent": True, "review_ok": True,
        }, timeout=60).json()
        try:
            assert admin_client.get(f"{BASE_URL}/api/admin/inquiries/{created['id']}", timeout=30).json()["review_ok"] is True
            label = admin_client.get(f"{BASE_URL}/api/admin/labels/{created['ref'].lower()}", timeout=60)
            assert label.status_code == 200, label.text
            data = label.json()
            assert data["ref"] == created["ref"] and data["request_type_label"] == "Reparatur"
            assert data["title"] == "Reparatur: Notebook Lenovo ThinkPad T14"
            assert "/status/view.php?track_id=" in data["status_url"]
            track_id = data["status_url"].split("track_id=", 1)[1]
            status = api_client.get(f"{BASE_URL}/api/inquiries/status/track/{track_id}", timeout=60)
            assert status.status_code == 200 and status.json()["ref"] == created["ref"]
            assert "TEST_Eva" not in label.text and "test_eva@example.com" not in label.text
            assert admin_client.get(f"{BASE_URL}/api/admin/labels/ANF-GIBTSNIX", timeout=30).status_code == 404
            assert api_client.get(f"{BASE_URL}/api/admin/labels/{created['ref']}", timeout=30).status_code == 401
        finally:
            admin_client.delete(f"{BASE_URL}/api/admin/inquiries/{created['id']}", timeout=30)

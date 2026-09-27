"""Milestone 2, part C: company data, legal texts, FAQ and status steps from Dolibarr."""
import asyncio
import json
import unittest.mock

import httpx

from app import dolibarr, site_data

CFG = {"base": "https://erp.example.test", "api_key": "top-secret-key", "country_code": "AT",
       "timeout": 8, "enabled": True}


def _run(coroutine_factory, handler):
    async def run():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            return await coroutine_factory(client)
    return asyncio.run(run())


def test_company_data_is_a_whitelist():
    def handler(request):
        return httpx.Response(200, json={"name": "IT-Tabelander", "zip": "6020", "note_private": "intern!",
                                         "managers": "Fabian Tabelander", "socialobject": "IT-Dienstleistungen",
                                         "idprof3": "FN 1a", "lines": []})
    company = _run(lambda client: site_data.fetch_company(client, CFG), handler)
    assert company == {"name": "IT-Tabelander", "zip": "6020", "managers": "Fabian Tabelander",
                       "socialobject": "IT-Dienstleistungen", "idprof3": "FN 1a"}


def test_opening_hours_skip_days_without_a_value():
    def handler(request):
        if request.url.path.endswith("MONDAY"):
            return httpx.Response(200, json="09:00–17:00")
        return httpx.Response(400, json={"error": {"message": "Bad or unknown value"}})
    hours = _run(lambda client: site_data.fetch_opening_hours(client, CFG), handler)
    assert hours == [{"day": "Montag", "hours": "09:00–17:00"}]


def test_articles_are_sanitized_and_obsolete_ones_dropped():
    def handler(request):
        assert request.url.params["category"] == "5"
        return httpx.Response(200, json=[
            {"id": "1", "question": "Wie lange?", "answer": "<p>Kurz</p><script>alert(1)</script>", "status": "1"},
            {"id": "2", "question": "Alt", "answer": "x", "status": "9"},
            {"id": "3", "question": "Entwurf", "answer": "<p onclick='x()'>y</p>", "status": "0"},
        ])
    articles = _run(lambda client: site_data.fetch_articles(client, CFG, 5), handler)
    assert [item["id"] for item in articles] == [1, 3]
    assert "<script" not in articles[0]["answer_html"] and "onclick" not in articles[1]["answer_html"]


def test_faq_shows_only_released_articles_that_are_no_legal_page():
    data = {"articles": [
        {"id": 1, "question": "Wie lange dauert es?", "answer_html": "<p>Ein <b>paar</b> Tage.</p>", "status": 1},
        {"id": 2, "question": "Entwurf", "answer_html": "<p>x</p>", "status": 0},
        {"id": 3, "question": "Datenschutz", "answer_html": "<p>...</p>", "status": 1},
    ]}
    entries = site_data.faq_entries(data, {"dolibarr_privacy_article_id": 3})
    assert [entry["question"] for entry in entries] == ["Wie lange dauert es?"]
    assert entries[0]["answer"] == "Ein paar Tage." and entries[0]["answer_html"] == "<p>Ein <b>paar</b> Tage.</p>"


def test_imprint_comes_from_company_data_and_escapes_it():
    body = site_data.imprint_html({
        "name": "IT-Tabelander <GmbH>", "managers": "Fabian Tabelander", "address": "Gasse 1", "zip": "6020",
        "town": "Innsbruck", "country_code": "AT", "email": "office@example.at", "tva_intra": "ATU12345678",
        "idprof3": "FN 123456a", "idprof2": "LG Innsbruck", "socialobject": "IT-Service",
    }, "<p>Aufsichtsbehörde: BH</p>")
    assert "IT-Tabelander &lt;GmbH&gt;" in body and "6020 Innsbruck" in body
    assert "UID-Nummer: ATU12345678" in body and "Firmenbuchnummer: FN 123456a" in body
    assert "Firmenbuchgericht: LG Innsbruck" in body and "Unternehmensgegenstand: IT-Service" in body
    assert body.endswith("<p>Aufsichtsbehörde: BH</p>")


def test_hints_name_the_dolibarr_setting():
    forbidden = httpx.HTTPStatusError("x", request=httpx.Request("GET", "https://x"),
                                      response=httpx.Response(403))
    assert "API_LOGINS_ALLOWED_FOR_GET_COMPANY" in site_data._hint("company", forbidden)
    assert "API_LOGINS_ALLOWED_FOR_CONST_READ" in site_data._hint("opening_hours", forbidden)
    assert "Wissensdatenbank" in site_data._hint("articles", forbidden)


def _status(ticket, proposals=None, proposal_status=200):
    def handler(request):
        if request.url.path.endswith("/proposals"):
            return httpx.Response(proposal_status, json=proposals or [])
        return httpx.Response(200, json=ticket)

    real_client = httpx.AsyncClient

    def client(**kwargs):
        return real_client(transport=httpx.MockTransport(handler), **kwargs)

    async def config():
        return CFG

    with unittest.mock.patch.object(dolibarr, "get_config", config), \
            unittest.mock.patch.object(dolibarr.httpx, "AsyncClient", client):
        return asyncio.run(dolibarr.fetch_ticket_status("501"))["step"]


def test_ready_for_pickup_and_offer_waiting_are_status_steps():
    base = {"id": "501", "status": "3", "fk_soc": "42", "origin_email": "a@b.at"}
    assert _status(base) == "in_arbeit"
    assert _status({**base, "array_options": {"options_abholbereit": "1"}}) == "abholbereit"
    offer = [{"id": "9", "linkedObjectsIds": {"ticket": {"12": "501"}}}]
    assert _status(base, offer) == "angebot_bereit"
    assert _status(base, [{"id": "9", "linkedObjectsIds": {"ticket": {"12": "777"}}}]) == "in_arbeit"
    # Without the right to read proposals the status still works.
    assert _status(base, proposal_status=403) == "in_arbeit"
    # A closed ticket stays closed, whatever the extra field says.
    assert _status({**base, "status": "8", "array_options": {"options_abholbereit": "1"}}) == "abgeschlossen"


# ---------------- new website (#50, #51) ----------------
class _Cursor:
    def __init__(self, docs):
        self.docs = docs

    def sort(self, *_args, **_kwargs):
        return self

    async def to_list(self, _limit):
        return self.docs


class _Collection:
    def __init__(self, docs):
        self.docs = docs
        self.queries = []

    def find(self, query=None, *_args):
        self.queries.append(query)
        return _Cursor(self.docs)


def test_gallery_lists_photos_with_their_area(monkeypatch):
    from bson import ObjectId

    from app.routers import gallery

    class Database:
        gallery = _Collection([
            {"_id": ObjectId(), "image_url": "/api/media/a.webp", "caption": "Gaming-PC", "category": "pc_build"},
            {"_id": ObjectId(), "image_url": "/api/media/b.webp", "category": "unknown"},
            {"_id": ObjectId(), "caption": "ohne Bild"},
        ])

    monkeypatch.setattr(gallery, "get_db", lambda: Database())
    items = asyncio.run(gallery.public_gallery())["items"]
    assert [item["category_label"] for item in items] == ["PC-Bau", "Sonstiges"]
    assert items[0]["thumb_url"] == "/api/media/a.webp" and items[1]["caption"] == ""
    assert Database.gallery.queries[0] == {"visible": {"$ne": False}}


def test_public_reviews_never_contain_demo_entries(monkeypatch):
    from bson import ObjectId

    from app.routers import reviews

    class Database:
        reviews = _Collection([{"_id": ObjectId(), "author": "Eva", "rating": 4, "text": "Gut", "visible": True}])

    monkeypatch.setattr(reviews, "get_db", lambda: Database())
    answer = asyncio.run(reviews.list_reviews())
    assert Database.reviews.queries[0] == {"visible": True, "is_demo": {"$ne": True}}
    assert answer["count"] == 1 and answer["average"] == 4.0


def test_review_and_profile_links_must_be_https():
    import pytest
    from pydantic import ValidationError

    from app.models import ReviewInput, SettingsInput

    assert ReviewInput(author="Eva", rating=5, text="Top", source="Google",
                       source_url="https://g.page/r/x", review_date="2026-09-01").source_url == "https://g.page/r/x"
    for bad in ({"source_url": "javascript:alert(1)"}, {"source_url": "http://g.page/r/x"}, {"review_date": "gestern"}):
        with pytest.raises(ValidationError):
            ReviewInput(author="Eva", rating=5, text="Top", **bad)
    assert SettingsInput(google_review_url="https://g.page/r/abc/review").google_review_url
    with pytest.raises(ValidationError):
        SettingsInput(google_review_url="https://")


# ---------------- serving the new website (#53, #54) ----------------
def _build(tmp_path):
    head = ('<head><title>T</title><meta property="og:image" content="/brand/og-image.png" />'
            "<!--app-head--></head>")
    (tmp_path / "index.html").write_text(f"<html>{head}<body>START</body></html>", encoding="utf-8")
    (tmp_path / "404.html").write_text(f"<html>{head}<body>FEHLT</body></html>", encoding="utf-8")
    (tmp_path / "admin.html").write_text("<html><body>VERWALTUNG</body></html>", encoding="utf-8")
    legal = tmp_path / "rechtliches" / "impressum"
    legal.mkdir(parents=True, exist_ok=True)
    (legal / "index.html").write_text(f"<html>{head}<body>RECHT</body></html>", encoding="utf-8")
    (tmp_path / "brand").mkdir(exist_ok=True)
    (tmp_path / "brand" / "og-image.png").write_bytes(b"png")
    return tmp_path


def _serve(monkeypatch, tmp_path, path, cache=None):
    from app import website

    async def seo():
        return "https://it.example.at", {"service_area": "Tirol", "google_review_url": "https://g.page/r/x"}, cache or {}

    monkeypatch.setattr(website, "seo_data", seo)
    return asyncio.run(website.respond(path, _build(tmp_path)))


def test_old_addresses_redirect_into_the_one_pager(monkeypatch, tmp_path):
    for old, new in (("impressum", "/rechtliches/impressum"), ("pc-reparatur", "/leistungen/pc-reparatur"),
                     ("anfrage", "/?kontakt=anfrage#kontakt"), ("ueber-mich", "/#ueber")):
        response = _serve(monkeypatch, tmp_path, old)
        assert response.status_code == 301 and response.headers["location"] == new


def test_pages_admin_files_and_unknown_addresses(monkeypatch, tmp_path):
    import pytest
    from fastapi import HTTPException

    assert b"VERWALTUNG" in open(_serve(monkeypatch, tmp_path, "admin/anfragen").path, "rb").read()
    assert str(_serve(monkeypatch, tmp_path, "brand/og-image.png").path).endswith("og-image.png")
    missing = _serve(monkeypatch, tmp_path, "gibt-es-nicht")
    assert missing.status_code == 404 and b"FEHLT" in missing.body
    assert _serve(monkeypatch, tmp_path, "admin.html").status_code == 404
    legal = _serve(monkeypatch, tmp_path, "rechtliches/impressum/")
    assert b"RECHT" in legal.body and b'href="https://it.example.at/rechtliches/impressum"' in legal.body
    service = _serve(monkeypatch, tmp_path, "leistungen/pc-reparatur")
    assert b"START" in service.body and b'rel="canonical" href="https://it.example.at/"' in service.body
    assert b'name="robots" content="noindex"' in _serve(monkeypatch, tmp_path, "status/view.php").body
    with pytest.raises(HTTPException) as outside:
        _serve(monkeypatch, tmp_path, "../../backend/.env")
    assert outside.value.status_code == 404


def test_start_page_carries_address_preview_and_business_data(monkeypatch, tmp_path):
    cache = {
        "company": {"name": "IT-Tabelander", "address": "Gasse 1", "zip": "6410", "town": "Telfs",
                    "country_code": "AT", "phone": "+43 1", "email": "office@example.at",
                    "socialnetworks": {"instagram": "https://instagram.com/it"}},
        "opening_hours": [{"day": "Montag", "hours": "09:00–12:00, 13:00-17:00"},
                          {"day": "Samstag", "hours": "nach Vereinbarung"}],
    }
    body = _serve(monkeypatch, tmp_path, "", cache).body.decode("utf-8")
    assert '<link rel="canonical" href="https://it.example.at/" />' in body
    assert 'content="https://it.example.at/brand/og-image.png"' in body
    data = json.loads(body.split('<script type="application/ld+json">')[1].split("</script>")[0])
    assert data["@type"] == "LocalBusiness" and data["address"]["addressLocality"] == "Telfs"
    assert data["areaServed"] == "Tirol" and "https://g.page/r/x" in data["sameAs"]
    assert data["openingHoursSpecification"] == [
        {"@type": "OpeningHoursSpecification", "dayOfWeek": "Monday", "opens": "09:00", "closes": "12:00"},
        {"@type": "OpeningHoursSpecification", "dayOfWeek": "Monday", "opens": "13:00", "closes": "17:00"},
    ]


def test_json_ld_cannot_close_its_script_tag():
    from app import website

    tag = website.json_ld({"name": "</script><script>alert(1)"})
    inner = tag[len('<script type="application/ld+json">'):-len("</script>")]
    assert "</" not in inner and json.loads(inner)["name"].startswith("</script>")


def test_public_pages_enforce_the_security_policy_the_admin_only_reports(monkeypatch, tmp_path):
    import server

    monkeypatch.setattr(server, "FRONTEND_BUILD_DIR", _build(tmp_path))

    async def call(path):
        transport = httpx.ASGITransport(app=server.app)
        async with httpx.AsyncClient(transport=transport, base_url="https://testserver") as client:
            return await client.get(path)

    from app import website

    async def seo():
        return "https://it.example.at", {}, {}

    monkeypatch.setattr(website, "seo_data", seo)
    public = asyncio.run(call("/"))
    admin = asyncio.run(call("/admin"))
    assert "default-src 'self'" in public.headers["content-security-policy"]
    assert "content-security-policy" not in admin.headers
    assert "content-security-policy-report-only" in admin.headers

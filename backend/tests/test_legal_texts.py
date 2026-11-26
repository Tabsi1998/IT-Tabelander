"""Rechtliches: imprint checklist, drafts of privacy policy and terms (#68, #69, #75)."""
import asyncio
import json
from datetime import timedelta

import httpx
import pytest

from app import dolibarr, legal_texts, review_invites, site_data
from app.routers import media

KINDS = ("impressum", "datenschutz", "nutzungsbedingungen")


# ------------------------------------------------------------- the drafts

def test_every_draft_marks_what_only_the_owner_knows():
    for kind in KINDS:
        text = legal_texts.draft_html(kind)
        points = legal_texts.open_points(text)
        assert points, kind
        assert all(point.startswith("[BITTE ") and point.endswith("]") for point in points), points
        # The EU platform for online disputes closed in July 2025: no link to it.
        assert "ec.europa.eu/consumers/odr" not in text


def test_the_privacy_draft_says_what_the_website_really_does():
    text = legal_texts.draft_html("datenschutz")
    for part in ("Wer ist verantwortlich?", "Deine Rechte", "Österreichischen Datenschutzbehörde", "Barichgasse 40–42",
                 "keine Cookies", "nichts von fremden Servern", "Art. 6 Abs. 1 lit. b DSGVO", "§ 174 TKG 2021"):
        assert part in text, part
    # The periods in the text are the ones in the code.
    assert media.ATTACHMENT_TTL == timedelta(hours=24) and "nach 24 Stunden" in text
    assert review_invites.LINK_VALID == timedelta(days=60) and "60 Tage" in text


def test_the_terms_draft_carries_the_withdrawal_right_and_the_model_form():
    text = legal_texts.draft_html("nutzungsbedingungen")
    for part in ("innerhalb von 14 Tagen", "Muster-Widerrufsformular", "Hiermit widerrufe(n) ich/wir (*)",
                 "Unzutreffendes streichen", "Bitte sichere deine Daten", "gesetzliche Gewährleistung"):
        assert part in text, part


def test_the_imprint_draft_has_what_dolibarr_has_no_field_for():
    text = legal_texts.draft_html("impressum")
    for part in ("Rechtsform", "Gewerbebehörde", "Wirtschaftskammer", "Gewerbeordnung", "Mediengesetz"):
        assert part in text, part


def test_open_points_are_found_after_the_editor_escaped_them():
    edited = "<p>Behörde: [BITTE ERG&Auml;NZEN: z.&nbsp;B. <b>BH</b>]</p><p>Fertig.</p>"
    assert legal_texts.open_points(edited) == ["[BITTE ERGÄNZEN: z. B. BH ]"]
    assert legal_texts.open_points("<p>Alles ausgefüllt.</p>") == []


# ------------------------------------------------------------- checklist

COMPANY = {"name": "IT-Tabelander", "address": "Gasse 1", "zip": "6410", "town": "Telfs",
           "email": "office@example.com", "socialobject": "IT-Dienstleistungen"}


def test_the_imprint_checklist_names_what_is_missing_and_where():
    items = {item["label"]: item for item in legal_texts.imprint_checklist(COMPANY)}
    assert all(item["ok"] for item in items.values() if item["required"])
    assert items["Telefon"]["ok"] is False and items["Telefon"]["required"] is False
    assert "Unternehmen/Institution" in items["Anschrift"]["where"]

    items = {item["label"]: item for item in legal_texts.imprint_checklist({**COMPANY, "zip": "", "phone_mobile": "+43 660"})}
    assert items["Anschrift"]["ok"] is False and items["Telefon"]["ok"] is True


def _article(article_id, text, status=site_data.ARTICLE_DRAFT, question="Datenschutzerklärung"):
    return {"id": article_id, "question": question, "answer_html": text, "status": status}


def test_the_overview_lists_everything_still_to_do():
    settings = {"dolibarr_privacy_article_id": 7, "dolibarr_terms_article_id": 9}
    data = {"company": {**COMPANY, "email": ""},
            "articles": [_article(3, "FAQ", site_data.ARTICLE_VALIDATED, "Holt ihr ab?")],
            "legal": {"datenschutz": _article(7, "<p>[BITTE ERGÄNZEN: Anbieter] und [BITTE PRÜFEN: EU?]</p>"),
                      "nutzungsbedingungen": None},
            "errors": {}}
    result = legal_texts.overview(data, settings)
    texts = {text["kind"]: text for text in result["texts"]}
    assert texts["datenschutz"]["state"] == "draft" and len(texts["datenschutz"]["open_points"]) == 2
    assert texts["nutzungsbedingungen"]["gone"] is True and texts["impressum"]["state"] == "missing"
    assert result["todo"] == [
        "E-Mail-Adresse fehlt im Impressum",
        "Ergänzung zum Impressum fehlt",
        "Datenschutzerklärung ist noch ein Entwurf",
        "Datenschutzerklärung: 2 Stellen noch zu ergänzen",
        "Nutzungsbedingungen fehlt",
    ]
    # A picked article can be chosen again even outside the website's category.
    assert {choice["id"] for choice in result["choices"]} == {3, 7}


def test_without_company_data_the_missing_imprint_comes_first():
    """Dolibarr not connected yet: the live website has no imprint at all (#68)."""
    todo = legal_texts.overview({"company": None, "legal": {}}, {})["todo"]
    assert todo[0] == "Das Impressum fehlt auf der Website, weil noch keine Firmendaten aus Dolibarr kommen"
    assert "Datenschutzerklärung fehlt" in todo


def test_all_released_and_complete_leaves_nothing_to_do():
    settings = {"dolibarr_imprint_article_id": 1, "dolibarr_privacy_article_id": 2, "dolibarr_terms_article_id": 4}
    legal = {kind: _article(number, "<p>Fertig.</p>", site_data.ARTICLE_VALIDATED)
             for kind, number in (("impressum", 1), ("datenschutz", 2), ("nutzungsbedingungen", 4))}
    assert legal_texts.overview({"company": COMPANY, "legal": legal}, settings)["todo"] == []


# ------------------------------------------------------------ the button

class Settings:
    def __init__(self, doc):
        self.doc = doc
        self.updates = []

    async def find_one(self, *_args, **_kwargs):
        return self.doc

    async def update_one(self, query, update, **_kwargs):
        self.updates.append((query, update))


class Database:
    def __init__(self, settings):
        self.settings = Settings(settings)


def _create(monkeypatch, handler, settings=None):
    db = Database(settings or {})
    forgotten = []
    real_client = httpx.AsyncClient

    async def config():
        return {"enabled": True, "base": "https://erp.example.com", "api_key": "key", "timeout": 5}

    async def forget(database=None):
        forgotten.append(database)

    monkeypatch.setattr(legal_texts, "get_db", lambda: db)
    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(site_data, "forget", forget)
    monkeypatch.setattr(legal_texts.httpx, "AsyncClient",
                        lambda **kwargs: real_client(transport=httpx.MockTransport(handler), **kwargs))
    return db, forgotten, asyncio.run(legal_texts.create_draft("datenschutz"))


def test_the_draft_goes_into_dolibarr_as_a_draft_and_is_picked(monkeypatch):
    sent = []

    def handler(request):
        sent.append(json.loads(request.content))
        assert request.url.path.endswith("/knowledgemanagement/knowledgerecords")
        return httpx.Response(200, json=42)

    db, forgotten, result = _create(monkeypatch, handler)
    assert result == {"kind": "datenschutz", "article_id": 42}
    assert sent[0]["status"] == 0 and sent[0]["question"] == "Datenschutzerklärung"
    assert "Wer ist verantwortlich?" in sent[0]["answer"]
    assert db.settings.updates == [({"_id": "site"}, {"$set": {"dolibarr_privacy_article_id": 42}})]
    assert forgotten, "the cached copy must be read again"


def test_without_the_right_the_owner_learns_which_one(monkeypatch):
    with pytest.raises(PermissionError, match="Artikel anlegen/ändern"):
        _create(monkeypatch, lambda request: httpx.Response(403, json={"error": {"message": "Forbidden"}}))


def test_a_picked_article_is_never_overwritten(monkeypatch):
    with pytest.raises(ValueError, match="schon ein Artikel gewählt"):
        _create(monkeypatch, lambda request: httpx.Response(500), settings={"dolibarr_privacy_article_id": 5})


# ------------------------------------------------------- the public page

def test_a_legal_page_is_the_picked_article_whatever_its_category(monkeypatch):
    async def settings():
        return {"dolibarr_terms_article_id": 9}

    async def refresh(*, force=False):
        return {"articles": [], "legal": {"nutzungsbedingungen": _article(9, "<p>Bedingungen</p>", question="AGB")}}

    monkeypatch.setattr(site_data, "_settings", settings)
    monkeypatch.setattr(site_data, "refresh", refresh)
    page = asyncio.run(site_data.legal_page("nutzungsbedingungen"))
    assert page["html"] == "<p>Bedingungen</p>" and page["draft"] is True and page["title"] == "Nutzungsbedingungen"
    assert asyncio.run(site_data.legal_page("datenschutz")) is None


# ------------------------------------- withdrawal, business terms, categories (#128)

def test_the_two_new_pages_are_optional_and_have_no_draft_button():
    result = legal_texts.overview({"company": COMPANY, "legal": {}}, {})
    texts = {text["kind"]: text for text in result["texts"]}
    assert texts["widerruf"]["state"] == "missing" and texts["agb-unternehmer"]["optional"] is True
    assert texts["widerruf"]["can_draft"] is False and texts["datenschutz"]["can_draft"] is True
    assert not any("Widerruf" in item or "AGB" in item for item in result["todo"]), result["todo"]
    with pytest.raises(LookupError, match="import_unterlagen"):
        asyncio.run(legal_texts.create_draft("widerruf"))


def test_choices_come_from_the_legal_category_and_a_text_outside_it_is_named():
    settings = {"dolibarr_privacy_article_id": 2, "dolibarr_terms_article_id": 4}
    data = {"company": COMPANY, "category_ids": {"faq": 8, "legal": 9},
            "articles": [_article(3, "FAQ", site_data.ARTICLE_VALIDATED, "Holt ihr ab?"),
                         _article(4, "<p>Fertig.</p>", site_data.ARTICLE_VALIDATED, "Nutzungsbedingungen")],
            "legal_articles": [_article(2, "<p>Fertig.</p>", site_data.ARTICLE_VALIDATED),
                               _article(5, "<p>x</p>", question="Widerrufsbelehrung")],
            "legal": {"datenschutz": _article(2, "<p>Fertig.</p>", site_data.ARTICLE_VALIDATED),
                      "nutzungsbedingungen": _article(4, "<p>Fertig.</p>", site_data.ARTICLE_VALIDATED)}}
    result = legal_texts.overview(data, settings)
    texts = {text["kind"]: text for text in result["texts"]}
    # The FAQ question is no choice; the picked terms still are, but get named.
    assert {choice["id"] for choice in result["choices"]} == {2, 4, 5}
    assert texts["nutzungsbedingungen"]["outside_category"] is True and texts["datenschutz"]["outside_category"] is False
    assert "Nutzungsbedingungen steht in Dolibarr nicht in der Kategorie „Rechtstexte“" in result["todo"]


# ------------------------------------------------------------- the PDF

from app import legal_pdf  # noqa: E402
from app.routers import site as site_router  # noqa: E402

PDF = b"%PDF-1.7\n% IT-Tabelander\n"
REAL_CLIENT = httpx.AsyncClient


def _pdf_world(monkeypatch, handler, *, page=None):
    """Dolibarr behind a mock, and a legal page as the cached copy has it."""
    requests = []

    def recording(request):
        requests.append(request)
        return handler(request)

    async def config():
        return {"enabled": True, "base": "https://erp.example.com", "api_key": "key", "timeout": 5}

    async def legal_data():
        legal = {"datenschutz": page} if page else {}
        return {"legal": legal}, {"dolibarr_privacy_article_id": 2}

    legal_pdf.forget()
    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(site_data, "legal_data", legal_data)
    monkeypatch.setattr(legal_pdf.httpx, "AsyncClient",
                        lambda **kwargs: REAL_CLIENT(transport=httpx.MockTransport(recording), **kwargs))
    return requests


def _filed(content: bytes):
    import base64
    return lambda request: httpx.Response(200, json={"filename": "datenschutz.pdf", "content-type": "application/pdf",
                                                     "content": base64.b64encode(content).decode(), "encoding": "base64"})


def test_the_pdf_is_the_fixed_file_of_the_page_and_kept_for_a_while(monkeypatch):
    requests = _pdf_world(monkeypatch, _filed(PDF))
    assert asyncio.run(legal_pdf.fetch("datenschutz")) == PDF
    assert asyncio.run(legal_pdf.fetch("datenschutz")) == PDF
    assert len(requests) == 1, "the second call comes from the copy"
    params = requests[0].url.params
    assert requests[0].url.path.endswith("/api/index.php/documents/download")
    assert params["modulepart"] == "ecm" and params["original_file"] == "IT-Tabelander/Unterlagen/datenschutz.pdf"
    assert legal_pdf.SLUGS["impressum"] == "impressum-ergaenzung"
    # Anything else is never asked for, whatever comes in.
    assert asyncio.run(legal_pdf.fetch("../../conf/conf.php")) is None and len(requests) == 1


def test_a_missing_file_or_no_pdf_gives_no_pdf(monkeypatch):
    _pdf_world(monkeypatch, lambda request: httpx.Response(404, json={"error": {"message": "File not found"}}))
    assert asyncio.run(legal_pdf.available("widerruf")) is False
    _pdf_world(monkeypatch, _filed(b"<html>kein PDF</html>"))
    assert asyncio.run(legal_pdf.fetch("widerruf")) is None
    _pdf_world(monkeypatch, _filed(b"%PDF-" + b"0" * legal_pdf.MAX_BYTES))
    assert asyncio.run(legal_pdf.fetch("widerruf")) is None


def test_without_the_ecm_right_the_admin_learns_which_one(monkeypatch):
    _pdf_world(monkeypatch, lambda request: httpx.Response(403, json={"error": {"message": "Forbidden"}}))
    overview = asyncio.run(legal_pdf.overview())
    assert overview["available"]["datenschutz"] is False
    assert "Dokumente (ECM): lesen/herunterladen" in overview["problem"]
    assert overview["files"]["agb-unternehmer"] == "IT-Tabelander/Unterlagen/agb-unternehmer.pdf"


def test_the_download_serves_only_released_pages(monkeypatch):
    released = _article(2, "<p>Fertig.</p>", site_data.ARTICLE_VALIDATED)
    _pdf_world(monkeypatch, _filed(PDF), page=released)
    response = asyncio.run(site_router.legal_pdf_download("datenschutz"))
    assert response.body == PDF and response.media_type == "application/pdf"
    assert response.headers["content-disposition"] == 'attachment; filename="IT-Tabelander-datenschutz.pdf"'
    assert "max-age=600" in response.headers["cache-control"]

    for kind, page in (("datenschutz", _article(2, "<p>Entwurf</p>")), ("nutzungsbedingungen", released),
                       ("gibt-es-nicht", released)):
        _pdf_world(monkeypatch, _filed(PDF), page=page)
        with pytest.raises(site_router.HTTPException) as caught:
            asyncio.run(site_router.legal_pdf_download(kind))
        assert caught.value.status_code == 404, kind

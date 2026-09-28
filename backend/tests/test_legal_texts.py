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

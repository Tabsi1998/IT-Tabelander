"""The documents in docs/unterlagen and the script that brings them into Dolibarr (#127)."""
import importlib.util
import io
import re
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
_spec = importlib.util.spec_from_file_location("import_unterlagen", ROOT / "scripts" / "import_unterlagen.py")
imp = importlib.util.module_from_spec(_spec)
sys.modules[_spec.name] = imp  # dataclasses look the module up there
_spec.loader.exec_module(imp)

PUBLIC = {"agb-unternehmer", "widerruf"}
INTERNAL = {"leistungsbeschreibung-hosting-wartung", "auftragsverarbeitung", "fernwartung-einverstaendnis",
            "geraeteannahme-bausteine", "qs-checklisten", "firmendatenblatt", "preisliste"}


def write(tmp_path: Path, name: str, body: str, **meta) -> Path:
    values = {"Titel": "Test", "Schluessel": "u-test", "Slug": "test", "Kategorie": "Unterlagen",
              "Hinweis": "Entwurf – vor Verwendung vom WKO-Rechtsservice prüfen lassen", **meta}
    head = "\n".join(f"{key}: {value}" for key, value in values.items() if value is not None)
    path = tmp_path / name
    path.write_text(f"<!--\n{head}\n-->\n{body}", encoding="utf-8")
    return path


class TestDocuments:
    def test_every_document_is_there_once_with_its_category(self):
        documents = imp.load_documents()
        assert {doc.slug for doc in documents if doc.public} == PUBLIC
        assert {doc.slug for doc in documents if not doc.public} == INTERNAL
        assert {doc.path.stem for doc in documents} == PUBLIC | INTERNAL

    def test_the_price_list_stays_internal(self):
        price_list = next(doc for doc in imp.load_documents() if doc.slug == "preisliste")
        assert price_list.category == "Unterlagen" and not price_list.public

    def test_every_text_carries_the_wko_note_and_ends_with_its_date(self):
        for doc in imp.load_documents():
            text = doc.path.read_text(encoding="utf-8")
            assert "vor Verwendung vom WKO-Rechtsservice prüfen lassen" in text.split("-->")[0], doc.path.name
            # The note is for the owner, never for the customer's text.
            assert "WKO-Rechtsservice" not in doc.body, doc.path.name
            assert re.search(r"<p>Stand: \d{2}\.\d{2}\.\d{4}</p>\s*$", doc.body), doc.path.name

    def test_no_bank_account_anywhere(self):
        for path in (ROOT / "docs" / "unterlagen").iterdir():
            text = path.read_text(encoding="utf-8")
            assert not re.search(r"\bAT\d{2}(?:\s?\d{4}){4}\b", text), path.name
            assert "BIC:" not in text, path.name
        assert "__BANK__" in (ROOT / "docs" / "unterlagen" / "firmendatenblatt.html").read_text(encoding="utf-8")

    def test_open_points_are_counted_like_the_admin_does(self):
        by_slug = {doc.slug: doc for doc in imp.load_documents()}
        assert any("WKO" in point for point in by_slug["widerruf"].open_points)
        assert by_slug["geraeteannahme-bausteine"].open_points == []

    def test_the_readme_names_every_document(self):
        readme = (ROOT / "docs" / "unterlagen" / "README.md").read_text(encoding="utf-8")
        for doc in imp.load_documents():
            assert doc.path.name in readme and doc.title in readme, doc.path.name


class TestParser:
    def test_a_tag_the_editor_drops_is_refused(self, tmp_path):
        path = write(tmp_path, "a.html", "<table><tr><td>x</td></tr></table>\n<p>Stand: 01.01.2026</p>")
        with pytest.raises(imp.DocumentError, match="table"):
            imp.parse_document(path)

    def test_styles_and_classes_are_refused(self, tmp_path):
        path = write(tmp_path, "a.html", '<p style="color:red">x</p>\n<p>Stand: 01.01.2026</p>')
        with pytest.raises(imp.DocumentError, match="style"):
            imp.parse_document(path)

    def test_the_date_at_the_end_is_required(self, tmp_path):
        with pytest.raises(imp.DocumentError, match="Stand"):
            imp.parse_document(write(tmp_path, "a.html", "<p>Text</p>"))

    def test_the_header_is_required(self, tmp_path):
        path = tmp_path / "a.html"
        path.write_text("<p>Stand: 01.01.2026</p>", encoding="utf-8")
        with pytest.raises(imp.DocumentError, match="Kopfkommentar"):
            imp.parse_document(path)
        with pytest.raises(imp.DocumentError, match="Kategorie"):
            imp.parse_document(write(tmp_path, "b.html", "<p>Stand: 01.01.2026</p>", Kategorie=None))
        with pytest.raises(imp.DocumentError, match="Kategorie muss"):
            imp.parse_document(write(tmp_path, "c.html", "<p>Stand: 01.01.2026</p>", Kategorie="Website"))

    def test_the_key_fits_dolibarrs_import_key(self, tmp_path):
        with pytest.raises(imp.DocumentError, match="14 Zeichen"):
            imp.parse_document(write(tmp_path, "a.html", "<p>Stand: 01.01.2026</p>", Schluessel="u-viel-zu-langer-name"))

    def test_two_files_with_one_key_are_refused(self, tmp_path):
        write(tmp_path, "a.html", "<p>Stand: 01.01.2026</p>", Titel="Eins", Slug="eins")
        write(tmp_path, "b.html", "<p>Stand: 01.01.2026</p>", Titel="Zwei", Slug="zwei")
        with pytest.raises(imp.DocumentError, match="u-test"):
            imp.load_documents(tmp_path)


class FakeDolibarr:
    """What the script sees of Dolibarr: categories, articles, and every write."""

    base = "https://dolibarr.test"

    def __init__(self, articles=(), categories=(), members=None):
        self.articles = [dict(item) for item in articles]
        self.categories = [dict(item) for item in categories]
        self.members = members or {}
        self.writes = []

    def listing(self, path, params):
        if path == "categories":
            return self.categories
        if "category" in params:
            return [{"id": number} for number in self.members.get(params["category"], [])]
        return self.articles

    def request(self, method, path, params=None, body=None):
        self.writes.append((method, path, body))
        if path == "categories":
            self.categories.append({"id": 90 + len(self.categories), "label": body["label"]})
            return self.categories[-1]["id"]
        if method == "POST":
            self.articles.append({"id": 100 + len(self.articles), **body})
            return self.articles[-1]["id"]
        return {}

    def card(self, article_id):
        return f"{self.base}/card?id={article_id}"


def documents():
    return imp.load_documents()


class TestRun:
    def test_a_dry_run_writes_nothing(self):
        api, out = FakeDolibarr(), io.StringIO()
        assert imp.run(api, documents(), dry_run=True, out=out) == 0
        assert api.writes == []
        assert "würde neu als Entwurf anlegen" in out.getvalue()
        assert "Kategorie „Rechtstexte“: fehlt, würde angelegt" in out.getvalue()

    def test_new_articles_are_drafts_with_their_key_and_never_released(self):
        api, out = FakeDolibarr(), io.StringIO()
        assert imp.run(api, documents(), dry_run=False, out=out) == 0
        posts = [body for method, path, body in api.writes if method == "POST" and "knowledgerecords" in path]
        assert len(posts) == len(documents())
        assert all(body["status"] == imp.DRAFT and body["import_key"].startswith("u-") for body in posts)
        assert not any(body and body.get("status") == imp.RELEASED for _m, _p, body in api.writes)
        assert {body["label"] for method, path, body in api.writes if path == "categories"} == {"Rechtstexte", "Unterlagen"}

    def test_the_categories_are_listed_for_the_owner(self):
        out = io.StringIO()
        imp.run(FakeDolibarr(), documents(), dry_run=False, out=out)
        text = out.getvalue()
        assert "von Hand zuordnen" in text
        assert "„AGB für Unternehmer“ → Kategorie „Rechtstexte“" in text
        assert "„Preisliste IT-Tabelander“ → Kategorie „Unterlagen“" in text
        assert "„Preisliste IT-Tabelander“ → Kategorie „Rechtstexte“" not in text

    def test_a_second_run_updates_its_drafts_and_leaves_released_ones(self):
        docs = documents()
        agb = next(doc for doc in docs if doc.slug == "agb-unternehmer")
        avv = next(doc for doc in docs if doc.slug == "auftragsverarbeitung")
        preise = next(doc for doc in docs if doc.slug == "preisliste")
        articles = [
            {"id": 1, "import_key": agb.key, "status": 1, "question": "alt", "answer": "alt"},
            {"id": 2, "import_key": avv.key, "status": 0, "question": "alt", "answer": "alt"},
            {"id": 3, "import_key": preise.key, "status": 0, "question": preise.title, "answer": preise.body},
            {"id": 4, "import_key": "", "status": 1, "question": "Nutzungsbedingungen", "answer": "<p>x</p>"},
        ]
        api, out = FakeDolibarr(articles, [{"id": 7, "label": "Rechtstexte"}, {"id": 8, "label": "Unterlagen"}],
                                {7: [1], 8: [2, 3]}), io.StringIO()
        assert imp.run(api, docs, dry_run=False, out=out) == 0
        puts = {path for method, path, _body in api.writes if method == "PUT"}
        assert puts == {"knowledgemanagement/knowledgerecords/2"}
        assert not any(path.endswith("/1") or path.endswith("/3") or path.endswith("/4") for _m, path, _b in api.writes)
        posts = [body for method, path, body in api.writes if method == "POST"]
        assert len(posts) == len(docs) - 3 and all("categories" not in str(body) for body in posts)
        text = out.getvalue()
        assert "agb-unternehmer: „AGB für Unternehmer“ (#1): freigegeben, bleibt unverändert" in text
        assert "preisliste: „Preisliste IT-Tabelander“ (#3): unverändert" in text
        assert "„AGB für Unternehmer“ → Kategorie" not in text

    def test_a_failed_write_is_reported_and_fails_the_run(self):
        class Refusing(FakeDolibarr):
            def request(self, method, path, params=None, body=None):
                if "knowledgerecords" in path:
                    raise imp.DolibarrError(403, "Forbidden")
                return super().request(method, path, params, body)

        out = io.StringIO()
        assert imp.run(Refusing(), documents(), dry_run=False, out=out) == 1
        assert "FEHLER" in out.getvalue() and "HTTP 403" in out.getvalue()


def test_without_url_and_key_the_script_explains_itself(monkeypatch, capsys):
    monkeypatch.delenv("DOLIBARR_URL", raising=False)
    monkeypatch.delenv("DOLIBARR_API_KEY", raising=False)
    assert imp.main(["--dry-run"]) == 2
    assert "DOLIBARR_URL" in capsys.readouterr().err


def test_open_points_need_no_dolibarr(capsys):
    assert imp.main(["--offene-punkte"]) == 0
    assert "widerruf.html" in capsys.readouterr().out

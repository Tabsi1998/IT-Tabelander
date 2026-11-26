"""scripts/import_unterlagen.py against the throwaway Dolibarr of the local check (#127).

Runs in the group "dolibarr" of scripts/local_check.py, after
test_dolibarr_runtime.py, with an administrator's API key.
"""
import os
import shutil
import subprocess
import sys
from pathlib import Path

import pytest
import requests

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "import_unterlagen.py"
FOLDER = ROOT / "docs" / "unterlagen"
DOLIBARR = os.environ.get("DOLIBARR_TEST_URL", "").rstrip("/")
ADMIN_KEY = os.environ.get("DOLIBARR_TEST_ADMIN_KEY", "")


def api(method: str, path: str, **kwargs):
    response = requests.request(method, f"{DOLIBARR}/api/index.php/{path}",
                                headers={"DOLAPIKEY": ADMIN_KEY, "Accept": "application/json"}, timeout=30, **kwargs)
    if method == "GET" and response.status_code == 404:
        return []
    assert response.ok, response.text
    return response.json()


def imported() -> dict:
    """The script's articles by key; Dolibarr lists them with their import_key."""
    articles = api("GET", "knowledgemanagement/knowledgerecords", params={"limit": 500})
    return {item["import_key"]: item for item in articles if str(item.get("import_key") or "").startswith("u-")}


def run_script(*args, folder: Path = FOLDER) -> subprocess.CompletedProcess:
    env = {**os.environ, "DOLIBARR_URL": DOLIBARR, "DOLIBARR_API_KEY": ADMIN_KEY, "PYTHONIOENCODING": "utf-8"}
    # The 'dolibarr' group has no module ittabelanderpdf: the knowledge base target.
    completed = subprocess.run([sys.executable, str(SCRIPT), "--wissensmanagement", "--ordner", str(folder), *args], env=env,
                               capture_output=True, text=True, encoding="utf-8", timeout=180)
    print(completed.stdout, completed.stderr)
    return completed


def document_count(folder: Path = FOLDER) -> int:
    return len(list(folder.glob("*.html")))


@pytest.fixture(scope="module")
def first_run():
    if not (DOLIBARR and ADMIN_KEY):
        pytest.skip("no Dolibarr for the scenarios")
    dry = run_script("--dry-run")
    assert dry.returncode == 0
    assert imported() == {}, "a dry run must not write"
    assert "würde neu als Entwurf anlegen" in dry.stdout
    completed = run_script()
    assert completed.returncode == 0
    return completed


class TestImport:
    def test_every_document_is_a_draft_with_its_key(self, first_run):
        articles = imported()
        assert len(articles) == document_count()
        assert {int(item["status"]) for item in articles.values()} == {0}
        agb = articles["u-agb-b2b"]
        assert agb["question"] == "AGB für Unternehmer" and agb["lang"] == "de_DE"
        assert "Gewährleistung" in agb["answer"] and "WKO-Rechtsservice" not in agb["answer"]

    def test_the_categories_exist_and_the_owner_gets_the_list(self, first_run):
        labels = {item["label"] for item in api("GET", "categories", params={"type": "knowledgemanagement"})}
        assert {"Rechtstexte", "Unterlagen"} <= labels
        assert "von Hand zuordnen" in first_run.stdout
        assert "knowledgerecord_card.php?id=" in first_run.stdout

    def test_a_second_run_creates_nothing_twice(self, first_run):
        before = imported()
        completed = run_script()
        assert completed.returncode == 0
        after = imported()
        assert after.keys() == before.keys()
        assert {key: item["id"] for key, item in after.items()} == {key: item["id"] for key, item in before.items()}
        assert "neu als Entwurf anlegen" not in completed.stdout

    def test_a_draft_follows_the_file_and_a_released_article_stays(self, first_run, tmp_path):
        folder = tmp_path / "unterlagen"
        shutil.copytree(FOLDER, folder, ignore=shutil.ignore_patterns("*.md"))
        articles = imported()
        released = articles["u-agb-b2b"]
        api("PUT", f"knowledgemanagement/knowledgerecords/{released['id']}", json={"status": 1})
        assert int(api("GET", f"knowledgemanagement/knowledgerecords/{released['id']}")["status"]) == 1
        for name in ("agb-unternehmer.html", "auftragsverarbeitung.html"):
            path = folder / name
            path.write_text(path.read_text(encoding="utf-8").replace(
                "<p>Stand: 26.11.2026</p>", "<p>Geänderter Absatz.</p>\n<p>Stand: 27.11.2026</p>"), encoding="utf-8")
        completed = run_script(folder=folder)
        assert completed.returncode == 0
        assert "agb-unternehmer: „AGB für Unternehmer“" in completed.stdout and "freigegeben, bleibt unverändert" in completed.stdout
        agb = api("GET", f"knowledgemanagement/knowledgerecords/{released['id']}")
        assert "Geänderter Absatz" not in agb["answer"] and int(agb["status"]) == 1
        avv = api("GET", f"knowledgemanagement/knowledgerecords/{articles['u-avv']['id']}")
        assert "Geänderter Absatz" in avv["answer"] and int(avv["status"]) == 0

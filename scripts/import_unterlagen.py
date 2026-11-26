#!/usr/bin/env python3
"""Bring the documents from docs/unterlagen into Dolibarr's knowledge base (#127).

Every HTML file there starts with a comment that names its title, a short key,
the slug of its PDF and its category ("Rechtstexte" for public texts,
"Unterlagen" for internal ones). The script creates each document as a draft
knowledge article, or updates the draft it created before. It never releases
an article and never touches one that is released: what the owner released is
changed in Dolibarr by hand.

Dolibarr's REST API (23.0.3, 24.0.1) cannot put a knowledge article into a
category, so the script lists those assignments for the owner instead. It
creates a missing category if the API user may.

    DOLIBARR_URL=https://erp.example.com DOLIBARR_API_KEY=... \\
        python scripts/import_unterlagen.py --dry-run
    python scripts/import_unterlagen.py --offene-punkte

Standard library only, so it runs on any Python 3.10+ without a venv.
"""
from __future__ import annotations

import argparse
import html
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FOLDER = ROOT / "docs" / "unterlagen"
CATEGORIES = ("Rechtstexte", "Unterlagen")
# What Dolibarr's knowledge editor keeps; everything else would be lost there.
ALLOWED_TAGS = {"p", "h2", "h3", "ul", "ol", "li", "strong", "em", "a", "br"}
ALLOWED_ATTRIBUTES = {"a": {"href"}}
REQUIRED_META = ("Titel", "Schluessel", "Slug", "Kategorie", "Hinweis")
META = re.compile(r"\A\s*<!--(.*?)-->", re.S)
OPEN_POINT = re.compile(r"\[BITTE (?:ERGÄNZEN|PRÜFEN|ENTSCHEIDEN|MIT DER WKO KLÄREN)[^\]]*\]")
STAND = re.compile(r"<p>Stand: \d{2}\.\d{2}\.\d{4}</p>\s*\Z")
DRAFT, RELEASED = 0, 1


class DocumentError(ValueError):
    """A file in docs/unterlagen that Dolibarr would not keep as written."""


class DolibarrError(RuntimeError):
    def __init__(self, status: int, message: str):
        super().__init__(f"HTTP {status}: {message}")
        self.status = status


@dataclass
class Document:
    path: Path
    title: str
    key: str
    slug: str
    category: str
    body: str
    open_points: list[str] = field(default_factory=list)

    @property
    def public(self) -> bool:
        return self.category == "Rechtstexte"


class _TagCheck(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.problems: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag not in ALLOWED_TAGS:
            self.problems.append(f"<{tag}> ist nicht erlaubt")
            return
        for name, _value in attrs:
            if name not in ALLOWED_ATTRIBUTES.get(tag, set()):
                self.problems.append(f"Attribut {name} an <{tag}> ist nicht erlaubt")

    def handle_comment(self, data):
        self.problems.append("Kommentar im Text")


def parse_document(path: Path) -> Document:
    text = path.read_text(encoding="utf-8")
    match = META.match(text)
    if not match:
        raise DocumentError(f"{path.name}: der Kopfkommentar mit Titel, Schlüssel und Kategorie fehlt")
    meta = {}
    for line in match.group(1).strip().splitlines():
        name, _, value = line.partition(":")
        meta[name.strip()] = value.strip()
    missing = [name for name in REQUIRED_META if not meta.get(name)]
    if missing:
        raise DocumentError(f"{path.name}: im Kopfkommentar fehlt {', '.join(missing)}")
    if meta["Kategorie"] not in CATEGORIES:
        raise DocumentError(f"{path.name}: Kategorie muss {' oder '.join(CATEGORIES)} sein")
    # Dolibarr's import_key holds at most 14 characters.
    if not re.fullmatch(r"u-[a-z0-9-]{1,12}", meta["Schluessel"]):
        raise DocumentError(f"{path.name}: Schlüssel muss „u-…“ mit höchstens 14 Zeichen sein")
    if not re.fullmatch(r"[a-z0-9-]+", meta["Slug"]):
        raise DocumentError(f"{path.name}: Slug nur aus Kleinbuchstaben, Ziffern und Bindestrich")
    body = text[match.end():].strip()
    check = _TagCheck()
    check.feed(body)
    check.close()
    if check.problems:
        raise DocumentError(f"{path.name}: " + "; ".join(sorted(set(check.problems))))
    if not STAND.search(body):
        raise DocumentError(f"{path.name}: der Text muss mit „<p>Stand: TT.MM.JJJJ</p>“ enden")
    plain = html.unescape(re.sub(r"<[^>]+>", " ", body))
    points = [" ".join(found.group(0).split()) for found in OPEN_POINT.finditer(plain)]
    return Document(path, meta["Titel"], meta["Schluessel"], meta["Slug"], meta["Kategorie"], body, points)


def load_documents(folder: Path = FOLDER) -> list[Document]:
    documents = [parse_document(path) for path in sorted(folder.glob("*.html"))]
    for attribute in ("key", "slug", "title"):
        seen: dict[str, Path] = {}
        for document in documents:
            value = getattr(document, attribute)
            if value in seen:
                raise DocumentError(f"{document.path.name} und {seen[value].name} haben denselben Wert „{value}“")
            seen[value] = document.path
    return documents


class Dolibarr:
    def __init__(self, base: str, key: str, timeout: int = 30):
        self.base = base.rstrip("/")
        self.key = key
        self.timeout = timeout

    def request(self, method: str, path: str, params: dict | None = None, body: dict | None = None):
        url = f"{self.base}/api/index.php/{path}"
        if params:
            url += "?" + urllib.parse.urlencode(params)
        data = json.dumps(body).encode("utf-8") if body is not None else None
        request = urllib.request.Request(url, data=data, method=method, headers={
            "DOLAPIKEY": self.key, "Accept": "application/json", "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                raw = response.read().decode("utf-8")
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", "replace")[:300]
            raise DolibarrError(exc.code, detail) from None
        except urllib.error.URLError as exc:
            raise DolibarrError(0, f"Dolibarr nicht erreichbar ({exc.reason})") from None
        return json.loads(raw) if raw else None

    def listing(self, path: str, params: dict) -> list:
        """All pages of a list; Dolibarr answers an empty list with 404."""
        items, page = [], 0
        while True:
            try:
                chunk = self.request("GET", path, {**params, "limit": 100, "page": page})
            except DolibarrError as exc:
                if exc.status == 404:
                    return items
                raise
            items.extend(chunk or [])
            if not chunk or len(chunk) < 100:
                return items
            page += 1

    def card(self, article_id: int) -> str:
        return f"{self.base}/knowledgemanagement/knowledgerecord_card.php?id={int(article_id)}"


def _same(left: str, right: str) -> bool:
    return " ".join(str(left or "").split()) == " ".join(str(right or "").split())


def plan(documents: list[Document], articles: list[dict]) -> list[tuple[Document, str, dict | None]]:
    """What to do with each document: create, update, unchanged or skip."""
    by_key = {str(item.get("import_key") or ""): item for item in articles}
    steps = []
    for document in documents:
        article = by_key.get(document.key)
        if article is None:
            steps.append((document, "create", None))
        elif int(article.get("status") or 0) != DRAFT:
            steps.append((document, "skip", article))
        elif _same(article.get("question"), document.title) and _same(article.get("answer"), document.body):
            steps.append((document, "unchanged", article))
        else:
            steps.append((document, "update", article))
    return steps


def _article_payload(document: Document) -> dict:
    # No status: a new article starts as a draft, an update keeps it one.
    return {"question": document.title, "answer": document.body, "lang": "de_DE"}


def categories(api: Dolibarr, dry_run: bool, out) -> dict[str, int | None]:
    found = {}
    listed = api.listing("categories", {"type": "knowledgemanagement"})
    for label in CATEGORIES:
        match = next((item for item in listed if str(item.get("label")).strip() == label), None)
        if match:
            found[label] = int(match["id"])
            print(f"Kategorie „{label}“: vorhanden (#{found[label]})", file=out)
        elif dry_run:
            found[label] = None
            print(f"Kategorie „{label}“: fehlt, würde angelegt", file=out)
        else:
            try:
                found[label] = int(api.request("POST", "categories", body={"label": label, "type": "knowledgemanagement"}))
                print(f"Kategorie „{label}“: angelegt (#{found[label]})", file=out)
            except DolibarrError as exc:
                found[label] = None
                print(f"Kategorie „{label}“: fehlt und konnte nicht angelegt werden ({exc}); "
                      "bitte in Dolibarr unter Kategorien → Wissensmanagement anlegen", file=out)
    return found


def run(api: Dolibarr, documents: list[Document], dry_run: bool, out=None) -> int:
    out = out or sys.stdout
    print(f"Dolibarr: {api.base}" + ("  (Probelauf, es wird nichts geschrieben)" if dry_run else ""), file=out)
    category_ids = categories(api, dry_run, out)
    articles = [item for item in api.listing("knowledgemanagement/knowledgerecords", {"sortfield": "t.rowid"})
                if str(item.get("import_key") or "").startswith("u-")]
    words = {"create": "neu als Entwurf anlegen", "update": "Entwurf aktualisieren", "unchanged": "unverändert",
             "skip": "freigegeben, bleibt unverändert (Änderungen in Dolibarr von Hand)"}
    errors, ids = 0, {}
    for document, action, article in plan(documents, articles):
        label = f"{document.slug}: „{document.title}“"
        if article:
            ids[document.key] = int(article["id"])
        try:
            if action == "create" and not dry_run:
                payload = {**_article_payload(document), "import_key": document.key, "status": DRAFT}
                ids[document.key] = int(api.request("POST", "knowledgemanagement/knowledgerecords", body=payload))
            elif action == "update" and not dry_run:
                api.request("PUT", f"knowledgemanagement/knowledgerecords/{int(article['id'])}",
                            body=_article_payload(document))
        except DolibarrError as exc:
            errors += 1
            print(f"FEHLER {label}: {exc}", file=out)
            continue
        number = f" (#{ids[document.key]})" if document.key in ids else ""
        prefix = "würde " if dry_run and action in ("create", "update") else ""
        print(f"{label}{number}: {prefix}{words[action]}", file=out)
    todo = []
    for label, category_id in category_ids.items():
        members = set()
        if category_id:
            members = {int(item["id"]) for item in api.listing("knowledgemanagement/knowledgerecords",
                                                               {"category": category_id})}
        for document in documents:
            article_id = ids.get(document.key)
            if document.category == label and (article_id is None or article_id not in members):
                where = api.card(article_id) if article_id else "nach dem Anlegen"
                todo.append(f"„{document.title}“ → Kategorie „{label}“: {where}")
    if todo:
        print("\nIn Dolibarr von Hand zuordnen (die API kann Wissensartikeln keine Kategorie geben):", file=out)
        for line in todo:
            print(f"  - {line}", file=out)
    print("\nFreigeben machst du in Dolibarr selbst, erst nach der Prüfung durch den WKO-Rechtsservice.", file=out)
    return 1 if errors else 0


def print_open_points(documents: list[Document], out=None) -> int:
    """Only the files with open places; nothing at all when every text is ready."""
    out = out or sys.stdout
    count = 0
    for document in documents:
        if document.open_points:
            print(f"{document.path.name} ({len(document.open_points)})", file=out)
        for point in document.open_points:
            print(f"  - {point}", file=out)
            count += 1
    return count


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Unterlagen als Entwürfe ins Dolibarr-Wissensmanagement bringen")
    parser.add_argument("--dry-run", action="store_true", help="nur zeigen, was passieren würde")
    parser.add_argument("--offene-punkte", action="store_true",
                        help="die [BITTE …]-Stellen auflisten, ohne Dolibarr; Rückgabe 1, solange es welche gibt")
    parser.add_argument("--ordner", type=Path, default=FOLDER, help="Ordner mit den HTML-Dateien")
    args = parser.parse_args(argv)
    for stream in (sys.stdout, sys.stderr):  # a Windows console is cp1252: no "→" there
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="replace")
    try:
        documents = load_documents(args.ordner)
    except DocumentError as exc:
        print(f"FEHLER {exc}", file=sys.stderr)
        return 2
    if args.offene_punkte:
        return 1 if print_open_points(documents) else 0
    base, key = os.environ.get("DOLIBARR_URL", "").strip(), os.environ.get("DOLIBARR_API_KEY", "").strip()
    if not base or not key:
        print("FEHLER DOLIBARR_URL und DOLIBARR_API_KEY setzen (API-Benutzer mit „Wissensmanagement: "
              "Artikel lesen und anlegen/ändern“ und „Kategorien: lesen“).", file=sys.stderr)
        return 2
    try:
        return run(Dolibarr(base, key), documents, args.dry_run)
    except DolibarrError as exc:
        print(f"FEHLER {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())

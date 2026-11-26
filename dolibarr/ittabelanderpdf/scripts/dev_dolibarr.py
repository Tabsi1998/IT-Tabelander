#!/usr/bin/env python3
"""A disposable local Dolibarr 23.0.3 to render the Werkbank PDFs and e-mails against.

Same stack as IT-Tabelander's local check (scripts/local_check.py, group
"dolibarr", the same image): Dolibarr's own docker image built at a pinned
commit, MariaDB, Mailpit catching every e-mail, no data that survives. Never
talks to the production ERP. The module lives in dolibarr/ittabelanderpdf/ of
the IT-Tabelander repository; run this from that folder.

    python scripts/dev_dolibarr.py up         start Dolibarr + MariaDB + Mailpit (fresh install)
    python scripts/dev_dolibarr.py install    build the ZIP and unpack it into custom/
    python scripts/dev_dolibarr.py fixtures   company, bank account, customers, documents
    python scripts/dev_dolibarr.py pdf [name] render the examples (or those matching name) into docs/beispiele/
    python scripts/dev_dolibarr.py mails      e-mail scenarios, then Mailpit's messages into docs/beispiele/mails/
    python scripts/dev_dolibarr.py rollout    Mahnwesen and ticket texts into docs/rollout/, sent to Mailpit
    python scripts/dev_dolibarr.py ui         log in and check the card banner and the queue page
    python scripts/dev_dolibarr.py tickets    the module's own ticket confirmation (tests/ticket_confirm.php), checked in Mailpit
    python scripts/dev_dolibarr.py unterlagen company documents from the knowledge base (tests/unterlagen.php), mail form, ticket, setup
    python scripts/dev_dolibarr.py qs         QS checklists, test report, intake slip (tests/qs.php), phone page, setup, rights
    python scripts/dev_dolibarr.py sign       signatures on the device and sending (tests/sign.php), signing page
    python scripts/dev_dolibarr.py docs       documents per customer (tests/docs.php), tab, cards, widget, rules
    python scripts/dev_dolibarr.py all        up, install, fixtures, pdf, mails, rollout, ui, tickets, unterlagen, qs, sign, docs
    python scripts/dev_dolibarr.py down       remove containers, volumes and network

Standard library only. Passwords are new on every start; the local test
administrator's login is kept in .local-testing/ (git-ignored) for the ui step.

IT-Tabelander's local check (group "dolibarr-modul") runs the same steps with
its own containers and ports and writes the examples outside docs/, so a check
never changes committed files:
    ITTABPDF_PREFIX    container names (default ittpdf-dolibarr)
    ITTABPDF_PORT      Dolibarr's port (default 18051)
    ITTABPDF_MAIL_PORT Mailpit's port (default 18151)
    ITTABPDF_OUT       folder for beispiele/ and rollout/ (default docs/)
"""

from __future__ import annotations

import http.cookiejar
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from html import unescape
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / ".local-testing"
OUT = Path(os.environ.get("ITTABPDF_OUT") or ROOT / "docs").resolve()
EXAMPLES = OUT / "beispiele"
MAILS = EXAMPLES / "mails"
ROLLOUT = OUT / "rollout"
IMAGE = "local-ci/dolibarr:23.0.3"
BUILD = "https://github.com/Dolibarr/dolibarr-docker.git#73658fe7060709b3bc798e646284775ec0020101:images/23.0.3-php8.2"
MARIADB = "mariadb:11.4.13"
MAILPIT = "axllent/mailpit:v1.31.1"
PREFIX = os.environ.get("ITTABPDF_PREFIX") or "ittpdf-dolibarr"
NETWORK = f"{PREFIX}-net"
WEB = f"{PREFIX}-web"
DB = f"{PREFIX}-db"
MAIL = f"{PREFIX}-mail"
PORT = int(os.environ.get("ITTABPDF_PORT") or 18051)
MAIL_PORT = int(os.environ.get("ITTABPDF_MAIL_PORT") or 18151)
URL = f"http://127.0.0.1:{PORT}"
MAILPIT_URL = f"http://127.0.0.1:{MAIL_PORT}"
FIXTURES = "/opt/ittpdf-fixtures"
MODULE = "ittabelanderpdf"
LOGIN = STATE / "admin-login.json"


def shown(path: Path) -> str:
    """A path relative to the module folder when it lies inside, else in full."""
    try:
        return str(path.relative_to(ROOT))
    except ValueError:
        return str(path)


def docker(*args: str, check: bool = True, capture: bool = True) -> subprocess.CompletedProcess:
    binary = shutil.which("docker") or r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    done = subprocess.run([binary, *args], capture_output=capture, text=True, encoding="utf-8", errors="replace")
    if check and done.returncode != 0:
        raise SystemExit(f"docker {' '.join(args[:3])} failed:\n{done.stdout}{done.stderr}")
    return done


def down() -> None:
    for name in (WEB, DB, MAIL):
        docker("rm", "--force", "--volumes", name, check=False)
    docker("network", "rm", NETWORK, check=False)
    print("removed", WEB, DB, MAIL, NETWORK)


def up() -> None:
    if docker("image", "inspect", IMAGE, check=False).returncode != 0:
        docker("build", "--tag", IMAGE, BUILD)
    down()
    password = secrets.token_urlsafe(18)
    admin_password = secrets.token_urlsafe(18)
    STATE.mkdir(exist_ok=True)
    LOGIN.write_text(json.dumps({"login": "admin", "password": admin_password}), encoding="utf-8")
    docker("network", "create", NETWORK)
    docker("run", "--detach", "--name", DB, "--network", NETWORK, "--network-alias", "db",
           "--tmpfs", "/var/lib/mysql", "--env", f"MARIADB_ROOT_PASSWORD={password}",
           "--env", "MARIADB_DATABASE=dolibarr", "--env", "MARIADB_USER=dolibarr",
           "--env", f"MARIADB_PASSWORD={password}", "--env", "TZ=Europe/Vienna", MARIADB)
    docker("run", "--detach", "--name", MAIL, "--network", NETWORK, "--network-alias", "mail",
           "--publish", f"127.0.0.1:{MAIL_PORT}:8025", MAILPIT)
    docker("run", "--detach", "--name", WEB, "--network", NETWORK,
           "--publish", f"127.0.0.1:{PORT}:80",
           "--mount", f"type=bind,source={ROOT / 'tests'},target={FIXTURES},readonly",
           "--env", "DOLI_DB_HOST=db", "--env", "DOLI_DB_NAME=dolibarr", "--env", "DOLI_DB_USER=dolibarr",
           "--env", f"DOLI_DB_PASSWORD={password}", "--env", "DOLI_ADMIN_LOGIN=admin",
           "--env", f"DOLI_ADMIN_PASSWORD={admin_password}", "--env", "DOLI_INSTALL_AUTO=1",
           "--env", f"DOLI_URL_ROOT={URL}", "--env", "DOLI_COMPANY_COUNTRYCODE=AT",
           "--env", "DOLI_COMPANY_NAME=IT-Tabelander", "--env", "DOLI_PROD=0",
           "--env", "PHP_INI_DATE_TIMEZONE=Europe/Vienna", IMAGE)
    deadline = time.time() + 420
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(f"{URL}/index.php", timeout=5) as answer:
                if answer.status == 200:
                    print(f"Dolibarr 23.0.3 on {URL}, Mailpit on {MAILPIT_URL}")
                    return
        except (urllib.error.URLError, OSError):
            pass
        time.sleep(3)
    raise SystemExit("Dolibarr did not answer within 420 s")


def install() -> None:
    """The module exactly as the release ZIP ships it, unpacked into custom/."""
    built = subprocess.run([sys.executable, str(ROOT / "scripts" / "build_release.py")], capture_output=True, text=True)
    if built.returncode != 0:
        raise SystemExit(built.stdout + built.stderr)
    archive = Path(built.stdout.splitlines()[0])
    unpacked = STATE / "unpacked"
    shutil.rmtree(unpacked, ignore_errors=True)
    with zipfile.ZipFile(archive) as bundle:
        bundle.extractall(unpacked)
    docker("exec", WEB, "rm", "-rf", f"/var/www/html/custom/{MODULE}")
    docker("cp", str(unpacked / MODULE), f"{WEB}:/var/www/html/custom/{MODULE}")
    docker("exec", WEB, "chown", "-R", "www-data:www-data", f"/var/www/html/custom/{MODULE}")
    print("installed", archive.name)


def php(*args: str, script: str = "fixtures.php") -> dict:
    done = docker("exec", "-u", "www-data", WEB, "php", f"{FIXTURES}/{script}", *args, check=False)
    if done.returncode != 0:
        raise SystemExit(f"{script} {' '.join(args)} failed:\n{done.stdout}{done.stderr}")
    return json.loads(done.stdout.strip().splitlines()[-1])


def fixtures() -> None:
    php("base")
    print(json.dumps(php("setup"), indent=2, ensure_ascii=False))
    print("company, bank account, customers and documents are in place")


def pdf(only: str = "") -> None:
    """Every sample document rendered with werkbank, copied to docs/beispiele/."""
    print(json.dumps(php("render", only), indent=2, ensure_ascii=False))
    EXAMPLES.mkdir(parents=True, exist_ok=True)
    docker("cp", f"{WEB}:/tmp/ittpdf-out/.", str(EXAMPLES))
    for target in sorted(EXAMPLES.glob("*.pdf")):
        print(f"{shown(target)}  {target.stat().st_size // 1024} KB")


def mailpit(path: str) -> dict:
    with urllib.request.urlopen(f"{MAILPIT_URL}/api/v1/{path}", timeout=30) as answer:
        return json.loads(answer.read().decode("utf-8"))


def mails() -> None:
    """E-mail scenarios in PHP, then every caught message as HTML plus a summary."""
    urllib.request.urlopen(urllib.request.Request(f"{MAILPIT_URL}/api/v1/messages", method="DELETE"), timeout=30)
    result = php("mails")
    (STATE / "ui.json").write_text(json.dumps({"planned_propal": result.get("planned_propal")}), encoding="utf-8")
    docker("cp", f"{WEB}:/tmp/ittpdf-out/angebot-1-kundenbereich-an.pdf", str(EXAMPLES / "angebot-1-kundenbereich-an.pdf"))
    MAILS.mkdir(parents=True, exist_ok=True)
    for old in MAILS.glob("*"):
        if old.is_file():
            old.unlink()
    summary = []
    messages = sorted(mailpit("messages?limit=200").get("messages", []), key=lambda entry: entry["Created"])
    for number, item in enumerate(messages, 1):
        detail = mailpit(f"message/{item['ID']}")
        to = ", ".join(entry["Address"] for entry in detail.get("To") or [])
        bcc = ", ".join(entry["Address"] for entry in detail.get("Bcc") or [])
        attachments = [entry["FileName"] for entry in detail.get("Attachments") or []]
        inline = [entry.get("ContentID", "") for entry in detail.get("Inline") or []]
        slug = f"{number:02d}-" + re.sub(r"[^a-z0-9]+", "-", f"{detail['Subject']} {to}".lower()).strip("-")[:80]
        (MAILS / f"{slug}.html").write_text(detail.get("HTML") or "", encoding="utf-8")
        found = re.search(r">\s*((?:Hallo|Guten Tag)[^<]*)<", detail.get("HTML") or "")
        greeting = found.group(1).strip() if found else ""
        summary.append({"file": f"{slug}.html", "subject": detail["Subject"], "to": to, "bcc": bcc,
                        "attachments": attachments, "inline": inline, "greeting": greeting})
    (MAILS / "uebersicht.json").write_text(json.dumps({"scenarios": result, "messages": summary}, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(result, indent=2, ensure_ascii=False))
    print(f"{len(summary)} messages -> {shown(MAILS)}")


def rollout() -> None:
    """Texts for production outside this module, into docs/rollout/, and their mails."""
    result = php("rollout")
    target = ROLLOUT
    target.mkdir(parents=True, exist_ok=True)
    docker("cp", f"{WEB}:/tmp/ittpdf-rollout/.", str(target))
    docker("cp", f"{WEB}:/tmp/ittpdf-out/mahnwesen-brieftext-simulation.pdf", str(EXAMPLES / "mahnwesen-brieftext-simulation.pdf"))
    MAILS.mkdir(parents=True, exist_ok=True)
    for old in MAILS.glob("rollout-*"):
        old.unlink()
    wanted = ("Zahlungserinnerung", "Mahnung", "Ticket", "TS2611")
    messages = sorted(mailpit("messages?limit=200").get("messages", []), key=lambda entry: entry["Created"])
    number = 0
    for item in messages:
        if not any(word in item["Subject"] for word in wanted):
            continue
        number += 1
        detail = mailpit(f"message/{item['ID']}")
        slug = f"rollout-{number:02d}-" + re.sub(r"[^a-z0-9]+", "-", detail["Subject"].lower()).strip("-")[:70]
        (MAILS / f"{slug}.html").write_text(detail.get("HTML") or "", encoding="utf-8")
    print(json.dumps(result, indent=2, ensure_ascii=False))
    print(f"{number} rollout messages -> {shown(MAILS)}")


def ui() -> None:
    """Log in as the local test administrator and look at the pages a person uses."""
    state = json.loads((STATE / "ui.json").read_text(encoding="utf-8")) if (STATE / "ui.json").exists() else {}
    login = json.loads(LOGIN.read_text(encoding="utf-8"))
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    page = opener.open(f"{URL}/index.php", timeout=30).read().decode("utf-8", "replace")
    token = re.search(r'name="token" value="([^"]+)"', page).group(1)
    form = urllib.parse.urlencode({"token": token, "actionlogin": "login", "loginfunction": "loginfunction",
                                   "username": login["login"], "password": login["password"]}).encode()
    opener.open(f"{URL}/index.php?mainmenu=home", data=form, timeout=30).read()
    checks = []

    def look(name: str, path: str, needles: list[str]) -> None:
        html = opener.open(f"{URL}{path}", timeout=60).read().decode("utf-8", "replace")
        missing = [needle for needle in needles if needle not in html]
        checks.append({"page": name, "ok": not missing, "missing": missing})
        (STATE / f"ui-{name}.html").write_text(html, encoding="utf-8")

    # The e-mail logo must load without a session (mail clients have none).
    with urllib.request.urlopen(f"{URL}/custom/ittabelanderpdf/public/maillogo.php", timeout=30) as answer:
        data = answer.read()
        width, height = int.from_bytes(data[16:20], "big"), int.from_bytes(data[20:24], "big")
        checks.append({"page": "maillogo without login", "ok": answer.status == 200 and answer.headers.get("Content-Type") == "image/png"
                       and data[1:4] == b"PNG" and width <= 480 and "max-age" in (answer.headers.get("Cache-Control") or ""),
                       "missing": [f"{answer.status} {answer.headers.get('Content-Type')} {width}x{height} {answer.headers.get('Cache-Control')}"]})
    with urllib.request.urlopen(f"{URL}/custom/ittabelanderpdf/public/maillogo.php?variant=dark", timeout=30) as answer:
        data = answer.read()
        checks.append({"page": "dark maillogo without login", "ok": answer.status == 200 and data[1:4] == b"PNG"
                       and int.from_bytes(data[16:20], "big") <= 480, "missing": [f"{answer.status} {len(data)} bytes"]})
    # Dolibarr's WAF checks every POST: the texts must pass it, or nobody can save them.
    samples = [ROLLOUT / "mahnwesen" / "mahnung-1.html", ROLLOUT / "ticket" / "signatur.html",
               next(MAILS.glob("*von-hand*angebot*.html"), None) or next(MAILS.glob("*von-hand*.html"))]
    for sample in samples:
        page = opener.open(f"{URL}/custom/ittabelanderpdf/admin/setup.php", timeout=30).read().decode("utf-8", "replace")
        token = re.search(r'name="token" value="([^"]+)"', page).group(1)
        body = urllib.parse.urlencode({"token": token, "action": "none", "content": sample.read_text(encoding="utf-8")}).encode()
        try:
            status = opener.open(f"{URL}/custom/ittabelanderpdf/admin/setup.php", data=body, timeout=30).status
        except urllib.error.HTTPError as error:
            status = error.code
        checks.append({"page": f"POST {sample.name} passes the WAF", "ok": status == 200, "missing": [str(status)]})
    look("queue", "/custom/ittabelanderpdf/queue.php", ["Automatischer Versand", "Gesendet"])
    look("setup", "/custom/ittabelanderpdf/admin/setup.php", ["ITTABPDF_AUTOSEND_DELAY", "Angebot (Sie)", "action=update_templates"])
    if state.get("planned_propal"):
        card = f"/comm/propal/card.php?id={state['planned_propal']}"
        look("propal-card", card, ["Automatischer Versand geplant um", "ittabpdf_cancel"])
        # Press "Abbrechen" like a person would; afterwards the banner is gone.
        found = re.search(r'href="([^"]*action=ittabpdf_cancel)"', (STATE / "ui-propal-card.html").read_text(encoding="utf-8"))
        if found:
            opener.open(f"{URL}{found.group(1).replace('&amp;', '&')}", timeout=60).read()
            html = opener.open(f"{URL}{card}", timeout=60).read().decode("utf-8", "replace")
            checks.append({"page": "propal-card-after-cancel", "ok": "ittabpdf-autosend" not in html, "missing": []})
    print(json.dumps(checks, indent=2, ensure_ascii=False))
    if not all(check["ok"] for check in checks):
        raise SystemExit("ui check failed")
    print(f"{len(checks)} page checks passed")


def tickets() -> None:
    """The module's own mails for a new ticket (tests/ticket_confirm.php), checked in Mailpit.

    The customer gets the confirmation by Du or Sie without the ticket text
    (it may carry internal notes), the owner the German notice with the full
    text, and Dolibarr's built-in mails do not go out as well. Saved as
    ticket-*.html next to the other e-mails.
    """
    urllib.request.urlopen(urllib.request.Request(f"{MAILPIT_URL}/api/v1/messages", method="DELETE"), timeout=30)
    php("enable", script="ticket_confirm.php")
    created = php("run", script="ticket_confirm.php")
    time.sleep(2)
    messages = [mailpit(f"message/{item['ID']}") for item in
                sorted(mailpit("messages?limit=200").get("messages", []), key=lambda entry: entry["Created"])]
    for old in MAILS.glob("ticket-*.html"):
        old.unlink()
    MAILS.mkdir(parents=True, exist_ok=True)
    for number, detail in enumerate(messages, 1):
        slug = f"ticket-{number:02d}-" + re.sub(r"[^a-z0-9]+", "-", detail["Subject"].lower()).strip("-")[:70]
        (MAILS / f"{slug}.html").write_text(detail.get("HTML") or "", encoding="utf-8")

    def to(detail: dict) -> set:
        return {entry["Address"] for entry in detail.get("To") or []}

    internal = "intern, nur für den Inhaber"
    checks = []
    expected = {"du": ("max@mustermann.example", "Deine Anfrage", "Liebe Grüße"),
                "sie": ("office@musterfirma.example", "Ihre Anfrage", "Mit freundlichen Grüßen"),
                "website": ("anna@beispiel.example", "Ihre Anfrage", "Mit freundlichen Grüßen")}
    for case, (address, opening, closing) in expected.items():
        ref = created[case]["ref"]
        mine = [detail for detail in messages if address in to(detail)]
        html = unescape(mine[0].get("HTML") or "") if len(mine) == 1 else ""
        checks.append({"check": f"{case}: one confirmation to {address}",
                       "ok": len(mine) == 1 and mine[0]["Subject"].startswith(f"{opening} {ref}")
                       and closing in html and internal not in html and "\\n" not in html
                       and f"/kundenbereich/anfragen/{created[case]['id']}" in html and "erreich" not in html,
                       "found": [detail["Subject"] for detail in mine]})
        notice = [detail for detail in messages if "inhaber@tabelander.example" in to(detail) and ref in detail["Subject"]]
        checks.append({"check": f"{case}: one owner notice with the full text",
                       "ok": len(notice) == 1 and notice[0]["Subject"].startswith(f"Neue Anfrage {ref}")
                       and internal in unescape(notice[0].get("HTML") or ""),
                       "found": [detail["Subject"] for detail in notice]})
    off = created["off"]["ref"]
    silent = [detail["Subject"] for detail in messages if "still@beispiel.example" in to(detail) or off in detail["Subject"]]
    checks.append({"check": "off: no mail at all", "ok": not silent, "found": silent})
    checks.append({"check": "no other mail (Dolibarr's own texts stay away)", "ok": len(messages) == 6,
                   "found": [detail["Subject"] for detail in messages]})
    print(json.dumps({"tickets": created, "checks": checks}, indent=2, ensure_ascii=False))
    print(f"{len(messages)} ticket messages -> {shown(MAILS)}")
    if not all(check["ok"] for check in checks):
        raise SystemExit("ticket check failed")


class FormFields(HTMLParser):
    """The named fields of one form (by id or class) as a browser would post them."""

    def __init__(self, marker: str) -> None:
        super().__init__(convert_charrefs=True)
        self.marker, self.depth, self.fields = marker, 0, []
        self.textarea: str | None = None
        self.select: str | None = None
        self.multiple, self.chosen = False, False
        self.first: str | None = None

    def handle_starttag(self, tag: str, attrs: list) -> None:
        attr = {key: value or "" for key, value in attrs}
        if tag == "form":
            if self.depth or self.marker in (attr.get("id", ""), *attr.get("class", "").split()):
                self.depth += 1
            return
        if not self.depth:
            return
        name = attr.get("name", "")
        if tag == "input" and name:
            kind = attr.get("type", "text").lower()
            if kind in ("hidden", "text", "email") or (kind in ("checkbox", "radio") and "checked" in attr):
                self.fields.append((name, attr.get("value", "")))
        elif tag == "textarea" and name:
            self.textarea, self.text = name, ""
        elif tag == "select" and name:
            self.select, self.multiple, self.first, self.chosen = name, "multiple" in attr, None, False
        elif tag == "option" and self.select:
            if self.first is None:
                self.first = attr.get("value", "")
            if "selected" in attr:
                self.fields.append((self.select, attr.get("value", "")))
                self.chosen = True

    def handle_endtag(self, tag: str) -> None:
        if tag == "form" and self.depth:
            self.depth -= 1
        elif tag == "textarea" and self.textarea:
            self.fields.append((self.textarea, self.text))
            self.textarea = None
        elif tag == "select" and self.select:
            # A single select without a selected option posts its first one.
            if not self.multiple and not self.chosen and self.first is not None:
                self.fields.append((self.select, self.first))
            self.select = None

    def handle_data(self, data: str) -> None:
        if self.textarea:
            self.text += data


def form_fields(html: str, marker: str) -> list:
    parser = FormFields(marker)
    parser.feed(html)
    return parser.fields


def browser() -> urllib.request.OpenerDirector:
    """A logged-in session of the local test administrator."""
    login = json.loads(LOGIN.read_text(encoding="utf-8"))
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    page = opener.open(f"{URL}/index.php", timeout=30).read().decode("utf-8", "replace")
    token = re.search(r'name="token" value="([^"]+)"', page).group(1)
    form = urllib.parse.urlencode({"token": token, "actionlogin": "login", "loginfunction": "loginfunction",
                                   "username": login["login"], "password": login["password"]}).encode()
    opener.open(f"{URL}/index.php?mainmenu=home", data=form, timeout=30).read()
    return opener


def unterlagen() -> None:
    """Company documents: articles -> PDFs (tests/unterlagen.php), then the pages a person uses.

    The article card shows where its PDF is and writes it again on "PDF
    erzeugen"; the proposal's mail form offers the Unterlagen, adds the
    defaults for companies and private persons and sends the chosen ones
    (checked in Mailpit); the ticket message gets them from the box above it;
    every form passes Dolibarr's WAF. Example PDFs go to beispiele/.
    """
    apikey = php("enable", script="unterlagen.php")["apikey"]
    result = php("run", script="unterlagen.php")
    checks = [dict(entry, page="php") for entry in result["checks"]]
    EXAMPLES.mkdir(parents=True, exist_ok=True)
    for name in ("unterlage-nutzungsbedingungen.pdf", "unterlage-widerruf.pdf", "unterlage-checkliste-uebergabe.pdf", "angebot-mit-agb.pdf"):
        docker("cp", f"{WEB}:/tmp/ittpdf-out/{name}", str(EXAMPLES / name))
    opener = browser()

    def get(path: str) -> str:
        return opener.open(f"{URL}{path}", timeout=60).read().decode("utf-8", "replace")

    def post(path: str, fields: list) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", data=urllib.parse.urlencode(fields).encode(), timeout=120)
            return answer.status, answer.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, ""

    def attached(html: str) -> list:
        return re.findall(r'<div id="attachfile_\d+">.*?(?:</span>|>)\s*([^<>]+?\.pdf)', html)

    def check(name: str, ok: bool, detail: str = "") -> None:
        checks.append({"page": "web", "check": name, "ok": bool(ok), "detail": detail})

    # The knowledge article that was taken over points to the module.
    html = get(f"/knowledgemanagement/knowledgerecord_card.php?id={result['article']}")
    check("taken-over article points to the document in the module", "ittabpdf-unterlage" in html and "unterlage_card.php?id=" in html)

    # List and card: create, release with a note, preview, versions.
    base = "/custom/ittabelanderpdf"
    html = get(f"{base}/unterlagen_list.php")
    check("list shows the documents with version and state", "Nutzungsbedingungen" in html and "datenschutz.pdf" in html and "v3" in html)
    html = get(f"{base}/unterlage_card.php?action=create")
    token = re.search(r'name="token" value="([^"]+)"', html).group(1)
    status, html = post(f"{base}/unterlage_card.php", [("token", token), ("action", "add"), ("title", "Preisaushang Werkstatt"), ("slug", ""),
                                                      ("kind", "internal"), ("text", "<h2>Preise</h2><p>Diagnose 29 €.</p><ul><li>Express</li></ul>"), ("position", "5")])
    check("new document from the card (WAF passes)", status == 200 and "Preisaushang Werkstatt" in html and "preisaushang-werkstatt" in html, str(status))
    found = re.search(r"[?&]id=(\d+)&(?:amp;)?action=preview", html)
    if found:
        doc_id = found.group(1)
        page = get(f"{base}/unterlage_card.php?id={doc_id}")
        token = re.search(r'name="token" value="([^"]+)"', page).group(1)
        page = get(f"{base}/unterlage_card.php?id={doc_id}&action=askrelease&token={token}")
        token = re.search(r'name="token" value="([^"]+)"', page).group(1)
        html = get(f"{base}/unterlage_card.php?id={doc_id}&action=confirm_release&confirm=yes&note=Erste%20Fassung&token={token}")
        check("release from the card writes version 1 and the PDF", "v1" in html and "Erste Fassung" in html)
        token = re.search(r'name="token" value="([^"]+)"', html).group(1)
        with opener.open(f"{URL}{base}/unterlage_card.php?id={doc_id}&action=preview&token={token}", timeout=60) as answer:
            check("preview of the draft is a PDF", answer.read(4) == b"%PDF")
    else:
        check("release from the card writes version 1 and the PDF", False, "no document id")

    # The module's REST API for the website: public legal texts, HTML and PDF.
    def api(path: str) -> tuple:
        request = urllib.request.Request(f"{URL}/api/index.php/ittabelanderpdf/{path}", headers={"DOLAPIKEY": apikey, "Accept": "application/json"})
        try:
            with urllib.request.urlopen(request, timeout=60) as answer:
                return answer.status, json.loads(answer.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            return error.code, {}
    status, listed = api("unterlagen")
    slugs = sorted(entry.get("slug") for entry in listed) if isinstance(listed, list) else []
    check("API lists the public legal texts only", status == 200 and "datenschutz" in slugs and "nutzungsbedingungen" in slugs
          and "checkliste-uebergabe" not in slugs and "avv-vorlage" not in slugs, f"{status} {slugs}")
    status, one = api("unterlagen/datenschutz")
    check("API gives the HTML of the released version", status == 200 and "IT-Tabelander" in one.get("html", "") and one.get("version") == 1, str(status))
    status, pdf = api("unterlagen/datenschutz/pdf")
    import base64
    check("API gives the PDF", status == 200 and base64.b64decode(pdf.get("content", "")).startswith(b"%PDF"), str(status))
    status, _ = api("unterlagen/checkliste-uebergabe")
    check("API hides internal documents", status == 404, str(status))

    # scripts/import_unterlagen.py of the repository brings docs/unterlagen as drafts into the module.
    script = ROOT.parents[1] / "scripts" / "import_unterlagen.py"
    folder = ROOT.parents[1] / "docs" / "unterlagen"
    if script.exists() and folder.exists():
        env = {**os.environ, "DOLIBARR_URL": URL, "DOLIBARR_API_KEY": apikey, "PYTHONIOENCODING": "utf-8"}
        first = subprocess.run([sys.executable, str(script), "--ordner", str(folder)], env=env, capture_output=True, text=True,
                               encoding="utf-8", errors="replace")
        second = subprocess.run([sys.executable, str(script), "--ordner", str(folder)], env=env, capture_output=True, text=True,
                                encoding="utf-8", errors="replace")
        check("import script writes the drafts into the module, a second run changes nothing",
              first.returncode == 0 and "widerruf:" in first.stdout and second.returncode == 0
              and "anlegen" not in second.stdout and "aktualisieren" not in second.stdout and "unverändert" in second.stdout,
              (second.stdout + second.stderr)[-300:])
    with opener.open(f"{URL}/document.php?modulepart=ecm&file=IT-Tabelander/Unterlagen/nutzungsbedingungen.pdf", timeout=60) as answer:
        check("PDF downloads from Documents", answer.status == 200 and answer.read(4) == b"%PDF")

    # Mail form of a proposal to a company: choice, default, chosen file, sent.
    urllib.request.urlopen(urllib.request.Request(f"{MAILPIT_URL}/api/v1/messages", method="DELETE"), timeout=30)
    path = f"/comm/propal/card.php?id={result['propal_firm']}"
    html = get(f"{path}&action=presend&mode=init")
    check("mail form offers the Unterlagen", 'name="ittabpdf_unterlagen[]"' in html and "Nutzungsbedingungen" in html)
    check("company default agb-unternehmer.pdf is attached", "agb-unternehmer.pdf" in attached(html), str(attached(html)))
    fields = [entry for entry in form_fields(html, "mailform") if entry[0] != "ittabpdf_unterlagen[]"]
    status, html = post(path, fields + [("addfile", "Beilegen"), ("ittabpdf_unterlagen[]", "nutzungsbedingungen.pdf")])
    names = attached(html)
    check("chosen Unterlage is added (WAF passes)", status == 200 and "nutzungsbedingungen.pdf" in names and "agb-unternehmer.pdf" in names, f"{status} {names}")
    # The local administrator has no e-mail address: send as the company.
    fields = [entry for entry in form_fields(html, "mailform") if entry[0] not in ("ittabpdf_unterlagen[]", "sendto", "fromtype")]
    status, html = post(path, fields + [("sendto", "office@musterfirma.example"), ("fromtype", "company"), ("sendmail", "Senden")])
    time.sleep(2)
    sent = [mailpit(f"message/{item['ID']}") for item in mailpit("messages?limit=50").get("messages", [])]
    files = sorted(entry["FileName"] for detail in sent for entry in detail.get("Attachments") or [])
    check("mail goes out with the proposal and both Unterlagen", status == 200 and len(sent) == 1
          and {"agb-unternehmer.pdf", "nutzungsbedingungen.pdf"} <= set(files) and len(files) == 3,
          f"{status} {files}")

    # Private person: the B2C defaults.
    html = get(f"/comm/propal/card.php?id={result['propal_private']}&action=presend&mode=init")
    check("private default nutzungsbedingungen.pdf + widerruf.pdf", {"nutzungsbedingungen.pdf", "widerruf.pdf"} <= set(attached(html))
          and "agb-unternehmer.pdf" not in attached(html), str(attached(html)))

    # Ticket message: the box above the form.
    ticket = f"/ticket/card.php?track_id={result['ticket']}"
    html = get(f"{ticket}&action=presend_addmessage&mode=init")
    check("ticket message offers the Unterlagen", 'value="ittabpdf_attach"' in html)
    status, html = post(ticket, form_fields(html, "ittabpdf-unterlagen") + [("ittabpdf_unterlagen[]", "widerruf.pdf")])
    check("ticket message gets widerruf.pdf (WAF passes)", status == 200 and "widerruf.pdf" in attached(html), f"{status} {attached(html)}")

    # Setup: the section, saving it, writing all again.
    setup = "/custom/ittabelanderpdf/admin/setup.php"
    html = get(setup)
    check("setup lists the Unterlagen", 'name="ITTABPDF_UNTERLAGEN_CATEGORIES[]"' in html and "checkliste-uebergabe.pdf" in html)
    token = re.search(r'name="token" value="([^"]+)"', html).group(1)
    status, html = post(setup, [("token", token), ("action", "save_unterlagen"), ("ITTABPDF_UNTERLAGEN_DIR", "IT-Tabelander/Unterlagen"),
                                ("ITTABPDF_UNTERLAGEN_AUTO", "1"), ("ITTABPDF_DOCS_PROPAL_SEND_B2B[]", "agb-unternehmer.pdf"),
                                ("ITTABPDF_DOCS_PROPAL_SEND_B2C[]", "nutzungsbedingungen.pdf"), ("ITTABPDF_DOCS_PROPAL_SEND_B2C[]", "widerruf.pdf")]
                         + [("ITTABPDF_UNTERLAGEN_CATEGORIES[]", value) for value in re.findall(
                             r'<option value="(\d+)"[^>]*selected', html.split('ITTABPDF_UNTERLAGEN_CATEGORIES', 1)[1].split("</select>", 1)[0])])
    check("setup saves (WAF passes) and keeps the categories", status == 200 and "nutzungsbedingungen.pdf" in html and "Rechtstexte" in html, str(status))
    token = re.search(r'name="token" value="([^"]+)"', html).group(1)
    status, html = post(setup, [("token", token), ("action", "regenerate_unterlagen")])
    check("write all again", status == 200 and "</html>" in html, str(status))

    print(json.dumps(checks, indent=2, ensure_ascii=False))
    failed = [entry for entry in checks if not entry["ok"]]
    if failed:
        raise SystemExit(f"unterlagen check failed: {', '.join(entry['check'] for entry in failed)}")
    print(f"{len(checks)} Unterlagen checks passed")


def qs() -> None:
    """Workshop: QS checklists (tests/qs.php), then the phone page, its autosave, setup, rights and the intake slip.

    The way a technician goes on a phone: customer -> new intervention ->
    kind of job -> tick points (each saved through ajax/qs.php) -> close,
    refused while required points are open, then the test report. The
    workshop setup adds a kind of job and a point; a user without the QS
    right is turned away; the ticket card writes the intake slip. Every form
    passes Dolibarr's WAF. Example PDFs go to beispiele/.
    """
    php("enable", script="qs.php")
    result = php("run", script="qs.php")
    checks = [dict(entry, page="php") for entry in result["checks"]]
    EXAMPLES.mkdir(parents=True, exist_ok=True)
    for name in ("pruefprotokoll-1.pdf", "arbeitsbericht-mit-pruefprotokoll.pdf", "geraeteannahme-1.pdf", "geraeteannahme-2-leer.pdf"):
        docker("cp", f"{WEB}:/tmp/ittpdf-out/{name}", str(EXAMPLES / name))
    opener = browser()
    base = "/custom/ittabelanderpdf"

    def get(path: str) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", timeout=60)
            return answer.status, answer.read().decode("utf-8", "replace"), answer.geturl()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8", "replace"), ""

    def post(path: str, fields: list) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", data=urllib.parse.urlencode(fields).encode(), timeout=120)
            return answer.status, answer.read().decode("utf-8", "replace"), answer.geturl()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8", "replace"), ""

    def token(html: str) -> str:
        return re.search(r'name="token" value="([^"]+)"', html).group(1)

    def check(name: str, ok: bool, detail: str = "") -> None:
        checks.append({"page": "web", "check": name, "ok": bool(ok), "detail": detail})

    # The way on the phone: customer, new intervention, kind of job.
    status, html, _ = get(f"{base}/qs.php")
    check("start page asks for the customer", status == 200 and 'name="socid"' in html and "qs.css" in html and "qs.js" in html)
    status, html, _ = get(f"{base}/qs.php?socid={result['socid']}")
    check("customer page lists open interventions", status == 200 and "create_fichinter" in html)
    status, html, url = post(f"{base}/qs.php?socid={result['socid']}", [("token", token(html)), ("action", "create_fichinter"),
                                                                       ("description", "Vor Ort: Drucker und WLAN")])
    created = re.search(r"fichinter=(\d+)", url)
    check("new intervention from the phone (WAF passes)", status == 200 and bool(created) and "ittabqs-kind" in html, url)
    kinds = re.findall(r'name="type" value="(\d+)"', html)
    status, html, url = post(f"{base}/qs.php?fichinter={created.group(1) if created else 0}", [("token", token(html)), ("action", "start"), ("type", kinds[-1] if kinds else "0")])
    check("kind of job starts a QS", status == 200 and "id=" in url and 'ittabqs-list' in html, url)

    # Autosave on the open PC build run: tick, value, text, note.
    status, html, _ = get(f"{base}/qs.php?id={result['open_run']}")
    form = re.search(r'data-endpoint="([^"]+)" data-token="([^"]+)"', html)
    items = re.findall(r'<tr class="oddeven ittabqs-item[^"]*" data-id="(\d+)" data-kind="(\w+)" data-required="(\d)"', html)
    check("checklist shows every point", status == 200 and bool(form) and len(items) >= 10 and "ittabqs-check" in html and "ittabqs-status" in html,
          f"{len(items)} points")
    saved = []
    for item, kind, required in items:
        value = {"value": "68", "text": "MB 2309XYZ, GPU 77AB"}.get(kind, "")
        state, answer, _ = post(unescape(form.group(1)).replace(URL, ""), [("action", "saveitem"), ("token", unescape(form.group(2))),
                                                                           ("run", str(result["open_run"])), ("item", item),
                                                                           ("checked", "1" if kind == "check" else "0"), ("value", value),
                                                                           ("note", "Lüfterkurve leise" if kind == "check" and not saved else "")])
        saved.append(state == 200 and json.loads(answer or "{}").get("ok") is True)
    last = json.loads(answer or "{}")
    check("every point saves through ajax/qs.php", saved and all(saved) and last.get("counts", {}).get("required_open") == 0, json.dumps(last)[:200])
    state, answer, _ = post(unescape(form.group(1)).replace(URL, ""), [("action", "saveitem"), ("token", "falsch"), ("run", str(result["open_run"])),
                                                                       ("item", items[0][0]), ("checked", "0")])
    check("autosave without the token is refused", state != 200 or json.loads(answer or "{}").get("ok") is not True, str(state))
    status, html, _ = get(f"{base}/qs.php?id={result['open_run']}")
    status, html, _ = post(f"{base}/qs.php?id={result['open_run']}", [("token", token(html)), ("action", "close"), ("note", "Alles geprüft.")])
    check("close writes the test report and goes on to signing", status == 200 and 'id="ittabsig"' in html and "pruefprotokoll" in html)
    link = re.search(r'href="([^"]*document\.php\?modulepart=ficheinter[^"]*pruefprotokoll[^"]*)"', html)
    if link:
        with opener.open(URL + unescape(link.group(1)).replace(URL, ""), timeout=60) as answer:
            check("test report downloads", answer.status == 200 and answer.read(4) == b"%PDF")
    else:
        check("test report downloads", False, "no link")

    # Required points: a fresh QS cannot be closed and marks what is missing.
    status, html, url = get(f"{base}/qs.php?fichinter={result['fichinter']}")
    status, html, url = post(f"{base}/qs.php?fichinter={result['fichinter']}", [("token", token(html)), ("action", "start"), ("type", kinds[0] if kinds else "0")])
    run = re.search(r"id=(\d+)", url)
    status, html, url = post(f"{base}/qs.php?id={run.group(1) if run else 0}", [("token", token(html)), ("action", "close"), ("note", "")])
    check("close with open required points is refused and marks them", status == 200 and "missing=1" in url and "is-missing" in html, url)

    # Cards: QS tab on the intervention and the third party, intake slip on the ticket.
    status, html, _ = get(f"/fichinter/card.php?id={result['fichinter']}")
    check("intervention card has the QS tab", status == 200 and "ittabelanderpdf/qs.php?fichinter=" in html)
    status, html, _ = get(f"/societe/card.php?socid={result['socid']}")
    check("third party card has the QS tab", status == 200 and "ittabelanderpdf/qs.php?socid=" in html)
    status, html, _ = get(f"/ticket/card.php?track_id={result['ticket']}")
    button = re.search(r'href="([^"]*action=ittabpdf_intake[^"]*)"', html)
    check("ticket card offers the intake slip", status == 200 and bool(button))
    if button:
        status, html, _ = get(unescape(button.group(1)).replace(URL, ""))
        check("intake slip is written", status == 200 and "geraeteannahme.pdf" in html)

    # Workshop setup: a new kind of job and a new point, without code.
    status, html, _ = get(f"{base}/admin/werkstatt.php")
    check("workshop setup lists the kinds of job", status == 200 and "Controller-Umbau" in html and "ITTABPDF_INTAKE_NOTICES" in html)
    status, html, _ = post(f"{base}/admin/werkstatt.php", [("token", token(html)), ("action", "save_types"), ("label_new", "Netzwerk vor Ort"),
                                                           ("code_new", "NETZWERK"), ("position_new", "200")])
    check("new kind of job is saved (WAF passes)", status == 200 and "Netzwerk vor Ort" in html, str(status))
    type_id = re.findall(r"werkstatt\.php\?type=(\d+)", html)
    if type_id:
        status, html, _ = get(f"{base}/admin/werkstatt.php?type={type_id[-1]}")
        status, html, _ = post(f"{base}/admin/werkstatt.php?type={type_id[-1]}", [("token", token(html)), ("action", "save_items"), ("label_new", "WLAN-Durchsatz"),
                                                                                  ("kind_new", "value"), ("unit_new", "Mbit/s"), ("required_new", "1"), ("position_new", "10")])
        check("new point with unit is saved", status == 200 and "WLAN-Durchsatz" in html and "Mbit/s" in html, str(status))
    else:
        check("new point with unit is saved", False, "no type link")

    # A user without the QS right is turned away from the page and the autosave.
    other = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    page = other.open(f"{URL}/index.php", timeout=30).read().decode("utf-8", "replace")
    other.open(f"{URL}/index.php?mainmenu=home", data=urllib.parse.urlencode({
        "token": token(page), "actionlogin": "login", "loginfunction": "loginfunction",
        "username": result["plain_login"], "password": result["plain_word"]}).encode(), timeout=30).read()
    try:
        denied = other.open(f"{URL}{base}/qs.php", timeout=30)
        denied_status, denied_html = denied.status, denied.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        denied_status, denied_html = error.code, ""
    check("user without the QS right is refused", denied_status == 403 or ("ittabqs" not in denied_html and "socid" not in denied_html), str(denied_status))

    print(json.dumps(checks, indent=2, ensure_ascii=False))
    failed = [entry for entry in checks if not entry["ok"]]
    if failed:
        raise SystemExit(f"qs check failed: {', '.join(entry['check'] for entry in failed)}")
    print(f"{len(checks)} QS checks passed")


def sign() -> None:
    """Signatures on the device and sending (tests/sign.php), then the signing page in the browser.

    The page shows a pad per signer; a signature goes through ajax/sign.php as
    base64 without "data:" and "=" (the WAF lets it pass), lands in the PDF,
    and the send form mails the signed report to the address shown. Example
    PDFs go to beispiele/.
    """
    urllib.request.urlopen(urllib.request.Request(f"{MAILPIT_URL}/api/v1/messages", method="DELETE"), timeout=30)
    php("enable", script="sign.php")
    result = php("run", script="sign.php")
    checks = [dict(entry, page="php") for entry in result["checks"]]
    time.sleep(2)
    sent = [mailpit(f"message/{item['ID']}") for item in mailpit("messages?limit=50").get("messages", [])]
    for subject, attachment in (("Prüfprotokoll", "-pruefprotokoll-"), ("Geräteannahme", "-geraeteannahme.pdf")):
        mail = [detail for detail in sent if detail["Subject"].startswith(subject)]
        files = [entry["FileName"] for detail in mail for entry in detail.get("Attachments") or []]
        bcc = [entry["Address"] for detail in mail for entry in detail.get("Bcc") or []]
        checks.append({"page": "mailpit", "check": f"{subject}: one mail with the PDF and BCC", "ok": len(mail) == 1 and any(attachment in name for name in files) and bool(bcc),
                       "detail": f"{[detail['Subject'] for detail in mail]} {files} {bcc}"})
        if mail:
            MAILS.mkdir(parents=True, exist_ok=True)
            (MAILS / f"sign-{subject.lower().replace('ü', 'ue').replace('ä', 'ae')}.html").write_text(mail[0].get("HTML") or "", encoding="utf-8")
    EXAMPLES.mkdir(parents=True, exist_ok=True)
    for name in ("pruefprotokoll-unterschrieben.pdf", "geraeteannahme-unterschrieben.pdf"):
        docker("cp", f"{WEB}:/tmp/ittpdf-out/{name}", str(EXAMPLES / name))

    opener = browser()

    def get(path: str) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", timeout=60)
            return answer.status, answer.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, ""

    def post(path: str, fields: list) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", data=urllib.parse.urlencode(fields).encode(), timeout=120)
            return answer.status, answer.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, ""

    def check(name: str, ok: bool, detail: str = "") -> None:
        checks.append({"page": "web", "check": name, "ok": bool(ok), "detail": detail})

    base = "/custom/ittabelanderpdf"
    status, html = get(f"{base}/sign.php?kind=intake&id={result['ticket']}")
    check("signing page shows both signatures of the intake slip", status == 200 and html.count("ittabsig-card is-signed") == 2 and "sign.js" in html)
    status, html = get(f"{base}/sign.php?kind=qs&id={result['run']}")
    root = re.search(r'data-endpoint="([^"]+)" data-token="([^"]+)"', html)
    check("signing page offers a pad per signer", status == 200 and bool(root) and html.count("<canvas") == 2 and "disabled" in html)
    if root:
        endpoint, token = unescape(root.group(1)).replace(URL, ""), unescape(root.group(2))
        for role, name in (("technician", "Fabian Tabelander"), ("customer", "Maria Muster")):
            status, answer = post(endpoint, [("action", "sign"), ("token", token), ("kind", "qs"), ("id", str(result["run"])), ("role", role),
                                              ("name", name), ("png", result["png"])])
            check(f"{role} signs through ajax/sign.php (WAF passes)", status == 200 and json.loads(answer or "{}").get("ok") is True, f"{status} {answer[:120]}")
        status, answer = post(endpoint, [("action", "sign"), ("token", token), ("kind", "qs"), ("id", str(result["run"])), ("role", "customer"),
                                          ("name", "X"), ("png", "data:image/png;base64," + result["png"])])
        check("a data: URL does not get through", status != 200, str(status))
    status, html = get(f"{base}/sign.php?kind=qs&id={result['run']}")
    form = re.search(r'name="token" value="([^"]+)"><input type="hidden" name="action" value="send">', html)
    check("send form unlocks with every signature", status == 200 and html.count("ittabsig-card is-signed") == 2 and bool(form) and 'name="sendto"' in html)
    before = len(mailpit("messages?limit=50").get("messages", []))
    status, html = post(f"{base}/sign.php?kind=qs&id={result['run']}", [("token", form.group(1) if form else ""), ("action", "send"),
                                                                       ("sendto", "Maria Muster <office@musterfirma.example>")])
    time.sleep(2)
    after = len(mailpit("messages?limit=50").get("messages", []))
    check("send goes to the confirmed address (WAF passes)", status == 200 and after == before + 1 and "ittabsig-sent" in html, f"{status} {before}->{after}")

    print(json.dumps(checks, indent=2, ensure_ascii=False))
    failed = [entry for entry in checks if not entry["ok"]]
    if failed:
        raise SystemExit(f"sign check failed: {', '.join(entry['check'] for entry in failed)}")
    print(f"{len(checks)} signature checks passed")


def multipart(fields: list, files: list) -> tuple:
    """A multipart/form-data body (fields, then files as (name, filename, bytes, type))."""
    boundary = "----ittabpdf" + secrets.token_hex(8)
    body = b""
    for name, value in fields:
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n").encode()
    for name, filename, data, kind in files:
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"; filename=\"{filename}\"\r\n"
                 f"Content-Type: {kind}\r\n\r\n").encode() + data + b"\r\n"
    return body + f"--{boundary}--\r\n".encode(), f"multipart/form-data; boundary={boundary}"


def docs() -> None:
    """Company documents per customer (tests/docs.php), then the tab, the cards, the widget and the rules page.

    Rules decide who needs what; e-mail, proposal acceptance, signature on the
    device, upload and a tick by hand mark it; cards and the start page show
    what is missing. Every form passes Dolibarr's WAF.
    """
    php("enable", script="docs.php")
    result = php("run", script="docs.php")
    checks = [dict(entry, page="php") for entry in result["checks"]]
    EXAMPLES.mkdir(parents=True, exist_ok=True)
    docker("cp", f"{WEB}:/tmp/ittpdf-out/avv-unterschrieben.pdf", str(EXAMPLES / "avv-unterschrieben.pdf"))
    opener = browser()

    def get(path: str) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", timeout=60)
            return answer.status, answer.read().decode("utf-8", "replace"), answer.geturl()
        except urllib.error.HTTPError as error:
            return error.code, "", ""

    def post(path: str, fields: list, files: list | None = None) -> tuple:
        if files is not None:
            body, kind = multipart(fields, files)
            request = urllib.request.Request(f"{URL}{path}", data=body, headers={"Content-Type": kind})
        else:
            request = urllib.request.Request(f"{URL}{path}", data=urllib.parse.urlencode(fields).encode())
        try:
            answer = opener.open(request, timeout=120)
            return answer.status, answer.read().decode("utf-8", "replace"), answer.geturl()
        except urllib.error.HTTPError as error:
            return error.code, "", ""

    def token(html: str) -> str:
        found = re.search(r'name="token" value="([^"]+)"', html)
        if found:
            return found.group(1)
        # The tab shows its forms only on demand (?action=checkform…).
        page = get(f"{base}/docs.php?socid={result['firm']}&action=checkform&rule={result['rule_receipt']}")[1]
        return re.search(r'name="token" value="([^"]+)"', page).group(1)

    def check(name: str, ok: bool, detail: str = "") -> None:
        checks.append({"page": "web", "check": name, "ok": bool(ok), "detail": detail})

    base = "/custom/ittabelanderpdf"
    tab = f"{base}/docs.php?socid={result['firm']}"
    status, html, _ = get(tab)
    check("tab lists the documents with their state", status == 200 and "state-done" in html and "state-missing" in html and "ittabdocs-summary" in html)
    status, html, _ = post(tab, [("token", token(html)), ("action", "check"), ("rule", str(result["rule_receipt"])), ("date", "2026-11-20"),
                                 ("note", "Ausgedruckt übergeben")])
    check("tick by hand with date and note (WAF passes)", status == 200 and "Abgehakt am 20.11.2026" in html and "Ausgedruckt" in html and f'id="rule-{result["rule_receipt"]}"' in html)
    status, html, _ = post(tab, [("token", token(html)), ("action", "upload"), ("rule", str(result["rule_consent"])), ("note", "")],
                           [("docfile", "scan.pdf", b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", "application/pdf")])
    check("upload of a signed scan ticks it", status == 200 and "fernwartung-einverstaendnis-unterschrieben-" in html, str(status))
    status, html, _ = post(tab, [("token", token(html)), ("action", "upload"), ("rule", str(result["rule_consent"]))],
                           [("docfile", "boese.php", b"<?php echo 1;", "text/plain")])
    check("upload of something else is refused", status == 200 and "PDF-, JPG- oder PNG-Datei" in html, str(status))
    status, html, url = post(tab, [("token", token(html)), ("action", "startsign"), ("rule", str(result["rule_receipt"]))])
    check("sign on the device opens the signing page for the customer only", status == 200 and "kind=doc" in url and html.count("<canvas") == 1, url)
    status, html, _ = get(f"/societe/card.php?socid={result['firm']}&action=presend&mode=init&ittabpdf_preselect=agb-unternehmer.pdf")
    check("send from the tab preselects the document", status == 200 and re.search(r'attachfile_\d+">.*?agb-unternehmer\.pdf', html, re.S) is not None)
    status, html, _ = get(f"/societe/card.php?socid={result['firm']}")
    check("customer card shows what is missing", status == 200 and "ittabpdf-docs-missing" in html and "docs.php?socid=" in html)
    status, html, _ = get(f"/comm/propal/card.php?id={result['propal']}")
    check("proposal card shows it too", status == 200 and "ittabpdf-docs-missing" in html)
    status, html, _ = get(f"/contrat/card.php?id={result['contract']}")
    check("contract card shows it too", status == 200 and "ittabpdf-docs-missing" in html)
    status, html, _ = get("/index.php?mainmenu=home")
    check("start page widget", status == 200 and "Fehlende Unterlagen" in html)
    status, html, _ = get(f"{base}/admin/docrules.php")
    check("rules page lists the rules", status == 200 and "auftragsverarbeitung" in html and "products_" in html)
    status, html, _ = post(f"{base}/admin/docrules.php", [("token", token(html)), ("action", "save_rules"), ("label_new", "Datenblatt erhalten"),
                                                          ("slug_new", "firmendatenblatt"), ("action_new", "handover"), ("customertype_new", ""),
                                                          ("required_new", "1"), ("position_new", "90"), ("ITTABPDF_DOCS_CONTRACT_CHECK", "warn"),
                                                          ("ITTABPDF_DOCS_BANNER", "1")])
    check("new rule without code (WAF passes)", status == 200 and "Datenblatt erhalten" in html, str(status))

    print(json.dumps(checks, indent=2, ensure_ascii=False))
    failed = [entry for entry in checks if not entry["ok"]]
    if failed:
        raise SystemExit(f"docs check failed: {', '.join(entry['check'] for entry in failed)}")
    print(f"{len(checks)} document checks passed")


def gen() -> None:
    """Documents made for one customer (tests/gen.php), then the tab's "Erzeugen" form in the browser.

    A customer template with placeholders and input fields; required fields
    block, every placeholder is replaced, the PDF lands with the customer and
    the contract, and the state goes generated -> sent -> signed. The form is
    prefilled from last time and passes Dolibarr's WAF.
    """
    result = php("run", script="gen.php")
    checks = [dict(entry, page="php") for entry in result["checks"]]
    EXAMPLES.mkdir(parents=True, exist_ok=True)
    for name in ("vereinbarung-erzeugt.pdf", "vereinbarung-unterschrieben.pdf"):
        docker("cp", f"{WEB}:/tmp/ittpdf-out/{name}", str(EXAMPLES / name))
    opener = browser()

    def get(path: str) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", timeout=60)
            return answer.status, answer.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, ""

    def post(path: str, fields: list) -> tuple:
        try:
            answer = opener.open(f"{URL}{path}", data=urllib.parse.urlencode(fields).encode(), timeout=120)
            return answer.status, answer.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, ""

    def check(name: str, ok: bool, detail: str = "") -> None:
        checks.append({"page": "web", "check": name, "ok": bool(ok), "detail": detail})

    base = "/custom/ittabelanderpdf"
    tab = f"{base}/docs.php?socid={result['firm']}"
    status, html = get(tab)
    check("tab offers Erzeugen for the customer template", status == 200 and f"action=generateform&amp;rule={result['rule']}" in html)
    status, html = get(f"{tab}&action=generateform&rule={result['rule']}")
    token = re.search(r'name="token" value="([^"]+)"><input type="hidden" name="action" value="generate">', html)
    check("form shows contract, contact and the fields, prefilled from last time", status == 200 and bool(token) and 'name="contract"' in html
          and 'name="field_ansprech" class="minwidth300" value="Maria Muster"' in html and 'name="field_datenkategorien[]"' in html)
    fields = [("token", token.group(1) if token else ""), ("action", "generate"), ("rule", str(result["rule"])), ("contract", str(result["contract"])),
              ("field_datenkategorien[]", "Kundendaten"), ("field_datenkategorien_more", "Fotos; Gesundheitsdaten")]
    status, html = post(tab, fields + [("field_ansprech", "")])
    check("a missing required field keeps the form with a message", status == 200 and "Bitte ausfüllen" in html and 'value="generate"' in html, str(status))
    token = re.search(r'name="token" value="([^"]+)"', html)
    fields[0] = ("token", token.group(1) if token else "")
    status, html = post(tab, fields + [("field_ansprech", "Maria Muster & Co"), ("field_bemerkung", "Zugang über <VPN>")])
    check("generating through the form (WAF passes)", status == 200 and "erzeugt." in html and "Erzeugt" in html, str(status))
    status, html = get(f"{base}/admin/docrules.php")
    check("settings list the placeholders and the business hours", status == 200 and "__ITTAB_GESCHAEFTSZEITEN__" in html and "__ITTAB_PREIS:&lt;REF&gt;__" in html
          and 'name="ITTABPDF_GESCHAEFTSZEITEN"' in html)

    print(json.dumps(checks, indent=2, ensure_ascii=False))
    failed = [entry for entry in checks if not entry["ok"]]
    if failed:
        raise SystemExit(f"gen check failed: {', '.join(entry['check'] for entry in failed)}")
    print(f"{len(checks)} generate checks passed")


def main() -> int:
    for stream in (sys.stdout, sys.stderr):  # a Windows console is cp1252
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="replace")
    steps = {"up": up, "install": install, "fixtures": fixtures, "pdf": pdf, "mails": mails, "rollout": rollout, "ui": ui,
             "tickets": tickets, "unterlagen": unterlagen, "qs": qs, "sign": sign, "docs": docs, "gen": gen, "down": down}
    wanted = sys.argv[1:] or ["all"]
    if wanted[0] == "pdf" and len(wanted) == 2:
        pdf(wanted[1])
        return 0
    for step in wanted:
        if step == "all":
            for name in ("up", "install", "fixtures", "pdf", "mails", "rollout", "ui", "tickets", "unterlagen", "qs", "sign", "docs", "gen"):
                steps[name]()
        elif step in steps:
            steps[step]()
        else:
            raise SystemExit(f"unknown step {step}; choose from {', '.join(steps)} or all")
    return 0


if __name__ == "__main__":
    sys.exit(main())

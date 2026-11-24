#!/usr/bin/env python3
"""A disposable local Dolibarr 23.0.3 to render the Werkbank PDFs and e-mails against.

Same stack as IT-Tabelander's local check (scripts/local_check.py, group
"dolibarr"): Dolibarr's own docker image built at a pinned commit, MariaDB,
Mailpit catching every e-mail, no data that survives. Never talks to the
production ERP.

    python scripts/dev_dolibarr.py up         start Dolibarr + MariaDB + Mailpit (fresh install)
    python scripts/dev_dolibarr.py install    build the ZIP and unpack it into custom/
    python scripts/dev_dolibarr.py fixtures   company, bank account, customers, documents
    python scripts/dev_dolibarr.py pdf [name] render the examples (or those matching name) into docs/beispiele/
    python scripts/dev_dolibarr.py mails      e-mail scenarios, then Mailpit's messages into docs/beispiele/mails/
    python scripts/dev_dolibarr.py rollout    Mahnwesen and ticket texts into docs/rollout/, sent to Mailpit
    python scripts/dev_dolibarr.py ui         log in and check the card banner and the queue page
    python scripts/dev_dolibarr.py all        up, install, fixtures, pdf, mails, rollout, ui
    python scripts/dev_dolibarr.py down       remove containers, volumes and network

Standard library only. Passwords are new on every start; the local test
administrator's login is kept in .local-testing/ (git-ignored) for the ui step.
"""

from __future__ import annotations

import http.cookiejar
import json
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
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / ".local-testing"
EXAMPLES = ROOT / "docs" / "beispiele"
MAILS = EXAMPLES / "mails"
IMAGE = "local-ci/dolibarr:23.0.3"
BUILD = "https://github.com/Dolibarr/dolibarr-docker.git#73658fe7060709b3bc798e646284775ec0020101:images/23.0.3-php8.2"
MARIADB = "mariadb:11.4.13"
MAILPIT = "axllent/mailpit:v1.31.1"
PREFIX = "ittpdf-dolibarr"
NETWORK = f"{PREFIX}-net"
WEB = f"{PREFIX}-web"
DB = f"{PREFIX}-db"
MAIL = f"{PREFIX}-mail"
PORT = 18051
MAIL_PORT = 18151
URL = f"http://127.0.0.1:{PORT}"
MAILPIT_URL = f"http://127.0.0.1:{MAIL_PORT}"
FIXTURES = "/opt/ittpdf-fixtures"
MODULE = "ittabelanderpdf"
LOGIN = STATE / "admin-login.json"


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


def php(*args: str) -> dict:
    done = docker("exec", "-u", "www-data", WEB, "php", f"{FIXTURES}/fixtures.php", *args, check=False)
    if done.returncode != 0:
        raise SystemExit(f"fixtures {' '.join(args)} failed:\n{done.stdout}{done.stderr}")
    return json.loads(done.stdout.strip().splitlines()[-1])


def fixtures() -> None:
    php("base")
    print(json.dumps(php("setup"), indent=2, ensure_ascii=False))


def pdf(only: str = "") -> None:
    """Every sample document rendered with werkbank, copied to docs/beispiele/."""
    print(json.dumps(php("render", only), indent=2, ensure_ascii=False))
    EXAMPLES.mkdir(parents=True, exist_ok=True)
    docker("cp", f"{WEB}:/tmp/ittpdf-out/.", str(EXAMPLES))
    for target in sorted(EXAMPLES.glob("*.pdf")):
        print(f"{target.relative_to(ROOT)}  {target.stat().st_size // 1024} KB")


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
    print(f"{len(summary)} messages -> {MAILS.relative_to(ROOT)}")


def rollout() -> None:
    """Texts for production outside this module, into docs/rollout/, and their mails."""
    result = php("rollout")
    target = ROOT / "docs" / "rollout"
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
    print(f"{number} rollout messages -> {MAILS.relative_to(ROOT)}")


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

    look("queue", "/custom/ittabelanderpdf/queue.php", ["Automatischer Versand", "Gesendet"])
    look("setup", "/custom/ittabelanderpdf/admin/setup.php", ["ITTABPDF_AUTOSEND_DELAY", "Angebot (Sie)"])
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


def main() -> int:
    steps = {"up": up, "install": install, "fixtures": fixtures, "pdf": pdf, "mails": mails, "rollout": rollout, "ui": ui, "down": down}
    wanted = sys.argv[1:] or ["all"]
    if wanted[0] == "pdf" and len(wanted) == 2:
        pdf(wanted[1])
        return 0
    for step in wanted:
        if step == "all":
            for name in ("up", "install", "fixtures", "pdf", "mails", "rollout", "ui"):
                steps[name]()
        elif step in steps:
            steps[step]()
        else:
            raise SystemExit(f"unknown step {step}; choose from {', '.join(steps)} or all")
    return 0


if __name__ == "__main__":
    sys.exit(main())

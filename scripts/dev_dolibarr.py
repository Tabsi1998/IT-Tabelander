#!/usr/bin/env python3
"""A disposable local Dolibarr 23.0.3 to render the Werkbank PDFs against.

Same stack as IT-Tabelander's local check (scripts/local_check.py, group
"dolibarr"): Dolibarr's own docker image built at a pinned commit, MariaDB, no
data that survives. Never talks to the production ERP.

    python scripts/dev_dolibarr.py up         start Dolibarr + MariaDB (fresh install)
    python scripts/dev_dolibarr.py install    build the ZIP and unpack it into custom/
    python scripts/dev_dolibarr.py fixtures   company, bank account, customers, documents
    python scripts/dev_dolibarr.py pdf [name] render the examples (or those matching name) into docs/beispiele/
    python scripts/dev_dolibarr.py all        the four steps above in a row
    python scripts/dev_dolibarr.py down       remove containers, volumes and network

Standard library only. Passwords are new on every start and never printed.
"""

from __future__ import annotations

import json
import secrets
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / ".local-testing"
EXAMPLES = ROOT / "docs" / "beispiele"
IMAGE = "local-ci/dolibarr:23.0.3"
BUILD = "https://github.com/Dolibarr/dolibarr-docker.git#73658fe7060709b3bc798e646284775ec0020101:images/23.0.3-php8.2"
MARIADB = "mariadb:11.4.13"
PREFIX = "ittpdf-dolibarr"
NETWORK = f"{PREFIX}-net"
WEB = f"{PREFIX}-web"
DB = f"{PREFIX}-db"
PORT = 18051
URL = f"http://127.0.0.1:{PORT}"
FIXTURES = "/opt/ittpdf-fixtures"
MODULE = "ittabelanderpdf"


def docker(*args: str, check: bool = True, capture: bool = True) -> subprocess.CompletedProcess:
    binary = shutil.which("docker") or r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    done = subprocess.run([binary, *args], capture_output=capture, text=True, encoding="utf-8", errors="replace")
    if check and done.returncode != 0:
        raise SystemExit(f"docker {' '.join(args[:3])} failed:\n{done.stdout}{done.stderr}")
    return done


def down() -> None:
    for name in (WEB, DB):
        docker("rm", "--force", "--volumes", name, check=False)
    docker("network", "rm", NETWORK, check=False)
    print("removed", WEB, DB, NETWORK)


def up() -> None:
    if docker("image", "inspect", IMAGE, check=False).returncode != 0:
        docker("build", "--tag", IMAGE, BUILD)
    down()
    password = secrets.token_urlsafe(18)
    docker("network", "create", NETWORK)
    docker("run", "--detach", "--name", DB, "--network", NETWORK, "--network-alias", "db",
           "--tmpfs", "/var/lib/mysql", "--env", f"MARIADB_ROOT_PASSWORD={password}",
           "--env", "MARIADB_DATABASE=dolibarr", "--env", "MARIADB_USER=dolibarr",
           "--env", f"MARIADB_PASSWORD={password}", "--env", "TZ=Europe/Vienna", MARIADB)
    docker("run", "--detach", "--name", WEB, "--network", NETWORK,
           "--publish", f"127.0.0.1:{PORT}:80",
           "--mount", f"type=bind,source={ROOT / 'tests'},target={FIXTURES},readonly",
           "--env", "DOLI_DB_HOST=db", "--env", "DOLI_DB_NAME=dolibarr", "--env", "DOLI_DB_USER=dolibarr",
           "--env", f"DOLI_DB_PASSWORD={password}", "--env", "DOLI_ADMIN_LOGIN=admin",
           "--env", f"DOLI_ADMIN_PASSWORD={secrets.token_urlsafe(18)}", "--env", "DOLI_INSTALL_AUTO=1",
           "--env", f"DOLI_URL_ROOT={URL}", "--env", "DOLI_COMPANY_COUNTRYCODE=AT",
           "--env", "DOLI_COMPANY_NAME=IT-Tabelander", "--env", "DOLI_PROD=0",
           "--env", "PHP_INI_DATE_TIMEZONE=Europe/Vienna", IMAGE)
    deadline = time.time() + 420
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(f"{URL}/index.php", timeout=5) as answer:
                if answer.status == 200:
                    print(f"Dolibarr 23.0.3 on {URL}")
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


def main() -> int:
    steps = {"up": up, "install": install, "fixtures": fixtures, "pdf": pdf, "down": down}
    wanted = sys.argv[1:] or ["all"]
    if wanted[0] == "pdf" and len(wanted) == 2:
        pdf(wanted[1])
        return 0
    for step in wanted:
        if step == "all":
            for name in ("up", "install", "fixtures", "pdf"):
                steps[name]()
        elif step in steps:
            steps[step]()
        else:
            raise SystemExit(f"unknown step {step}; choose from {', '.join(steps)} or all")
    return 0


if __name__ == "__main__":
    sys.exit(main())

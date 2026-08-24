#!/usr/bin/env bash
# Installiert fehlende Voraussetzungen, baut die App und startet den Webdienst.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

BACKEND_HOST="127.0.0.1"
BACKEND_PORT="8001"
BACKEND_WORKERS="1"
PYTHON_BIN="python3"
HEALTHCHECK_HOST="127.0.0.1"
STARTUP_TIMEOUT_SECONDS="30"
[[ -f "$SCRIPT_DIR/deploy.config" ]] && source "$SCRIPT_DIR/deploy.config"

RUN_DIR="$SCRIPT_DIR/run"
LOG_DIR="$SCRIPT_DIR/logs"
BACKEND_DIR="$SCRIPT_DIR/backend"
FRONTEND_DIR="$SCRIPT_DIR/frontend"
VENV_DIR="$BACKEND_DIR/venv"
BUILD_DIR="$FRONTEND_DIR/build"
STAGED_BUILD_DIR="$RUN_DIR/frontend-build.next"
mkdir -p "$RUN_DIR" "$LOG_DIR"

PREPARE_ONLY=0
FORCE_REFRESH=0
AUTO_INSTALL_SYSTEM=1
RESET_ADMIN=0
for argument in "$@"; do
  case "$argument" in
    --prepare) PREPARE_ONLY=1 ;;
    --refresh) FORCE_REFRESH=1 ;;
    --reset-admin) RESET_ADMIN=1 ;;
    --no-system-install) AUTO_INSTALL_SYSTEM=0 ;;
    -h|--help)
      echo "Verwendung: ./start.sh [--prepare] [--refresh] [--reset-admin] [--no-system-install]"
      echo "  --prepare  Abhängigkeiten installieren und Frontend bauen, nichts starten"
      echo "  --refresh  Installation und Build auch ohne erkannte Änderungen erneuern"
      echo "  --reset-admin  Neues Admin-Passwort erzeugen und beim nächsten Start setzen"
      echo "  --no-system-install  Keine fehlenden Ubuntu-Systempakete installieren"
      exit 0
      ;;
    *) echo "Unbekannte Option: $argument" >&2; exit 2 ;;
  esac
done

green()  { printf "\033[0;32m%s\033[0m\n" "$1"; }
yellow() { printf "\033[0;33m%s\033[0m\n" "$1"; }
red()    { printf "\033[0;31m%s\033[0m\n" "$1" >&2; }
die()    { red "✗ $1"; exit 1; }

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "Benötigtes Programm fehlt: $1"
}

run_as_root() {
  if (( EUID == 0 )); then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    die "Für die automatische Systeminstallation werden root-Rechte oder sudo benötigt."
  fi
}

APT_UPDATED=0
apt_update() {
  (( APT_UPDATED == 1 )) && return 0
  green "→ Ubuntu-Paketlisten aktualisieren ..."
  run_as_root env DEBIAN_FRONTEND=noninteractive apt-get update
  APT_UPDATED=1
}

apt_install() {
  apt_update
  run_as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y "$@"
}

bootstrap_system_dependencies() {
  local node_major="" setup_script=""
  (( AUTO_INSTALL_SYSTEM == 1 )) || return 0
  if [[ "$(uname -s 2>/dev/null || true)" != "Linux" ]] || ! command -v apt-get >/dev/null 2>&1; then
    yellow "⚠ Automatische Systeminstallation wird nur auf Ubuntu/Debian mit apt unterstützt."
    return 0
  fi

  if ! command -v curl >/dev/null 2>&1 || ! command -v gpg >/dev/null 2>&1 \
    || ! command -v "$PYTHON_BIN" >/dev/null 2>&1 || ! command -v flock >/dev/null 2>&1 \
    || ! command -v ps >/dev/null 2>&1 || ! "$PYTHON_BIN" -m venv --help >/dev/null 2>&1; then
    green "→ Fehlende System-Grundpakete installieren ..."
    apt_install ca-certificates curl git gnupg python3 python3-pip python3-venv procps util-linux
  fi

  if command -v node >/dev/null 2>&1; then
    node_major="$(node -p 'Number(process.versions.node.split(".")[0])' 2>/dev/null || true)"
  fi
  if [[ ! "$node_major" =~ ^[0-9]+$ ]] || (( node_major < 20 )); then
    green "→ Node.js 24 LTS installieren ..."
    setup_script="$(mktemp "${TMPDIR:-/tmp}/it-tabelander-nodesource.XXXXXX")"
    curl -fsSL https://deb.nodesource.com/setup_24.x -o "$setup_script"
    run_as_root bash "$setup_script"
    rm -f -- "$setup_script"
    apt_install nodejs
  fi

  if ! command -v yarn >/dev/null 2>&1 || [[ "$(yarn --version 2>/dev/null || true)" != "1.22.22" ]]; then
    green "→ Yarn 1.22.22 installieren ..."
    run_as_root npm install --global yarn@1.22.22
  fi
}

bootstrap_lock_dependencies() {
  command -v flock >/dev/null 2>&1 && command -v readlink >/dev/null 2>&1 && return 0
  if (( AUTO_INSTALL_SYSTEM == 1 )) && command -v apt-get >/dev/null 2>&1; then
    green "→ Deployment-Lock-Werkzeuge installieren ..."
    apt_install coreutils util-linux
  fi
}

[[ "$BACKEND_PORT" =~ ^[0-9]+$ ]] || die "BACKEND_PORT muss eine Zahl sein."
[[ "$BACKEND_WORKERS" =~ ^[1-9][0-9]*$ ]] || die "BACKEND_WORKERS muss mindestens 1 sein."
[[ "$STARTUP_TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]] || die "STARTUP_TIMEOUT_SECONDS muss mindestens 1 sein."

bootstrap_lock_dependencies
require_command flock
require_command readlink
lock_is_inherited() {
  [[ "${IT_TABELANDER_DEPLOY_LOCK_HELD:-0}" == "1" ]] || return 1
  [[ -e "/proc/$$/fd/9" ]] || return 1
  [[ "$(readlink -f "/proc/$$/fd/9" 2>/dev/null)" == "$(readlink -f "$RUN_DIR/deploy.lock" 2>/dev/null)" ]]
}
if ! lock_is_inherited; then
  exec 9>"$RUN_DIR/deploy.lock"
  flock -n 9 || die "Ein anderer Start-, Stop- oder Update-Vorgang läuft bereits."
  export IT_TABELANDER_DEPLOY_LOCK_HELD=1
fi

bootstrap_system_dependencies

require_command "$PYTHON_BIN"
require_command node
require_command yarn
require_command curl
require_command mktemp
require_command tail

if ! "$PYTHON_BIN" -c 'import sys; raise SystemExit(0 if (3, 10) <= sys.version_info[:2] <= (3, 14) else 1)'; then
  die "$PYTHON_BIN muss Python 3.10 bis 3.14 sein (gefunden: $("$PYTHON_BIN" --version 2>&1))."
fi

NODE_MAJOR="$(node -p 'Number(process.versions.node.split(".")[0])')"
[[ "$NODE_MAJOR" =~ ^[0-9]+$ ]] || die "Node.js-Version konnte nicht ermittelt werden."
(( NODE_MAJOR >= 20 )) || die "Node.js 20 oder neuer wird benötigt (gefunden: $(node --version))."

YARN_VERSION="$(yarn --version)"
if [[ "$YARN_VERSION" != "1.22.22" ]]; then
  die "Yarn 1.22.22 wird benötigt (gefunden: $YARN_VERSION)."
fi

read_pid() {
  local pidfile="$1" pid
  [[ -r "$pidfile" ]] || return 1
  IFS= read -r pid < "$pidfile" || return 1
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || return 1
  printf '%s' "$pid"
}

process_command() {
  local pid="$1"
  if [[ -r "/proc/$pid/cmdline" ]]; then
    tr '\0' ' ' < "/proc/$pid/cmdline"
  else
    ps -p "$pid" -o args= 2>/dev/null
  fi
}

process_workdir() {
  readlink -f "/proc/$1/cwd" 2>/dev/null
}

process_belongs_to_project() {
  local service="$1" pid="$2" command_line
  local process_dir=""
  command_line="$(process_command "$pid")" || return 1
  process_dir="$(process_workdir "$pid" || true)"
  [[ "$service" == "backend" ]] || return 1
  [[ "$command_line" == *uvicorn* && "$command_line" == *server:app* ]] \
    && [[ "$process_dir" == "$BACKEND_DIR" || "$command_line" == *"$VENV_DIR/"* ]]
}

process_matches_current_config() {
  local service="$1" pid="$2" command_line
  process_belongs_to_project "$service" "$pid" || return 1
  command_line="$(process_command "$pid")" || return 1
  [[ "$service" == "backend" && "$command_line" == *"--port $BACKEND_PORT"* ]]
}

running_pid() {
  local service="$1" pidfile="$2" pid
  pid="$(read_pid "$pidfile")" || return 1
  kill -0 "$pid" 2>/dev/null || return 1
  process_matches_current_config "$service" "$pid" || return 1
  printf '%s' "$pid"
}

clear_stale_pidfile() {
  local service="$1" pidfile="$2" pid=""
  [[ -e "$pidfile" ]] || return 0
  pid="$(read_pid "$pidfile" 2>/dev/null || true)"
  if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
    if process_belongs_to_project "${service,,}" "$pid"; then
      die "$service läuft mit einer anderen Port-Konfiguration (PID $pid). Bitte zuerst ./stop.sh ausführen."
    fi
    yellow "⚠ $service-PID-Datei zeigte auf einen fremden Prozess (PID $pid); sie wird entfernt."
  else
    yellow "· Veraltete $service-PID-Datei entfernt."
  fi
  rm -f -- "$pidfile"
}

write_pid() {
  local pidfile="$1" pid="$2" temporary
  temporary="$pidfile.tmp.$$"
  if ! printf '%s\n' "$pid" > "$temporary"; then
    rm -f -- "$temporary"
    return 1
  fi
  if ! mv -f -- "$temporary" "$pidfile"; then
    rm -f -- "$temporary"
    return 1
  fi
}

process_target() {
  local pid="$1" pgid="" target
  pgid="$(ps -o pgid= -p "$pid" 2>/dev/null | tr -d '[:space:]' || true)"
  target="$pid"
  [[ "$pgid" == "$pid" ]] && target="-$pid"
  printf '%s' "$target"
}

target_is_alive() {
  kill -0 -- "$1" 2>/dev/null
}

terminate_process_tree() {
  local pid="$1" target attempt
  if target_is_alive "-$pid"; then
    target="-$pid"
  elif kill -0 "$pid" 2>/dev/null; then
    target="$(process_target "$pid")"
  else
    return 0
  fi
  kill -TERM -- "$target" 2>/dev/null || target_is_alive "$target" || return 0
  for attempt in {1..20}; do
    target_is_alive "$target" || return 0
    sleep 0.25
  done
  kill -KILL -- "$target" 2>/dev/null || target_is_alive "$target" || return 0
  for attempt in {1..8}; do
    target_is_alive "$target" || return 0
    sleep 0.25
  done
  return 1
}

remove_pidfile_if_matching() {
  local pidfile="$1" expected_pid="$2" stored_pid=""
  stored_pid="$(read_pid "$pidfile" 2>/dev/null || true)"
  if [[ "$stored_pid" == "$expected_pid" ]]; then
    rm -f -- "$pidfile"
  fi
}

LAUNCHED_BACKEND_PID=""
BUILD_TMP=""
cleanup_on_exit() {
  local status=$?
  trap - EXIT INT TERM
  trap '' INT TERM
  set +e
  if (( status != 0 )); then
    red "Start abgebrochen; neu gestartete Prozesse werden aufgeräumt."
    if [[ -n "$BUILD_TMP" && -d "$BUILD_TMP" ]]; then
      if [[ ! -e "$BUILD_DIR" && -d "$BUILD_TMP/previous" ]]; then
        if ! mv -- "$BUILD_TMP/previous" "$BUILD_DIR"; then
          red "Letzter Frontend-Build konnte nicht wiederhergestellt werden und bleibt in $BUILD_TMP/previous."
          BUILD_TMP=""
        fi
      fi
      if [[ -n "$BUILD_TMP" && ! -e "$STAGED_BUILD_DIR" && -d "$BUILD_TMP/previous-stage" ]]; then
        if ! mv -- "$BUILD_TMP/previous-stage" "$STAGED_BUILD_DIR"; then
          red "Vorheriger Staging-Build bleibt zur Wiederherstellung in $BUILD_TMP/previous-stage."
          BUILD_TMP=""
        fi
      fi
      [[ -n "$BUILD_TMP" ]] && rm -rf -- "$BUILD_TMP"
    fi
    if [[ "$LAUNCHED_BACKEND_PID" =~ ^[1-9][0-9]*$ ]]; then
      if terminate_process_tree "$LAUNCHED_BACKEND_PID"; then
        remove_pidfile_if_matching "$RUN_DIR/backend.pid" "$LAUNCHED_BACKEND_PID"
      else
        red "Backend-Prozessgruppe $LAUNCHED_BACKEND_PID konnte nicht vollständig beendet werden; PID-Datei bleibt erhalten."
      fi
    fi
  fi
  exit "$status"
}
trap cleanup_on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

ensure_backend_environment() {
  local env_file="$BACKEND_DIR/.env"
  local reset_marker="$RUN_DIR/reset-admin.pending"
  "$PYTHON_BIN" - "$SCRIPT_DIR/.env.example" "$env_file" "$reset_marker" "$RESET_ADMIN" <<'PY'
import os
import re
import secrets
import sys
import hashlib
from pathlib import Path

template_path, env_path, marker_path = map(Path, sys.argv[1:4])
force_reset = sys.argv[4] == "1"
content = env_path.read_text(encoding="utf-8") if env_path.exists() else template_path.read_text(encoding="utf-8")

def value(key: str) -> str:
    match = re.search(rf"(?m)^{re.escape(key)}=(.*)$", content)
    return match.group(1).strip().strip('"').strip("'") if match else ""

def replace(key: str, new_value: str) -> None:
    global content
    line = f"{key}={new_value}"
    pattern = rf"(?m)^{re.escape(key)}=.*$"
    content = re.sub(pattern, line, content) if re.search(pattern, content) else content.rstrip() + "\n" + line + "\n"

created = not env_path.exists()
admin_changed = created or force_reset
if created or len(value("JWT_SECRET")) < 32 or value("JWT_SECRET").startswith("CHANGE_ME"):
    replace("JWT_SECRET", secrets.token_hex(48))

admin_email = value("ADMIN_EMAIL")
if not admin_email or admin_email.startswith("CHANGE_ME"):
    replace("ADMIN_EMAIL", "admin@it-tabelander.at")
    admin_changed = True

admin_password = value("ADMIN_PASSWORD")
if (not admin_password or admin_password.startswith("CHANGE_ME")
        or hashlib.sha256(admin_password.encode()).hexdigest() == "c5d84310fae2bb8ff8300a2c396304112963a4705ae4bd463fd8f9d621abf822"
        or force_reset):
    replace("ADMIN_PASSWORD", secrets.token_urlsafe(18))
    admin_changed = True

env_path.parent.mkdir(parents=True, exist_ok=True)
temporary = env_path.with_name(f".{env_path.name}.tmp.{os.getpid()}")
temporary.write_text(content, encoding="utf-8", newline="\n")
os.chmod(temporary, 0o600)
os.replace(temporary, env_path)
os.chmod(env_path, 0o600)
if admin_changed:
    marker_path.write_text("pending\n", encoding="utf-8")
    os.chmod(marker_path, 0o600)
PY

  if [[ -f "$reset_marker" ]]; then
    export IT_TABELANDER_RESET_ADMIN=1
    green "✓ Sichere Admin-Zugangsdaten wurden automatisch erzeugt"
  fi
}

env_value() {
  "$PYTHON_BIN" - "$BACKEND_DIR/.env" "$1" <<'PY'
import re
import sys
from pathlib import Path

content = Path(sys.argv[1]).read_text(encoding="utf-8")
match = re.search(rf"(?m)^{re.escape(sys.argv[2])}=(.*)$", content)
print(match.group(1).strip().strip('"').strip("'") if match else "")
PY
}

install_and_start_mongodb() {
  local mongo_url os_id="" codename="" architecture="" key_tmp="" source_tmp=""
  mongo_url="$(env_value MONGO_URL)"
  if [[ "$mongo_url" != mongodb://127.0.0.1* && "$mongo_url" != mongodb://localhost* \
    && "$mongo_url" != mongodb://\[::1\]* ]]; then
    green "✓ Externe MongoDB ist konfiguriert; lokale Installation wird übersprungen"
    return 0
  fi

  if ! command -v mongod >/dev/null 2>&1; then
    if (( AUTO_INSTALL_SYSTEM == 0 )); then
      yellow "⚠ MongoDB fehlt; automatische Installation wurde deaktiviert."
      return 0
    fi
    [[ -r /etc/os-release ]] || die "MongoDB kann automatisch nur auf Ubuntu installiert werden."
    os_id="$(. /etc/os-release && printf '%s' "${ID:-}")"
    codename="$(. /etc/os-release && printf '%s' "${VERSION_CODENAME:-}")"
    [[ "$os_id" == "ubuntu" ]] || die "MongoDB-Autoinstallation unterstützt Ubuntu; gefunden: ${os_id:-unbekannt}."
    [[ "$codename" == "focal" || "$codename" == "jammy" || "$codename" == "noble" ]] \
      || die "MongoDB 8.0 unterstützt hier automatisch Ubuntu 20.04/22.04/24.04; gefunden: ${codename:-unbekannt}."
    architecture="$(dpkg --print-architecture)"
    [[ "$architecture" == "amd64" || "$architecture" == "arm64" ]] \
      || die "MongoDB-Autoinstallation unterstützt amd64/arm64; gefunden: $architecture."

    green "→ MongoDB 8.0 Community installieren ..."
    key_tmp="$(mktemp "${TMPDIR:-/tmp}/it-tabelander-mongodb-key.XXXXXX")"
    source_tmp="$(mktemp "${TMPDIR:-/tmp}/it-tabelander-mongodb-source.XXXXXX")"
    curl -fsSL https://pgp.mongodb.com/server-8.0.asc -o "$key_tmp"
    run_as_root gpg --batch --yes --dearmor -o /usr/share/keyrings/mongodb-server-8.0.gpg "$key_tmp"
    printf 'deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg ] https://repo.mongodb.org/apt/ubuntu %s/mongodb-org/8.0 multiverse\n' "$codename" > "$source_tmp"
    run_as_root install -m 0644 "$source_tmp" /etc/apt/sources.list.d/mongodb-org-8.0.list
    rm -f -- "$key_tmp" "$source_tmp"
    APT_UPDATED=0
    apt_install mongodb-org
  fi

  green "→ MongoDB aktivieren und starten ..."
  if command -v systemctl >/dev/null 2>&1; then
    if ! run_as_root systemctl enable --now mongod; then
      run_as_root systemctl daemon-reload
      run_as_root systemctl enable --now mongod
    fi
  elif command -v service >/dev/null 2>&1; then
    run_as_root service mongod start
  else
    yellow "⚠ Kein unterstütztes Init-System gefunden; MongoDB muss bereits laufen."
  fi
}

prepare_backend() {
  local requirements="$BACKEND_DIR/requirements.txt"
  local stamp="$VENV_DIR/.requirements.sha256"
  local selected_minor venv_minor="" venv_has_pip=0 requirements_hash installed_hash=""
  local install_needed=0

  [[ -f "$requirements" ]] || die "Backend-Requirements fehlen: $requirements"
  selected_minor="$("$PYTHON_BIN" -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')"

  if [[ -x "$VENV_DIR/bin/python" ]]; then
    venv_minor="$("$VENV_DIR/bin/python" -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")' 2>/dev/null || true)"
    if "$VENV_DIR/bin/python" -m pip --version >/dev/null 2>&1; then
      venv_has_pip=1
    fi
  fi
  if [[ -d "$VENV_DIR" && ( "$venv_minor" != "$selected_minor" || "$venv_has_pip" != "1" ) ]]; then
    yellow "→ Unvollständiges oder falsches Python-venv wird neu angelegt ..."
    rm -rf -- "$VENV_DIR"
  fi
  if [[ ! -d "$VENV_DIR" ]]; then
    green "→ Python-venv mit $PYTHON_BIN anlegen ..."
    "$PYTHON_BIN" -m venv "$VENV_DIR" || die "venv konnte nicht angelegt werden (unter Ubuntu ggf. python3-venv installieren)."
  fi

  requirements_hash="$("$PYTHON_BIN" -c 'import hashlib, pathlib, sys; p=pathlib.Path(sys.argv[1]); data=p.read_bytes()+f"\npython={sys.version_info.major}.{sys.version_info.minor}".encode(); print(hashlib.sha256(data).hexdigest())' "$requirements")"
  [[ -r "$stamp" ]] && installed_hash="$(< "$stamp")"

  if (( FORCE_REFRESH == 1 )) || [[ ! -x "$VENV_DIR/bin/uvicorn" || "$installed_hash" != "$requirements_hash" ]]; then
    install_needed=1
  elif ! "$VENV_DIR/bin/python" -m pip check >/dev/null 2>&1 \
    || ! "$VENV_DIR/bin/python" -c 'import bcrypt, fastapi, httpx, jwt, PIL, pydantic, pymongo, uvicorn; from pymongo import AsyncMongoClient' >/dev/null 2>&1; then
    yellow "⚠ Backend-venv ist unvollständig und wird repariert."
    install_needed=1
  fi

  if (( install_needed == 1 )); then
    green "→ Backend-Abhängigkeiten installieren ..."
    rm -f -- "$stamp"
    PIP_DISABLE_PIP_VERSION_CHECK=1 "$VENV_DIR/bin/python" -m pip install \
      --no-input --no-cache-dir --requirement "$requirements"
    "$VENV_DIR/bin/python" -m pip check
    "$VENV_DIR/bin/python" -c 'import bcrypt, fastapi, httpx, jwt, PIL, pydantic, pymongo, uvicorn; from pymongo import AsyncMongoClient'
    printf '%s\n' "$requirements_hash" > "$stamp.tmp"
    mv -f -- "$stamp.tmp" "$stamp"
    green "✓ Backend-Abhängigkeiten vollständig"
  else
    green "✓ Backend-Abhängigkeiten unverändert"
  fi
}

write_frontend_environment() {
  local env_file="$FRONTEND_DIR/.env.production"
  local expected="REACT_APP_BACKEND_URL="
  if [[ ! -f "$env_file" || "$(< "$env_file")" != "$expected" ]]; then
    printf '%s\n' "$expected" > "$env_file"
  fi
}

frontend_dependency_hash() {
  node - "$FRONTEND_DIR" <<'NODE'
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const root = process.argv[2];
const hash = crypto.createHash('sha256');
for (const name of ['package.json', 'yarn.lock']) {
  hash.update(name + '\0');
  hash.update(fs.readFileSync(path.join(root, name)));
  hash.update('\0');
}
console.log(hash.digest('hex'));
NODE
}

frontend_build_input_hash() {
  node - "$FRONTEND_DIR" <<'NODE'
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const root = process.argv[2];
const files = [];

function collect(relativeDirectory) {
  const absoluteDirectory = path.join(root, relativeDirectory);
  for (const entry of fs.readdirSync(absoluteDirectory, {withFileTypes: true})) {
    const relative = path.join(relativeDirectory, entry.name);
    if (entry.isDirectory()) collect(relative);
    else if (entry.isFile()) files.push(relative);
  }
}

collect('src');
collect('public');
for (const name of ['package.json', 'yarn.lock', '.env.production',
                    'postcss.config.js', 'tailwind.config.js']) {
  if (fs.existsSync(path.join(root, name))) files.push(name);
}
files.sort();

const hash = crypto.createHash('sha256');
for (const relative of files) {
  hash.update(relative.split(path.sep).join('/') + '\0');
  hash.update(fs.readFileSync(path.join(root, relative)));
  hash.update('\0');
}
console.log(hash.digest('hex'));
NODE
}

prepare_frontend() {
  local stamp="$FRONTEND_DIR/node_modules/.yarn-install-ok"
  local install_needed=0 build_needed=0 new_build previous_stage
  local dependency_hash installed_hash="" build_input_hash baseline_dir="$BUILD_DIR"

  [[ -f "$FRONTEND_DIR/yarn.lock" ]] || die "frontend/yarn.lock fehlt; ein reproduzierbares Install ist nicht möglich."
  write_frontend_environment
  dependency_hash="$(frontend_dependency_hash)"
  [[ -r "$stamp" ]] && installed_hash="$(< "$stamp")"

  if (( FORCE_REFRESH == 1 )) || [[ ! -x "$FRONTEND_DIR/node_modules/.bin/react-scripts" || "$installed_hash" != "$dependency_hash" ]]; then
    install_needed=1
  fi
  if (( install_needed == 1 )); then
    green "→ Frontend-Abhängigkeiten aus yarn.lock installieren ..."
    rm -f -- "$stamp"
    (
      cd "$FRONTEND_DIR"
      yarn install --frozen-lockfile --non-interactive --production=false
    )
    printf '%s\n' "$dependency_hash" > "$stamp.tmp"
    mv -f -- "$stamp.tmp" "$stamp"
    green "✓ Frontend-Abhängigkeiten vollständig"
  else
    green "✓ Frontend-Abhängigkeiten unverändert"
  fi

  build_input_hash="$(frontend_build_input_hash)"
  [[ -f "$STAGED_BUILD_DIR/index.html" ]] && baseline_dir="$STAGED_BUILD_DIR"
  if (( FORCE_REFRESH == 1 )) || [[ ! -f "$baseline_dir/index.html" || ! -r "$baseline_dir/.build-input.sha256" || "$(< "$baseline_dir/.build-input.sha256")" != "$build_input_hash" ]]; then
    build_needed=1
  fi

  if (( build_needed == 0 )); then
    if [[ "$baseline_dir" == "$STAGED_BUILD_DIR" ]]; then
      green "✓ Frontend-Build ist aktuell (bereitgestellt)"
    else
      green "✓ Frontend-Build ist aktuell"
    fi
    return
  fi

  green "→ Frontend neu bauen und für den nächsten Start bereitstellen ..."
  BUILD_TMP="$(mktemp -d "$RUN_DIR/.frontend-build.XXXXXX")"
  new_build="$BUILD_TMP/build"
  previous_stage="$BUILD_TMP/previous-stage"
  if ! (
    cd "$FRONTEND_DIR"
    BUILD_PATH="$new_build" yarn build
  ); then
    rm -f -- "$stamp"
    die "Frontend-Build fehlgeschlagen; beim nächsten Lauf werden auch die Abhängigkeiten geprüft."
  fi
  [[ -f "$new_build/index.html" ]] || die "Frontend-Build ist unvollständig (index.html fehlt)."
  printf '%s\n' "$build_input_hash" > "$new_build/.build-input.sha256"

  if [[ -d "$STAGED_BUILD_DIR" ]]; then
    mv -- "$STAGED_BUILD_DIR" "$previous_stage"
  elif [[ -e "$STAGED_BUILD_DIR" ]]; then
    die "$STAGED_BUILD_DIR ist kein Verzeichnis."
  fi
  if ! mv -- "$new_build" "$STAGED_BUILD_DIR"; then
    [[ -d "$previous_stage" ]] && mv -- "$previous_stage" "$STAGED_BUILD_DIR"
    die "Neuer Frontend-Build konnte nicht bereitgestellt werden."
  fi
  rm -rf -- "$BUILD_TMP"
  BUILD_TMP=""
  green "✓ Frontend-Build vollständig bereitgestellt"
}

activate_staged_frontend() {
  local previous_build
  [[ -f "$STAGED_BUILD_DIR/index.html" ]] || return 0

  BUILD_TMP="$(mktemp -d "$RUN_DIR/.frontend-activate.XXXXXX")"
  previous_build="$BUILD_TMP/previous"
  if [[ -d "$BUILD_DIR" ]]; then
    mv -- "$BUILD_DIR" "$previous_build"
  elif [[ -e "$BUILD_DIR" ]]; then
    die "$BUILD_DIR ist kein Verzeichnis."
  fi
  if ! mv -- "$STAGED_BUILD_DIR" "$BUILD_DIR"; then
    [[ -d "$previous_build" ]] && mv -- "$previous_build" "$BUILD_DIR"
    die "Bereitgestellter Frontend-Build konnte nicht aktiviert werden."
  fi
  rm -rf -- "$BUILD_TMP"
  BUILD_TMP=""
  green "✓ Neuer Frontend-Build aktiviert"
}

validate_backend_environment() {
  local env_file="$BACKEND_DIR/.env"
  [[ -f "$env_file" ]] || die "backend/.env fehlt und konnte nicht automatisch erzeugt werden."
  "$VENV_DIR/bin/python" - "$env_file" <<'PY'
import sys
from dotenv import dotenv_values
from email_validator import EmailNotValidError, validate_email

config = dotenv_values(sys.argv[1])
required = ("MONGO_URL", "DB_NAME", "JWT_SECRET", "ADMIN_EMAIL", "ADMIN_PASSWORD")
missing = [key for key in required if not str(config.get(key, "")).strip()]
placeholders = [key for key in ("JWT_SECRET", "ADMIN_EMAIL", "ADMIN_PASSWORD")
                if str(config.get(key, "")).strip().startswith("CHANGE_ME")]
errors = []
if missing:
    errors.append("fehlend/leer: " + ", ".join(missing))
if placeholders:
    errors.append("Platzhalter ersetzen: " + ", ".join(placeholders))
if config.get("JWT_SECRET") and len(str(config["JWT_SECRET"])) < 32:
    errors.append("JWT_SECRET muss mindestens 32 Zeichen lang sein")
password = str(config.get("ADMIN_PASSWORD", ""))
if password and len(password) < 12:
    errors.append("ADMIN_PASSWORD muss mindestens 12 Zeichen lang sein")
if len(password.encode("utf-8")) > 72:
    errors.append("ADMIN_PASSWORD darf höchstens 72 Bytes lang sein")
try:
    validate_email(str(config.get("ADMIN_EMAIL", "")), check_deliverability=False)
except EmailNotValidError:
    errors.append("ADMIN_EMAIL ist ungültig")
mongo_url = str(config.get("MONGO_URL", ""))
if mongo_url and not mongo_url.startswith(("mongodb://", "mongodb+srv://")):
    errors.append("MONGO_URL muss mit mongodb:// oder mongodb+srv:// beginnen")
if errors:
    print("Ungültige backend/.env: " + "; ".join(errors), file=sys.stderr)
    raise SystemExit(1)
PY
}

validate_backend_runtime() {
  if ! (
    cd "$BACKEND_DIR"
    "$VENV_DIR/bin/python" -c 'import server; assert server.app.title == "IT-Tabelander API"'
  ); then
    die "Backend-Code oder Runtime-Abhängigkeiten können nicht geladen werden."
  fi
}

check_mongodb() {
  if ! (
    cd "$BACKEND_DIR"
    "$VENV_DIR/bin/python" - "$BACKEND_DIR/.env" <<'PY'
import asyncio
import sys

from dotenv import load_dotenv

load_dotenv(sys.argv[1])

from app.db import close_client, get_db


async def main() -> None:
    try:
        await get_db().command("ping")
    except Exception as exc:  # noqa: BLE001
        print(f"MongoDB-Ping fehlgeschlagen ({type(exc).__name__}): {exc}", file=sys.stderr)
        raise SystemExit(1) from None
    finally:
        await close_client()


asyncio.run(main())
PY
  ); then
    die "MongoDB ist über MONGO_URL nicht erreichbar. Lokal ggf.: sudo systemctl start mongod"
  fi
  green "✓ MongoDB erreichbar"
}

start_backend() {
  local pid
  if pid="$(running_pid backend "$RUN_DIR/backend.pid")"; then
    yellow "Backend läuft bereits (PID $pid)"
    return
  fi
  clear_stale_pidfile "Backend" "$RUN_DIR/backend.pid"
  green "→ Backend starten (Port $BACKEND_PORT) ..."
  (
    cd "$BACKEND_DIR"
    unset IT_TABELANDER_DEPLOY_LOCK_HELD
    exec nohup setsid "$VENV_DIR/bin/python" -m uvicorn server:app \
      --host "$BACKEND_HOST" --port "$BACKEND_PORT" --workers "$BACKEND_WORKERS"
  ) > "$LOG_DIR/backend.log" 2>&1 9>&- &
  pid=$!
  LAUNCHED_BACKEND_PID="$pid"
  if ! write_pid "$RUN_DIR/backend.pid" "$pid"; then
    die "Backend-PID konnte nicht gespeichert werden."
  fi
}

wait_for_backend() {
  local pid response verified_pid deadline=$((SECONDS + STARTUP_TIMEOUT_SECONDS))
  local url="http://$HEALTHCHECK_HOST:$BACKEND_PORT/api/health"
  while (( SECONDS < deadline )); do
    pid="$(read_pid "$RUN_DIR/backend.pid" 2>/dev/null || true)"
    if [[ -z "$pid" ]] || ! kill -0 "$pid" 2>/dev/null; then
      red "Backend-Prozess wurde vorzeitig beendet. Letzte Logzeilen:"
      tail -n 30 "$LOG_DIR/backend.log" >&2 || true
      return 1
    fi
    if response="$(curl -fsS --max-time 2 "$url" 2>/dev/null)" \
      && [[ "$response" == *'"status":"ok"'* && "$response" == *'"db":true'* ]]; then
      sleep 1
      verified_pid="$(running_pid backend "$RUN_DIR/backend.pid" 2>/dev/null || true)"
      if [[ "$verified_pid" == "$pid" ]] \
        && response="$(curl -fsS --max-time 2 "$url" 2>/dev/null)" \
        && [[ "$response" == *'"status":"ok"'* && "$response" == *'"db":true'* ]]; then
        green "✓ Backend und MongoDB sind bereit"
        return 0
      fi
    fi
    sleep 1
  done
  red "Backend wurde nicht rechtzeitig bereit. Prüfe MongoDB und backend/.env."
  tail -n 30 "$LOG_DIR/backend.log" >&2 || true
  return 1
}

wait_for_frontend() {
  local pid verified_pid deadline=$((SECONDS + STARTUP_TIMEOUT_SECONDS))
  local url="http://$HEALTHCHECK_HOST:$BACKEND_PORT/"
  while (( SECONDS < deadline )); do
    pid="$(read_pid "$RUN_DIR/backend.pid" 2>/dev/null || true)"
    if [[ -z "$pid" ]] || ! kill -0 "$pid" 2>/dev/null; then
      red "Webdienst wurde vorzeitig beendet. Letzte Logzeilen:"
      tail -n 30 "$LOG_DIR/backend.log" >&2 || true
      return 1
    fi
    if curl -fsS --max-time 2 "$url" >/dev/null 2>&1; then
      sleep 1
      verified_pid="$(running_pid backend "$RUN_DIR/backend.pid" 2>/dev/null || true)"
      if [[ "$verified_pid" == "$pid" ]] \
        && curl -fsS --max-time 2 "$url" >/dev/null 2>&1; then
        green "✓ Website ist bereit"
        return 0
      fi
    fi
    sleep 1
  done
  red "Frontend wurde nicht rechtzeitig bereit."
  tail -n 30 "$LOG_DIR/backend.log" >&2 || true
  return 1
}

ensure_backend_environment
install_and_start_mongodb
prepare_backend
prepare_frontend

require_command setsid
require_command ps
validate_backend_environment
validate_backend_runtime
check_mongodb

if (( PREPARE_ONLY == 1 )); then
  green "✓ Vorbereitung abgeschlossen; neuer Frontend-Build wurde nur bereitgestellt."
  exit 0
fi

if [[ -e "$RUN_DIR/backend.pid" || -e "$RUN_DIR/frontend.pid" ]]; then
  green "→ Vorhandenen App-Prozess für einen sauberen Neustart stoppen ..."
  bash "$SCRIPT_DIR/stop.sh"
fi

activate_staged_frontend

start_backend
wait_for_backend
wait_for_frontend

ADMIN_EMAIL_DISPLAY=""
ADMIN_PASSWORD_DISPLAY=""
if [[ -f "$RUN_DIR/reset-admin.pending" ]]; then
  ADMIN_EMAIL_DISPLAY="$(env_value ADMIN_EMAIL)"
  ADMIN_PASSWORD_DISPLAY="$(env_value ADMIN_PASSWORD)"
  rm -f -- "$RUN_DIR/reset-admin.pending"
fi

echo ""
green "════════════════════════════════════════════"
green " IT-Tabelander läuft und ist bereit"
echo  " Website/Reverse-Proxy-Ziel: http://$HEALTHCHECK_HOST:$BACKEND_PORT"
echo  " Healthcheck              : http://$HEALTHCHECK_HOST:$BACKEND_PORT/api/health"
echo  " Admin                     : /admin"
echo  " Log                       : $LOG_DIR/backend.log"
if [[ -n "$ADMIN_PASSWORD_DISPLAY" ]]; then
  yellow " Einmalige Admin-Zugangsdaten (jetzt anmelden und im Admin-Menü ändern):"
  echo  " E-Mail   : $ADMIN_EMAIL_DISPLAY"
  echo  " Passwort : $ADMIN_PASSWORD_DISPLAY"
fi
green "════════════════════════════════════════════"

#!/usr/bin/env bash
# Startet Backend (FastAPI/uvicorn) und Frontend (statischer Build) im Hintergrund.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ── Config laden ─────────────────────────────────────────────────────────
PUBLIC_BACKEND_URL="https://it.tabelander.co.at"
BACKEND_HOST="127.0.0.1"; BACKEND_PORT="8001"; FRONTEND_PORT="3000"; BACKEND_WORKERS="2"
[ -f "$SCRIPT_DIR/deploy.config" ] && source "$SCRIPT_DIR/deploy.config"

RUN_DIR="$SCRIPT_DIR/run"
LOG_DIR="$SCRIPT_DIR/logs"
mkdir -p "$RUN_DIR" "$LOG_DIR"

green() { printf "\033[0;32m%s\033[0m\n" "$1"; }
yellow(){ printf "\033[0;33m%s\033[0m\n" "$1"; }
red()   { printf "\033[0;31m%s\033[0m\n" "$1"; }

is_running() { # $1 = pidfile
  [ -f "$1" ] && kill -0 "$(cat "$1")" 2>/dev/null
}

# ── MongoDB-Check ────────────────────────────────────────────────────────
if ! (exec 3<>"/dev/tcp/127.0.0.1/27017") 2>/dev/null; then
  yellow "⚠  MongoDB scheint auf 127.0.0.1:27017 nicht zu laufen. Bitte 'sudo systemctl start mongod'."
else
  green "✓ MongoDB erreichbar"
fi

# ── Backend ──────────────────────────────────────────────────────────────
start_backend() {
  if is_running "$RUN_DIR/backend.pid"; then
    yellow "Backend läuft bereits (PID $(cat "$RUN_DIR/backend.pid"))"; return
  fi
  cd "$SCRIPT_DIR/backend"
  if [ ! -d venv ]; then
    green "→ Python venv anlegen & Abhängigkeiten installieren ..."
    python3 -m venv venv
    ./venv/bin/pip install --upgrade pip >/dev/null
    grep -vE 'emergentintegrations|litellm @' requirements.txt > /tmp/it_req.txt
    ./venv/bin/pip install -r /tmp/it_req.txt
  fi
  green "→ Backend starten (Port $BACKEND_PORT) ..."
  nohup ./venv/bin/uvicorn server:app --host "$BACKEND_HOST" --port "$BACKEND_PORT" \
        --workers "$BACKEND_WORKERS" > "$LOG_DIR/backend.log" 2>&1 &
  echo $! > "$RUN_DIR/backend.pid"
  cd "$SCRIPT_DIR"
}

# ── Frontend ─────────────────────────────────────────────────────────────
start_frontend() {
  if is_running "$RUN_DIR/frontend.pid"; then
    yellow "Frontend läuft bereits (PID $(cat "$RUN_DIR/frontend.pid"))"; return
  fi
  cd "$SCRIPT_DIR/frontend"
  if [ ! -d build ]; then
    green "→ Frontend-Build erstellen ..."
    echo "REACT_APP_BACKEND_URL=$PUBLIC_BACKEND_URL" > .env.production
    yarn install --frozen-lockfile || yarn install
    yarn build
  fi
  if ! command -v serve >/dev/null 2>&1; then
    green "→ statischen Server 'serve' installieren ..."
    npm install -g serve
  fi
  green "→ Frontend starten (Port $FRONTEND_PORT) ..."
  nohup serve -s build -l "$FRONTEND_PORT" > "$LOG_DIR/frontend.log" 2>&1 &
  echo $! > "$RUN_DIR/frontend.pid"
  cd "$SCRIPT_DIR"
}

start_backend
start_frontend
sleep 2

echo ""
green "════════════════════════════════════════════"
green " IT-Tabelander läuft"
echo  " Backend :  http://$BACKEND_HOST:$BACKEND_PORT/api/health"
echo  " Frontend:  http://127.0.0.1:$FRONTEND_PORT"
echo  " Public  :  $PUBLIC_BACKEND_URL   (via Apache-Proxy)"
echo  " Logs    :  $LOG_DIR/{backend,frontend}.log"
green "════════════════════════════════════════════"

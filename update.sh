#!/usr/bin/env bash
# Holt neuesten Code (git pull), aktualisiert Abhängigkeiten, baut das Frontend
# neu und startet alles neu.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

PUBLIC_BACKEND_URL="https://it.tabelander.co.at"
[ -f "$SCRIPT_DIR/deploy.config" ] && source "$SCRIPT_DIR/deploy.config"

green() { printf "\033[0;32m%s\033[0m\n" "$1"; }

green "→ 1/5  git pull ..."
git pull --ff-only

green "→ 2/5  Backend-Abhängigkeiten ..."
cd "$SCRIPT_DIR/backend"
[ -d venv ] || python3 -m venv venv
./venv/bin/pip install --upgrade pip >/dev/null
grep -vE 'emergentintegrations|litellm @' requirements.txt > /tmp/it_req.txt
./venv/bin/pip install -r /tmp/it_req.txt

green "→ 3/5  Frontend bauen ..."
cd "$SCRIPT_DIR/frontend"
echo "REACT_APP_BACKEND_URL=$PUBLIC_BACKEND_URL" > .env.production
yarn install --frozen-lockfile || yarn install
rm -rf build
yarn build

green "→ 4/5  Dienste stoppen ..."
cd "$SCRIPT_DIR"
bash ./stop.sh

green "→ 5/5  Dienste starten ..."
bash ./start.sh

green "✓ Update abgeschlossen."

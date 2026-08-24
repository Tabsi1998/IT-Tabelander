#!/usr/bin/env bash
# Aktualisiert Code und Artefakte. Laufende Dienste werden erst nach einer
# vollständig erfolgreichen Vorbereitung neu gestartet.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

RUN_DIR="$SCRIPT_DIR/run"
mkdir -p "$RUN_DIR"

green() { printf "\033[0;32m%s\033[0m\n" "$1"; }
red()   { printf "\033[0;31m%s\033[0m\n" "$1" >&2; }
die()   { red "✗ $1"; exit 1; }

command -v flock >/dev/null 2>&1 || die "Benötigtes Programm fehlt: flock"
command -v git >/dev/null 2>&1 || die "Benötigtes Programm fehlt: git"
command -v bash >/dev/null 2>&1 || die "Benötigtes Programm fehlt: bash"

exec 9>"$RUN_DIR/deploy.lock"
flock -n 9 || die "Ein anderer Start-, Stop- oder Update-Vorgang läuft bereits."
export IT_TABELANDER_DEPLOY_LOCK_HELD=1

trap 'exit 130' INT
trap 'exit 143' TERM

green "→ 1/4  Änderungen laden ..."
git pull --ff-only

green "→ 2/4  Abhängigkeiten und neuen Build vorbereiten ..."
bash "$SCRIPT_DIR/start.sh" --prepare --refresh

green "→ 3/4  Bisherige Dienste sauber stoppen ..."
bash "$SCRIPT_DIR/stop.sh"

green "→ 4/4  Geprüften Stand starten ..."
bash "$SCRIPT_DIR/start.sh"

green "✓ Update vollständig abgeschlossen."

#!/usr/bin/env bash
# Aktualisiert Code und Artefakte. Laufende Dienste werden erst nach einer
# vollständig erfolgreichen Vorbereitung neu gestartet.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

RUN_DIR="$SCRIPT_DIR/run"
BACKEND_DIR="$SCRIPT_DIR/backend"
CURRENT_VENV="$BACKEND_DIR/venv"
NEXT_VENV="$RUN_DIR/backend-venv.next"
PREVIOUS_VENV="$RUN_DIR/backend-venv.previous"
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

if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
  die "Lokale Änderungen erkannt. Update abgebrochen, damit nichts überschrieben oder vermischt wird."
fi

for target in "$NEXT_VENV" "$PREVIOUS_VENV"; do
  case "$(readlink -m -- "$target")" in
    "$(readlink -m -- "$RUN_DIR")"/*) ;;
    *) die "Unsicherer temporärer venv-Pfad: $target" ;;
  esac
done
rm -rf -- "$NEXT_VENV" "$PREVIOUS_VENV"

VENV_SWITCHED=0
rollback_venv() {
  local status=$?
  trap - EXIT INT TERM
  if (( status != 0 && VENV_SWITCHED == 1 )); then
    red "↶ Update fehlgeschlagen; vorheriges Python-venv wird wiederhergestellt."
    bash "$SCRIPT_DIR/stop.sh" || true
    rm -rf -- "$CURRENT_VENV"
    if [[ -d "$PREVIOUS_VENV" ]]; then
      mv -- "$PREVIOUS_VENV" "$CURRENT_VENV"
    fi
    if ! bash "$SCRIPT_DIR/start.sh"; then
      red "Automatischer Neustart mit dem vorherigen venv ist ebenfalls fehlgeschlagen. Prüfe logs/backend.log."
    fi
  fi
  [[ -d "$NEXT_VENV" ]] && rm -rf -- "$NEXT_VENV"
  exit "$status"
}
trap rollback_venv EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

green "→ 1/4  Änderungen laden ..."
git pull --ff-only

green "→ 2/4  Abhängigkeiten und neuen Build getrennt vorbereiten ..."
IT_TABELANDER_VENV_DIR="$NEXT_VENV" bash "$SCRIPT_DIR/start.sh" --prepare --refresh

green "→ 3/4  Bisherige Dienste sauber stoppen ..."
bash "$SCRIPT_DIR/stop.sh"

if [[ -d "$CURRENT_VENV" ]]; then
  mv -- "$CURRENT_VENV" "$PREVIOUS_VENV"
fi
VENV_SWITCHED=1
mv -- "$NEXT_VENV" "$CURRENT_VENV"

green "→ 4/4  Geprüften Stand starten ..."
bash "$SCRIPT_DIR/start.sh"

rm -rf -- "$PREVIOUS_VENV"
VENV_SWITCHED=0

green "✓ Update vollständig abgeschlossen."

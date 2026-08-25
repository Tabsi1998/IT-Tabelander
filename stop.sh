#!/usr/bin/env bash
# Stoppt ausschließlich die zu diesem Projekt gehörenden Backend-/Frontend-Prozesse.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

RUN_DIR="$SCRIPT_DIR/run"
BACKEND_DIR="$SCRIPT_DIR/backend"
FRONTEND_DIR="$SCRIPT_DIR/frontend"
VENV_DIR="$BACKEND_DIR/venv"
mkdir -p "$RUN_DIR"

green()  { printf "\033[0;32m%s\033[0m\n" "$1"; }
yellow() { printf "\033[0;33m%s\033[0m\n" "$1"; }
red()    { printf "\033[0;31m%s\033[0m\n" "$1" >&2; }
die()    { red "✗ $1"; exit 1; }

command -v flock >/dev/null 2>&1 || die "Benötigtes Programm fehlt: flock"
command -v ps >/dev/null 2>&1 || die "Benötigtes Programm fehlt: ps"
command -v readlink >/dev/null 2>&1 || die "Benötigtes Programm fehlt: readlink"

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
  local service="$1" pid="$2" command_line process_dir=""
  command_line="$(process_command "$pid")" || return 1
  process_dir="$(process_workdir "$pid" || true)"
  case "$service" in
    backend)
      [[ "$command_line" == *uvicorn* && "$command_line" == *server:app* ]] \
        && [[ "$process_dir" == "$BACKEND_DIR" || "$command_line" == *"$VENV_DIR/"* ]]
      ;;
    frontend)
      [[ "$command_line" == *serve* && "$command_line" == *"-s build"* ]] \
        && [[ "$process_dir" == "$FRONTEND_DIR" || "$command_line" == *"$FRONTEND_DIR/node_modules/"* ]]
      ;;
    *) return 1 ;;
  esac
}

target_is_alive() {
  local target="$1" group
  if [[ "$target" == -* ]]; then
    group="${target#-}"
    ps -eo pgid= 2>/dev/null | awk -v wanted="$group" '$1 == wanted { found=1 } END { exit !found }'
  else
    process_exists "$target"
  fi
}

process_exists() {
  local pid="$1"
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || return 1
  [[ -d "/proc/$pid" ]] || ps -p "$pid" -o pid= >/dev/null 2>&1
}

STOP_FAILURES=0
stop_one() {
  local label="$1" service="$2" pidfile="$3"
  local pid pgid="" target attempt forced=0

  if [[ ! -e "$pidfile" ]]; then
    yellow "· $label: keine PID-Datei"
    return
  fi
  pid="$(read_pid "$pidfile" 2>/dev/null || true)"
  if [[ -z "$pid" ]]; then
    yellow "⚠ $label: ungültige PID-Datei entfernt"
    rm -f -- "$pidfile"
    return
  fi
  if ! process_exists "$pid"; then
    yellow "· $label lief nicht mehr (veraltete PID $pid)"
    rm -f -- "$pidfile"
    return
  fi
  if ! process_belongs_to_project "$service" "$pid"; then
    red "✗ $label-PID $pid gehört nicht sicher zu diesem Projekt; Prozess und PID-Datei bleiben unangetastet."
    STOP_FAILURES=1
    return
  fi

  pgid="$(ps -o pgid= -p "$pid" 2>/dev/null | tr -d '[:space:]' || true)"
  if ! process_exists "$pid"; then
    yellow "· $label endete bereits (PID $pid)"
    rm -f -- "$pidfile"
    return
  fi
  target="$pid"
  [[ "$pgid" == "$pid" ]] && target="-$pid"

  if ! kill -TERM -- "$target" 2>/dev/null && target_is_alive "$target"; then
    red "✗ $label konnte kein TERM-Signal erhalten; PID-Datei bleibt erhalten."
    STOP_FAILURES=1
    return
  fi
  for attempt in {1..40}; do
    target_is_alive "$target" || break
    sleep 0.25
  done
  if target_is_alive "$target"; then
    forced=1
    if ! kill -KILL -- "$target" 2>/dev/null && target_is_alive "$target"; then
      red "✗ $label konnte nicht erzwungen beendet werden; PID-Datei bleibt erhalten."
      STOP_FAILURES=1
      return
    fi
    for attempt in {1..8}; do
      target_is_alive "$target" || break
      sleep 0.25
    done
  fi
  if target_is_alive "$target"; then
    red "✗ $label läuft nach den Stop-Signalen weiter; PID-Datei bleibt erhalten."
    STOP_FAILURES=1
    return
  fi

  rm -f -- "$pidfile"
  if (( forced == 1 )); then
    yellow "✓ $label gestoppt (PID $pid, Beenden erzwungen)"
  else
    green "✓ $label sauber gestoppt (PID $pid)"
  fi
}

# Räumt beim ersten Update noch den früheren separaten `serve`-Prozess auf.
if [[ -e "$RUN_DIR/frontend.pid" ]]; then
  stop_one "Altes Frontend" frontend "$RUN_DIR/frontend.pid"
fi
stop_one "Backend" backend "$RUN_DIR/backend.pid"

if (( STOP_FAILURES != 0 )); then
  die "Mindestens ein Prozess konnte nicht sicher gestoppt werden."
fi
green "IT-Tabelander ist gestoppt."

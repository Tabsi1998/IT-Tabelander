#!/usr/bin/env bash
# Stoppt Backend und Frontend.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN_DIR="$SCRIPT_DIR/run"

green() { printf "\033[0;32m%s\033[0m\n" "$1"; }
yellow(){ printf "\033[0;33m%s\033[0m\n" "$1"; }

stop_one() { # $1 = name, $2 = pidfile
  local name="$1" pidfile="$2"
  if [ -f "$pidfile" ]; then
    local pid; pid="$(cat "$pidfile")"
    if kill -0 "$pid" 2>/dev/null; then
      # Prozessgruppe beenden (uvicorn-Worker / serve-Kinder mit)
      kill "$pid" 2>/dev/null
      sleep 1
      kill -9 "$pid" 2>/dev/null || true
      green "✓ $name gestoppt (PID $pid)"
    else
      yellow "· $name lief nicht"
    fi
    rm -f "$pidfile"
  else
    yellow "· $name: keine PID-Datei"
  fi
}

stop_one "Frontend" "$RUN_DIR/frontend.pid"
stop_one "Backend"  "$RUN_DIR/backend.pid"

# Sicherheits-Fallback: verwaiste Prozesse abräumen
pkill -f "uvicorn server:app" 2>/dev/null && green "✓ Rest-uvicorn beendet" || true
pkill -f "serve -s build"     2>/dev/null && green "✓ Rest-serve beendet"   || true

green "Alles gestoppt."

#!/usr/bin/env bash
# Deployment test phases for the "deploy" group of scripts/local_check.py.
# Runs as root inside the throwaway server from scripts/deploy-test/Dockerfile;
# every project script runs as the server user "deploy", as on the real server.
set -Eeuo pipefail

APP=/srv/it-tabelander
ORIGIN=/srv/origin.git
WORK=/srv/work
SERVICE=it-tabelander
HEALTH=http://127.0.0.1:8001/api/health

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

as_deploy() {
  local directory="$1"
  shift
  # The inner script expands its own positional parameters, hence single quotes.
  # shellcheck disable=SC2016
  runuser -u deploy -- bash -c 'cd "$1" && shift && exec "$@"' bash "$directory" "$@"
}

healthy() {
  curl -fsS --max-time 3 "$HEALTH" 2>/dev/null | grep -q '"status":"ok"'
}

wait_healthy() {
  local seconds="$1" waited=0
  while (( waited < seconds )); do
    healthy && return 0
    sleep 2
    waited=$((waited + 2))
  done
  fail "the website did not become healthy within ${seconds} s"
}

main_pid() {
  systemctl show -p MainPID --value "$SERVICE.service"
}

app_commit() {
  as_deploy "$APP" git rev-parse HEAD
}

# Commit one change in the second clone and push it to the shared origin, as a
# developer would before the owner runs ./update.sh on the server.
push_change() {
  local message="$1" script="$2"
  as_deploy "$WORK" git pull --quiet --ff-only
  as_deploy "$WORK" bash -c "$script"
  as_deploy "$WORK" git commit --quiet --all --message "$message"
  as_deploy "$WORK" git push --quiet
}

revert_last_change() {
  as_deploy "$WORK" git revert --no-edit HEAD >/dev/null
  as_deploy "$WORK" git push --quiet
}

# The whole update output goes to stdout as well, so local_check.py keeps it in
# .local-testing/logs/deploy-<phase>.log; the summary stays the last line.
run_update() {
  local status=0
  as_deploy "$APP" ./update.sh > /tmp/update.log 2>&1 || status=$?
  cat /tmp/update.log
  return "$status"
}

# A failed update must fail for the planted reason, not for any other one.
expect_in_update_log() {
  grep -qF -- "$1" /tmp/update.log || fail "the update failed, but not with: $1"
}

case "${1:-}" in
  setup)
    install -d -o deploy -g deploy /srv
    # The bundle holds the committed HEAD; it becomes branch main of the origin.
    as_deploy /srv git init --quiet --bare "$ORIGIN"
    as_deploy "$ORIGIN" git fetch --quiet /tmp/repo.bundle "HEAD:refs/heads/main"
    as_deploy "$ORIGIN" git symbolic-ref HEAD refs/heads/main
    as_deploy /srv git clone --quiet "$ORIGIN" "$APP"
    as_deploy /srv git clone --quiet "$ORIGIN" "$WORK"
    for clone in "$APP" "$WORK"; do
      as_deploy "$clone" git config user.email deploy-test@example.com
      as_deploy "$clone" git config user.name "Deploy Test"
    done
    echo "origin, server copy and developer copy at $(app_commit | cut -c1-7)"
    ;;

  first-start)
    as_deploy "$APP" ./start.sh --no-system-install > /tmp/start.log 2>&1 \
      || { tail -n 40 /tmp/start.log; fail "./start.sh failed"; }
    systemctl is-active --quiet "$SERVICE" || fail "the service is not active"
    systemctl is-enabled --quiet "$SERVICE" || fail "the service does not start at boot"
    [[ "$(systemctl show -p User --value "$SERVICE.service")" == "deploy" ]] \
      || fail "the service does not run as the project user"
    healthy || fail "the health check fails"
    echo "service active, enabled for boot, runs as deploy, website healthy"
    ;;

  crash)
    before="$(main_pid)"
    kill -KILL "$before"
    for _ in $(seq 1 40); do
      after="$(main_pid)"
      if [[ "$after" != "0" && "$after" != "$before" ]] && healthy; then
        echo "systemd restarted the app after a crash (PID $before -> $after)"
        exit 0
      fi
      sleep 1
    done
    fail "the app did not come back after a crash"
    ;;

  wait-healthy)
    wait_healthy 240
    systemctl is-active --quiet "$SERVICE" || fail "the service is not active after the boot"
    echo "healthy after the boot without any command (PID $(main_pid))"
    ;;

  update-broken-prepare)
    before="$(app_commit)"
    pid="$(main_pid)"
    push_change "deploy test: import error" \
      "printf '\\nraise RuntimeError(\"deploy test: broken import\")\\n' >> backend/server.py"
    if run_update; then
      fail "an update with a broken import succeeded"
    fi
    expect_in_update_log "deploy test: broken import"
    expect_in_update_log "Backend-Code oder Runtime-Abhängigkeiten können nicht geladen werden"
    [[ "$(app_commit)" == "$before" ]] || fail "the code on disk was not rolled back"
    [[ "$(main_pid)" == "$pid" ]] || fail "the running app was restarted although the update never reached it"
    [[ ! -e "$APP/run/frontend-build.next" ]] || fail "a build of the failed version was left for the next start"
    healthy || fail "the website is down after a failed preparation"
    revert_last_change
    echo "failed preparation: running app untouched, code back at ${before:0:7}"
    ;;

  update-broken-start)
    before="$(app_commit)"
    push_change "deploy test: health always 503" "python3 - <<'PY'
from pathlib import Path
server = Path('backend/server.py')
text = server.read_text(encoding='utf-8')
old = 'return {\"status\": \"ok\", \"db\": True}'
assert text.count(old) == 1
server.write_text(text.replace(old, 'return JSONResponse(status_code=503, content={\"status\": \"broken\", \"db\": True})'), encoding='utf-8')
PY"
    if run_update; then
      fail "an update whose start fails succeeded"
    fi
    expect_in_update_log "Backend wurde nicht rechtzeitig bereit"
    grep -q "Es läuft wieder" /tmp/update.log || { tail -n 40 /tmp/update.log; fail "the rollback did not restart the previous version"; }
    [[ "$(app_commit)" == "$before" ]] || fail "the code on disk was not rolled back"
    systemctl is-active --quiet "$SERVICE" || fail "the service is not active after the rollback"
    healthy || fail "the website is down after the rollback"
    "$APP/backend/venv/bin/python" -c "import fastapi" || fail "the previous Python environment was not restored"
    revert_last_change
    echo "failed start: code, packages and build back at ${before:0:7}, website healthy"
    ;;

  update-good)
    push_change "deploy test: harmless change" "printf '\\n<!-- deploy test -->\\n' >> README.md"
    expected="$(as_deploy "$WORK" git rev-parse HEAD)"
    run_update || { tail -n 40 /tmp/update.log; fail "a good update failed"; }
    [[ "$(app_commit)" == "$expected" ]] || fail "the server does not run the new commit"
    grep -q "Update abgeschlossen" /tmp/update.log || fail "the update did not report which version runs"
    healthy || fail "the website is down after a good update"
    echo "good update: runs ${expected:0:7}, website healthy"
    ;;

  stop-disable)
    as_deploy "$APP" ./stop.sh --disable > /tmp/stop.log 2>&1 || { cat /tmp/stop.log; fail "./stop.sh --disable failed"; }
    systemctl is-active --quiet "$SERVICE" && fail "the service still runs after ./stop.sh"
    systemctl is-enabled --quiet "$SERVICE" && fail "autostart is still on after --disable"
    healthy && fail "the website still answers after ./stop.sh"
    as_deploy "$APP" ./start.sh --no-system-install > /tmp/start.log 2>&1 \
      || { tail -n 40 /tmp/start.log; fail "./start.sh after --disable failed"; }
    systemctl is-enabled --quiet "$SERVICE" || fail "./start.sh did not turn autostart back on"
    healthy || fail "the website is down after ./start.sh"
    echo "stop --disable and start: autostart off and on again"
    ;;

  process-mode)
    as_deploy "$APP" bash -c 'printf "USE_SYSTEMD=\"0\"\n" > deploy.config.local'
    as_deploy "$APP" ./start.sh --no-system-install > /tmp/start.log 2>&1 \
      || { tail -n 40 /tmp/start.log; fail "./start.sh with USE_SYSTEMD=0 failed"; }
    systemctl is-enabled --quiet "$SERVICE" && fail "USE_SYSTEMD=0 left the service enabled"
    [[ -s "$APP/run/backend.pid" ]] || fail "no background process was started"
    healthy || fail "the website is down in process mode"
    as_deploy "$APP" ./stop.sh > /tmp/stop.log 2>&1 || { cat /tmp/stop.log; fail "./stop.sh in process mode failed"; }
    healthy && fail "the background process still answers after ./stop.sh"
    as_deploy "$APP" rm -f deploy.config.local
    as_deploy "$APP" ./start.sh --no-system-install > /tmp/start.log 2>&1 \
      || { tail -n 40 /tmp/start.log; fail "switching back to the service failed"; }
    systemctl is-active --quiet "$SERVICE" || fail "the service did not take over again"
    healthy || fail "the website is down after switching back"
    echo "USE_SYSTEMD=0 runs a background process; switching back restores the service"
    ;;

  *)
    fail "unknown phase: ${1:-<none>}"
    ;;
esac

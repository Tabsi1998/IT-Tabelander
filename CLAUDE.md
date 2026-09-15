# CLAUDE.md

Notes for Claude Code sessions on IT-Tabelander: how the local checks are set
up and how to extend them. Answer the owner (Tabsi1998) in German. Commits and
PR titles stay English.

## Local first, GitHub second

GitHub Actions is the second confirmation. Before every push:

```bash
python scripts/local_check.py                  # everything but extra
python scripts/local_check.py --all            # plus the gates GitHub does not run
python scripts/local_check.py --only integration
python scripts/local_check.py --list           # the steps, without running them
```

Results: `.local-testing/local-check.json`, logs in `.local-testing/logs/`,
live progress in `.local-testing/local-check.progress.json`. All of it is
ignored by Git.

| Group | Mirrors | Runs |
| --- | --- | --- |
| repository | ci.yml `deployment-scripts` | every `*.sh` parses, no CRLF in the index, `git diff --check` over every tracked line (the CI's check sees no diff on a fresh checkout), Gitleaks over the history and over uncommitted files |
| backend | ci.yml `backend` matrix | for Python 3.10 and 3.14: venv from `requirements-dev.txt`, `pip check`, compileall, `import server`, `tests/test_unit_runtime.py` and `tests/test_inquiry_dolibarr.py` |
| integration | - (the CI never runs it) | a MongoDB container, uvicorn over HTTPS on Python 3.14, `tests/test_api.py` and `tests/test_regression_iter2.py` against it |
| frontend | ci.yml `frontend` | Node 24, `yarn install --frozen-lockfile`, `yarn build` with `CI=true` (Create React App turns warnings into errors) |
| extra | - | every test file is run by some gate, OSV over the lockfiles, ShellCheck |

Tools the checks expect: Docker Desktop (MongoDB, OSV, ShellCheck), Git for
Windows (bash, openssl), gitleaks, Python 3.10 and 3.14 through the `py`
launcher, Node 24 with Corepack. A missing tool skips its steps with a hint.

## How the backend steps are isolated

- They run against `.local-testing/snapshot/backend`, a copy of what Git would
  commit. `server.py` loads `backend/.env`; a real `.env` with SMTP or Dolibarr
  credentials must never reach a test.
- The integration server speaks HTTPS with a throwaway certificate
  (`.local-testing/tls`). The login sets `Secure; SameSite=None` cookies, which a
  client never returns over plain HTTP; `REQUESTS_CA_BUNDLE` trusts the
  certificate for the test run only.
- Settings are throwaway values (`integration_env()`); the database name
  contains "test", as `conftest.py` requires.

## Open findings (2026-09-15)

The integration suite has 57 passing and 2 failing tests; both are app bugs:

- `AsyncMongoClient` in `backend/app/db.py` is created without `tz_aware=True`,
  so stored datetimes come back naive. Comparing them with `now_utc()` raises
  `TypeError`: `GET /api/media/<file>` answers 500 for an unlinked repair
  attachment (`routers/media.py`), and `routers/auth.py` compares a reset
  token's `expires_at` the same way.
- The unconditional GET catch-all route in `server.py` makes the removed
  `POST /api/contact` answer 405; the test expects 404.

## Ratchet

The extra group compares against `scripts/ci-baseline.json`: known findings
are debt, new ones fail. After paying debt down, run
`python scripts/local_check.py --all --record` and commit the baseline. Gates
the CI already enforces are never ratcheted. Debt on 2026-09-15: 29 OSV
findings in `frontend/yarn.lock`, 4 ShellCheck findings.

## Extending the checks

`scripts/local_check.py` has three parts:

1. **Header** - paths, groups, the Python matrix, ports.
2. **Shared core** - `Step`, `Context`, the runner, the ratchet, Gitleaks, OSV,
   ShellCheck, MongoDB and process helpers. OmniFM, dolibarr-mahnwesen and
   THE-LION_SQUAD-eSPORT-Webseite carry the same copy; a fix here is worth
   porting there.
3. **IT-Tabelander steps** and `plan()`.

A step is a function `(context) -> str`. It returns its one-line result,
raises `StepFailed` with the reason and how to fix it, or `StepSkipped` when it
cannot run on this machine. Register it with
`Step(group, name, describe, action, needs)`; `needs` names steps of the same
group, or `group/name` across groups. A gate that counts findings goes through
`ratchet(context, key, found, what)`. A new test file must be added to
`CI_TEST_FILES` or `INTEGRATION_TEST_FILES`, or the test inventory fails.

Keep the ports unique, so repositories can be checked side by side: API 18011,
MongoDB 27018 (container `it-tabelander-local-check-mongo`).

## Windows notes

- venvs live in `~/.local-ci/IT-Tabelander/`, never inside the repository.
- Environment variables that look like credentials are withheld from every
  step; only their names are printed.
- `tzdata` goes into the venvs: Windows has no zoneinfo database.

## Machine-local helpers (not in Git)

- `.ci-panel/test_checks.py` with `.vscode/settings.json`: every step in the VS
  Code Testing panel through pytest. Hidden through `.git/info/exclude`.
- `C:\Programmieren\check-all.py --serve`: live dashboard over all
  repositories. `C:\Programmieren\Programmieren.code-workspace` opens all five.

# CLAUDE.md

Build, test and code rules for IT-Tabelander. Operating and setup docs are in
`README.md` (German). Answer the owner (Tabsi1998) in German; commit and PR
titles stay English, PR bodies German with `Closes #N` (a German "Schließt"
alone closes nothing).

## Local checks are the gate

There is no GitHub workflow (private repository); GitHub only sends
Dependabot alerts. Before every push:

```bash
python scripts/local_check.py                  # everything but extra
python scripts/local_check.py --all            # plus extra; deploy only if a deploy file changed
python scripts/local_check.py --only dolibarr  # one group
python scripts/local_check.py --list           # the steps, without running them
IT_TABELANDER_DOLIBARR=24.0.1 python scripts/local_check.py --only dolibarr
```

Results in `.local-testing/local-check.json`, logs in `.local-testing/logs/`
(ignored by Git).

| Group | Runs |
| --- | --- |
| repository | shell scripts parse, no CRLF, `git diff --check`, Gitleaks |
| backend | Python 3.10 and 3.14: venv from `requirements-dev.txt`, `pip check`, compileall, `import server`, unit tests |
| integration | MongoDB container, uvicorn over HTTPS, `tests/test_api.py`, `tests/test_regression_iter2.py` |
| dolibarr | Dolibarr 23.0.3 (live release) + MariaDB + Mailpit in Docker, prepared by `backend/tests/dolibarr_fixtures/fixtures.php`, `tests/test_dolibarr_runtime.py` |
| web | `web/`: frozen install, ESLint (jsx-a11y strict), Vitest, build + prerender, Playwright at 390/768/1280/1440 px with axe WCAG 2.1 AA |
| extra | test inventory, OSV over the lockfiles, ShellCheck (ratchet) |
| deploy | `start.sh`/`stop.sh`/`update.sh` on a throwaway Ubuntu 24.04 with systemd (`scripts/deploy-test/`), tests the committed HEAD |

Tools: Docker Desktop, Git for Windows (bash, openssl), gitleaks, Python 3.10
and 3.14 via `py`, Node 24 with Corepack. A missing tool skips its steps.

## Rules for tests

- Backend steps run against `.local-testing/snapshot/backend`. `server.py`
  loads `backend/.env`; a real `.env` with SMTP or Dolibarr credentials must
  never reach a test.
- The integration server speaks HTTPS with a throwaway certificate: the login
  sets `Secure; SameSite=Lax` cookies. Tests use the cookie session
  `admin_client` from `conftest.py`; the API accepts no `Authorization: Bearer`.
- Mutating integration tests run only with `IT_TABELANDER_RUN_INTEGRATION=1`,
  a local URL and a `DB_NAME` containing "test".
- A new test file goes into `UNIT_TEST_FILES`, `INTEGRATION_TEST_FILES` or
  `DOLIBARR_TEST_FILES`, or the test inventory fails.
- Test values must not look like secrets (Gitleaks): low entropy, names
  without "token".

## Ratchet

The extra group compares against `scripts/ci-baseline.json`: known findings
are debt, new ones fail. After paying debt down:
`python scripts/local_check.py --all --record` and commit the baseline. The
gates repository, backend, integration, dolibarr and web are never ratcheted.

## Extending the checks

`scripts/local_check.py`: header (paths, groups, Python matrix, ports), shared
core (same copy in OmniFM, dolibarr-mahnwesen and THE-LION_SQUAD website - a
fix is worth porting), IT-Tabelander steps and `plan()`. A step is
`(context) -> str`; it raises `StepFailed` (reason + fix) or `StepSkipped`.
Register with `Step(group, name, describe, action, needs)`; counting gates go
through `ratchet(context, key, found, what)`.

Ports stay unique: API 18011, MongoDB 27018, Dolibarr 18031, Mailpit 18131
(SMTP 18125), Dolibarr scenario site 18013, `vite preview` 18015, `yarn dev`
3010, production 8001.

## Code map

- `backend/app/`: `dolibarr.py` (client), `handover.py` (queue to Dolibarr),
  `site_data.py` (company data, opening hours, knowledge articles, cache),
  `legal_texts.py` + `legal_drafts/`, `portal.py` (customer area),
  `review_invites.py`, `mailer.py`, `website.py` (pages, redirects, SEO head,
  404), `routers/`.
- `web/`: one Vite app - website (prerendered), admin `src/admin/`, customer
  area `src/portal/` (lazy chunks, empty shells from `scripts/prerender.mjs`).
- Customer data lives only in Dolibarr; the website never changes an existing
  third party. Public output goes through field whitelists.
- The Content-Security-Policy is enforced everywhere; a Vitest test keeps the
  `vite preview` copy equal to `server.py`.

## Windows notes

- venvs live in `~/.local-ci/IT-Tabelander/`, never inside the repository.
- Environment variables that look like credentials are withheld from every
  step; only their names are printed.
- `tzdata` goes into the venvs: Windows has no zoneinfo database.

# CLAUDE.md

Notes for Claude Code sessions on IT-Tabelander: how the local checks are set
up and how to extend them. Answer the owner (Tabsi1998) in German. Commits and
PR titles stay English.

## Local checks are the gate; GitHub only warns

The repository is private; GitHub Actions would cost minutes and stayed red
for billing, so there is no workflow at all (removed 2026-09-27 at the
owner's request). GitHub keeps only what it does for free: Dependabot alerts
for packages with known vulnerabilities (OSV checks the same here). Before
every push:

```bash
python scripts/local_check.py                  # everything but extra
python scripts/local_check.py --all            # plus the gates GitHub does not run
python scripts/local_check.py --only integration
python scripts/local_check.py --list           # the steps, without running them
```

Results: `.local-testing/local-check.json`, logs in `.local-testing/logs/`,
live progress in `.local-testing/local-check.progress.json`. All of it is
ignored by Git.

| Group | Runs |
| --- | --- |
| repository | every `*.sh` parses, no CRLF in the index, `git diff --check` over every tracked line (a check on a fresh checkout would see no diff), Gitleaks over the history and over uncommitted files |
| backend | for Python 3.10 and 3.14: venv from `requirements-dev.txt`, `pip check`, compileall, `import server`, `tests/test_unit_runtime.py`, `tests/test_inquiry_dolibarr.py`, `tests/test_handover.py` and `tests/test_site_data.py` |
| integration | a MongoDB container, uvicorn over HTTPS on Python 3.14, `tests/test_api.py` and `tests/test_regression_iter2.py` against it |
| dolibarr | Dolibarr 24.0.1 + MariaDB + Mailpit in Docker, prepared by `backend/tests/dolibarr_fixtures/fixtures.php` (modules, mail, an API user with only the website's rights, an existing customer), a website server of its own, `tests/test_dolibarr_runtime.py`: prospect + ticket + photo document, existing customer unchanged, confirmation and workshop mails, status by number/e-mail and by link, the website's test mail, queue with one warning while Dolibarr is unreachable, personal data gone after hand-over, migration of old records without mails, contact form without new third party, callback as agenda event, company data and imprint (with a moved address), draft marker on legal texts, FAQ only from released website articles, status steps "Angebot bereit" and "abholbereit" |
| web | the website and its admin in `web/` (Vite 8, React 19, Tailwind 3, same toolbox as LION): frozen install, ESLint with jsx-a11y strict, Vitest, `yarn build` (Vite build + SSR build + `scripts/prerender.mjs`, checked for prerendered text and no Google fonts), Playwright in 390, 768, 1280 and 1440 px against `vite preview` with the live Content-Security-Policy (a Vitest test keeps it equal to `server.py`), axe WCAG 2.1 AA in light and dark, keyboard, nothing beyond the screen edge, screenshots of every page in `web/screenshots/`; the e2e tests answer `/api` themselves (`web/e2e/fixtures.js`) |
| extra | every test file is run by some gate, OSV over the lockfiles, ShellCheck |
| deploy | `start.sh`, `stop.sh`, `update.sh` on a throwaway Ubuntu 24.04 server with systemd (`scripts/deploy-test/`): autostart, crash restart, reboot (`docker restart`), a broken update rolled back, a good update, `stop.sh --disable`, `USE_SYSTEMD=0`. With `--all` only when a deployment file changed against origin/main (about 15 minutes); `--only deploy` forces it. It tests the committed HEAD |

Tools the checks expect: Docker Desktop (MongoDB, OSV, ShellCheck), Git for
Windows (bash, openssl), gitleaks, Python 3.10 and 3.14 through the `py`
launcher, Node 24 with Corepack. A missing tool skips its steps with a hint.

## How the backend steps are isolated

- They run against `.local-testing/snapshot/backend`, a copy of what Git would
  commit. `server.py` loads `backend/.env`; a real `.env` with SMTP or Dolibarr
  credentials must never reach a test.
- The integration server speaks HTTPS with a throwaway certificate
  (`.local-testing/tls`). The login sets `Secure; SameSite=Lax` cookies, which a
  client never returns over plain HTTP; `REQUESTS_CA_BUNDLE` trusts the
  certificate for the test run only.
- Settings are throwaway values (`integration_env()`); the database name
  contains "test", as `conftest.py` requires.

## Work plan

The rework (design, Dolibarr as the single customer system, customer portal)
is planned as GitHub issues #25 onward with milestones "0 Design" to
"5 Kundenportal", "Rechtliches" and "Ideen (offen)". Issues are written in
German (Warum / Was zu tun ist / Abnahme). Every PR names its issues with
`Closes #N`; a German "Schließt" alone closes nothing.

- The login lives only in httpOnly cookies (`SameSite=Lax`); the API does not
  accept `Authorization: Bearer`. Integration tests use a logged-in
  `requests.Session` (`admin_client` in `conftest.py`).
- Company data and legal texts come from Dolibarr (issue #74), not from the
  website admin. Dolibarr 24.0.1 runs at erp.tabelander.co.at.

## Website and admin in production

`web/` is one Vite app: the website (prerendered) and the admin under
`/admin` (`src/admin/`, a lazy chunk visitors never download; `admin.html` is
its empty shell from `scripts/prerender.mjs`). `start.sh` builds it and
activates `web/dist` as `web/build`; staging, activation and rollback work on
that one directory. `backend/app/website.py` answers every address outside
`/api`: `/admin*` gets `admin.html`, old addresses redirect (301,
`OLD_ADDRESSES`), known pages get the SEO head (canonical, absolute og:image,
the admin's start page title/description, LocalBusiness JSON-LD from the
cached Dolibarr copy - a page never waits for Dolibarr), everything else
`404.html` with 404. The Content-Security-Policy is enforced everywhere.

`frontend/` (the old CRA admin) was removed in milestone 4. A server updated
from before still has `frontend/build` and `frontend/node_modules` (ignored
by Git); `start.sh` removes them after the first successful start of the new
version (`remove_legacy_frontend`), never before, because a failed update
rolls back to the old version, which serves `frontend/build`. The deploy
group plants such leftovers and checks they are gone.

Review request (#71, `backend/app/review_invites.py`): only for inquiries
with `review_ok` (an opt-in in the form, also written into the ticket). The
maintenance loop claims due records by moving `review.next_check_at` 30
minutes ahead, asks Dolibarr for the ticket state and, for a closed ticket,
marks `review.invited_at` before it sends one mail to the ticket's
`origin_email` - never another address, at most once. The link is
`/bewertung#<token>` (the fragment keeps the token out of logs and Referer);
`review_invites` stores its SHA-256 only. `/api/review-invites/check` and
`/submit` take the token in the body; the review is stored hidden with
`pending`, and `PUT /admin/reviews/{id}` with `visible: true` releases it. The
public review list is a field whitelist (`PUBLIC_FIELDS`).

Legal texts (#68, #69, #75, `backend/app/legal_texts.py`): the imprint is
the company data (checklist `IMPRINT_FIELDS`) plus an article; privacy policy
and terms are articles. A picked legal article is read by its number
(`site_data.fetch_article`), not through the website category - picking it is
the decision to publish it. Plain-language drafts live in
`backend/app/legal_drafts/*.html`; `POST /api/admin/legal/{kind}/draft`
writes one into the knowledge base as a draft and picks it. Places for the
owner are marked `[BITTE ERGÄNZEN|PRÜFEN|ENTSCHEIDEN|MIT DER WKO KLÄREN ...]`;
`open_points` counts them and the dashboard's `legal_todo` lists everything
open. A unit test keeps the periods in the privacy text equal to the code
(`ATTACHMENT_TTL`, `LINK_VALID`). Uvicorn runs with `--no-access-log`: the
website keeps no visitor IPs, the reverse proxy logs requests.

Device label (#72): `GET /api/admin/labels/{ref}` (inquiry or ticket number)
gives number, title (the local subject before the hand-over, the Dolibarr
ticket subject after it), date and the status link with the track id. The QR
code is drawn as SVG from `qrcode-generator` (`src/admin/qr.js`); a Vitest
test reads it back with jsQR. The paper size comes from
`public/print/label-roll.css` or `label-sheet.css`, linked only while the
label page is open; Playwright checks the PDF page size (62 x 29 mm, A4).

The admin (#57): `src/admin/api.js` renews an expired access cookie once via
`/api/auth/refresh` and retries; if that fails too, `admin-session-expired`
shows the sign-in form in place, and the same page opens after signing in.
`LoadState` renders a form only after its data loaded (a failed load can
never save over data). `ErrorBoundary` per page. Error texts come from the
backend's `detail` (pydantic lists mapped to field names in German).

## Ratchet

The extra group compares against `scripts/ci-baseline.json`: known findings
are debt, new ones fail. After paying debt down, run
`python scripts/local_check.py --all --record` and commit the baseline. The hard gates (repository, backend, integration, dolibarr, web) are never
ratcheted. Debt on 2026-09-27: no OSV findings (the 29 of the old CRA admin's
`frontend/yarn.lock` left with it in milestone 4), 4 ShellCheck findings.

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
`UNIT_TEST_FILES`, `INTEGRATION_TEST_FILES` or `DOLIBARR_TEST_FILES`, or the test
inventory fails.

Dolibarr facts the scenarios proved (24.0.1): the API user needs
`societe client voir`, or it sees only third parties it is sales
representative of; a ticket's state is written from `status` (`fk_statut` is
read only); points in time come as Unix seconds; the customer's confirmation
carries the status link only with `TICKET_ENABLE_PUBLIC_INTERFACE`, and the
ticket number only in the workshop mail's subject; `users/info` needs
"modify own user" (the website takes the ticket's `fk_user_create` as event
owner instead); an agenda event links to its ticket through `elementid`
(`fk_element` is refused in API requests); `setup/company` answers with the
private company note too, so the website keeps a whitelist; the
`API_LOGINS_ALLOWED_FOR_*` constants hold one login, not a list; creating a
knowledge article needs `status` in the request; the category API cannot tag
knowledge articles; the rights class of proposals is `propale`.

Keep the ports unique, so repositories can be checked side by side: API 18011,
MongoDB 27018 (container `it-tabelander-local-check-mongo`). The deploy group
publishes no port; its container is `it-tabelander-local-check-deploy`. The
dolibarr group: Dolibarr 18031, Mailpit 18131 (SMTP 18125), its website server 18013
(containers `it-tabelander-dolibarr-db/-mail/-web`, network
`it-tabelander-dolibarr-net`). The web group: `vite preview` for Playwright on
18015; `yarn dev` uses 3010.

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

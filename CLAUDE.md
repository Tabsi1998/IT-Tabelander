# CLAUDE.md

Build, test and code rules for IT-Tabelander. Operating and setup docs are in
`README.md` (German). Answer the owner (Tabsi1998) in German; commit and PR
titles stay English, PR bodies German with `Closes #N` (a German "Schließt"
alone closes nothing).

## Local checks are the gate

There is no GitHub workflow (the repository is public but all rights reserved,
see `LICENSE`; no CI by choice); GitHub only sends
Dependabot alerts. Before every push:

```bash
python scripts/local_check.py                  # everything but extra
python scripts/local_check.py --all            # plus extra; deploy only if a deploy file changed
python scripts/local_check.py --only dolibarr  # one group
python scripts/local_check.py --only dolibarr-modul  # the Dolibarr module, always with its Dolibarr run
python scripts/local_check.py --list           # the steps, without running them
IT_TABELANDER_DOLIBARR=24.0.1 python scripts/local_check.py --only dolibarr
```

Results in `.local-testing/local-check.json`, logs in `.local-testing/logs/`
(ignored by Git).

| Group | Runs |
| --- | --- |
| repository | shell scripts parse, no CRLF, `git diff --check`, Gitleaks |
| backend | Python 3.11 and 3.14: venv from `requirements-dev.txt`, `pip check`, compileall, `import server`, unit tests |
| integration | MongoDB container, uvicorn over HTTPS, `tests/test_api.py`, `tests/test_regression_iter2.py` |
| dolibarr | Dolibarr 23.0.3 (live release) + MariaDB + Mailpit in Docker, prepared by `backend/tests/dolibarr_fixtures/fixtures.php`, `tests/test_dolibarr_runtime.py` |
| dolibarr-modul | `dolibarr/ittabelanderpdf/`: PHP lint (local PHP), release ZIP built and verified; when something under `dolibarr/` changed against origin/main: Dolibarr 23.0.3 of its own via the module's `scripts/dev_dolibarr.py`, module from its ZIP, PHP 8.2 lint in the image, fixtures, PDFs, mail scenarios, rollout texts, UI/WAF, ticket mails |
| web | `web/`: frozen install, ESLint (jsx-a11y strict), Vitest, build + prerender, Playwright at 390/768/1280/1440 px with axe WCAG 2.1 AA |
| extra | test inventory, OSV over the lockfiles, ShellCheck (ratchet) |
| deploy | `start.sh`/`stop.sh`/`update.sh` on a throwaway Ubuntu 24.04 with systemd (`scripts/deploy-test/`), tests the committed HEAD |

Tools: Docker Desktop, Git for Windows (bash, openssl), gitleaks, Python 3.11
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
3010, production 8001; module check Dolibarr 18033, Mailpit 18133; the
module's own `dev_dolibarr.py` 18051/18151.

## Code map

- `backend/app/`: `dolibarr.py` (client), `handover.py` (queue to Dolibarr),
  `site_data.py` (company data, opening hours, knowledge articles, cache;
  the categories FAQ, Rechtstexte and Unterlagen decide, never a title),
  `legal_texts.py` + `legal_drafts/`, `legal_pdf.py` (legal PDFs from the
  ECM folder `IT-Tabelander/Unterlagen`, fixed names), `portal.py` (customer area),
  `portal_inquiries.py` (inquiries in the customer area: history, answers, closing, new inquiry),
  `portal_files.py` (their files: which the customer sees, uploads checked by content),
  `review_invites.py`, `mailer.py`, `mail_layout.py` (HTML + text of customer
  mails in the look of the Dolibarr module's mails: one style block for dark
  mode by class only, no tables; Du, so always "Liebe Grüße", never "Viele
  Grüße"), `website.py` (pages, redirects, SEO head, 404), `routers/`.
- `web/`: one Vite app - website (prerendered), admin `src/admin/`, customer
  area `src/portal/` (lazy chunks, empty shells from `scripts/prerender.mjs`).
- `dolibarr/ittabelanderpdf/`: the Dolibarr module (below).
- `docs/unterlagen/`: company documents as HTML for Dolibarr's knowledge base
  (drafts for the WKO review, see its README); `scripts/import_unterlagen.py`
  brings them in as drafts, standard library only. Both are part of the
  backend snapshot for their tests.
- Customer data lives only in Dolibarr; the website never changes an existing
  third party. Public output goes through field whitelists.
- The Content-Security-Policy is enforced everywhere; a Vitest test keeps the
  `vite preview` copy equal to `server.py`.

## Windows notes

- venvs live in `~/.local-ci/IT-Tabelander/`, never inside the repository.
- Environment variables that look like credentials are withheld from every
  step; only their names are printed.
- `tzdata` goes into the venvs: Windows has no zoneinfo database.

## Dolibarr module (`dolibarr/ittabelanderpdf/`)

The module `ittabelanderpdf` (Werkbank PDFs, e-mail templates, automatic
send, ticket mails), brought in with its history by `git subtree` (#113). It
has its own `CLAUDE.md` (code layout, traps) and `CHANGELOG.md`; docs German
in `README-DE.md`. Licence: everything under `dolibarr/` is GPL-3.0-or-later,
the fonts OFL 1.1 (exception in the root `LICENSE`; the root stays all rights
reserved, so GitHub shows no open-source licence).

- Version: `$this->version` in `core/modules/modITTabelanderPDF.class.php`,
  section in the module `CHANGELOG.md`. Patch for fixes, minor for features.
- Check: `python scripts/local_check.py --only dolibarr-modul` (part of the
  default run; the Dolibarr part runs only when `dolibarr/` changed). Examples
  go to `.local-testing/dolibarr-modul/out/`. To refresh the committed
  samples in `docs/beispiele/`, run `python scripts/dev_dolibarr.py all` in the
  module folder and commit what changed (mail samples are exempt from the
  whitespace check via the module's `.gitattributes`).
- `build_release.py` packs only files Git tracks below the module folder
  (`git ls-files -- .`): `git add` new files before building; the check fails
  on untracked files under `dolibarr/`. The ZIP is byte for byte reproducible.

### Module release (after the owner merged)

One GitHub release per module version, tag `dolibarr-v<version>`, ZIP and
`.sha256` as assets, notes from the module `CHANGELOG.md`, never marked
`latest` (the website has no releases of its own). Build from the merged
`main` (a clean worktree of `origin/main`):

```bash
cd dolibarr/ittabelanderpdf
python scripts/build_release.py                          # dist/module_ittabelanderpdf-X.Y.Z.zip + .sha256
python scripts/build_release.py --notes X.Y.Z > dist/notes.md
gh release create dolibarr-vX.Y.Z dist/module_ittabelanderpdf-X.Y.Z.zip dist/module_ittabelanderpdf-X.Y.Z.zip.sha256 --target main --title "Dolibarr-Modul IT-Tabelander PDF X.Y.Z" --notes-file dist/notes.md --latest=false
```

The ZIP of a commit is the same on every machine: compare its SHA-256 with
the previous build before uploading.

Then install the ZIP in Dolibarr (Einstellungen -> Module -> Externes Modul
bereitstellen), deactivate and activate the module once so new constants and
templates arrive, and follow `docs/ROLLOUT.md` for texts outside the module.

### Traps

- Dolibarr's WAF (`waf.inc.php`) answers 403 to `<style`, `=data:`, `<svg`
  and `on*=` attributes in any POST: templates and rollout texts must not
  contain them, or they cannot be saved or sent by hand. Dark mode exists only
  in `WerkbankMailTemplates::wrapDocument()` for the automatic send.
- CKEditor takes tables apart and Dolibarr's restricthtml drops classes: no
  layout tables, inline styles only.
- Never test against erp.tabelander.co.at; `dev_dolibarr.py` and the check use
  throwaway containers.
- Portal links: `WerkbankMailData::portalUrl()` maps each customer document
  to its customer-area page (`angebote`, `rechnungen`, `belege/...`). A new
  page on the website needs its path there too.

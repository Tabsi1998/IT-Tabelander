# CLAUDE.md

Dolibarr module `ittabelanderpdf` (module number 756230): Werkbank PDFs, e-mail templates, automatic sending. The module lives in `dolibarr/ittabelanderpdf/` of the IT-Tabelander repository (since 1.0.7; before that a repository of its own), the ZIP wraps this folder in `ittabelanderpdf/`. The root `CLAUDE.md` of IT-Tabelander applies too (issues, branches, local check, release `dolibarr-vX.Y.Z`). Commit and PR titles English, docs German. Licence GPL-3.0-or-later, fonts OFL 1.1.

## Layout of the code

- `class/werkbankrenderer.class.php` draws a document from a plain array (head, address, info block, title, table, bottom block, footer, page breaks, fonts). No Dolibarr objects in there.
- `class/werkbankbuilder.class.php` + `werkbankbuilder.<type>.class.php`: one builder per document type turns the Dolibarr object into that array. A new type = a new builder + a model file + an entry in `WerkbankModel::$werkbankBuilders` and `modITTabelanderPDF::MODEL_TYPES`.
- `core/modules/*/doc/pdf_werkbank.modules.php`: thin; every file declares `pdf_werkbank` only if it does not exist yet, the class works for every type (two equally named classes in one process would be fatal, e.g. in the cron).
- Company documents (Unterlagen): `werkbankunterlagen.class.php` (categories, slug, ECM folder and index, file list, mail defaults, PDFs appended to proposal/order/invoice), `werkbankbuilder.unterlage.class.php` (knowledge base article -> sheet; `html()` whitelists the article HTML and puts inline styles on it), trigger `interface_50_modITTabelanderPDF_ITTabPdfUnterlagen.class.php`, hooks in `actions_ittabelanderpdf.class.php` (`knowledgerecordcard`, `formmail` via `getFormMail`, `ticketcard`). The renderer knows `layout => sheet` (no address/info block), `blocks` (`html` flows over pages) and `append` (PDF pages after the count).
- E-mail: `werkbankmailtemplates.class.php` (templates, rollout blocks), `werkbankmaildata.class.php` (facts, greeting), `core/substitutions/functions_ittabelanderpdf.lib.php` (`__ITTAB_*__`), `werkbankmailqueue.class.php` (queue, cron, send), trigger `core/triggers/interface_99_modITTabelanderPDF_ITTabPdfAutoSend.class.php`, hooks `class/actions_ittabelanderpdf.class.php`, list `queue.php`.

## Check locally

```bash
python scripts/dev_dolibarr.py all    # Dolibarr 23.0.3 + MariaDB + Mailpit in Docker, module from the ZIP, sample data, PDFs, e-mail scenarios, rollout texts, UI check, ticket mails
python scripts/dev_dolibarr.py down
python scripts/build_release.py       # dist/module_ittabelanderpdf-x.y.z.zip
```

The gate is the repository's `python scripts/local_check.py --only dolibarr-modul` (from the repository root): the same steps in containers of their own, examples into `.local-testing/`. `build_release.py` packs only files Git tracks: `git add` new files first.

The `mails` step fails when one of its checks fails (see `docs/beispiele/mails/uebersicht.json`). Look at the PDFs in `docs/beispiele/` after layout changes. Never test against erp.tabelander.co.at.

## Traps

- `getFormMail` has no hook inside the attachment row: the hook builds the form again itself (a static flag marks the inner call, which adds the files to the session list) and puts the choice in front of `<input type="hidden" class="removedfilehidden"`. Its button must be named like the form's `addfile`, any other submit name would send the mail. Ticket messages (`FormTicket`) have no hook at all: a box via `formConfirm` on the ticket card.
- TCPDF `setPage()` restores the page break setting per page: pages opened by flowing HTML carry auto page break on; switch it off again before drawing the footer.
- `KNOWLEDGERECORD_VALIDATE` fires before the status is set on the object; the card sets categories before `update()` but after `create()` (then `CATEGORY_MODIFY` with `context['linkto']`).
- In `init()` a module switched on in the same request is invisible to `isModEnabled()`: check the extra field table instead.

- Builders must not depend on libraries the card page happens to load (cron context): require them (e.g. `functions2.lib.php`).
- `__ITTAB_FAKTEN__` and friends are built only in mode `formemail`; PDF substitutions must stay cheap and must not recurse into a builder.
- Mahnwesen prints its e-mail text into the dunning letter up to `<!--MAHNWESEN_PDF_END-->`.
- E-mail templates: no `<style>` (Dolibarr's waf.inc.php answers 403 to "<style" in any form, so the template could not be saved or sent by hand), no classes (restricthtml drops them), no layout tables. Dark mode exists only in `WerkbankMailTemplates::wrapDocument()` for the automatic send and finds elements by their inline colours.
- Bash heredocs on Windows mangle `\n` inside Python/PHP strings: write such edits with the Edit tool.

# CLAUDE.md

Dolibarr module `ittabelanderpdf` (module number 756230): Werkbank PDFs, e-mail templates, automatic sending. The module lives in `dolibarr/ittabelanderpdf/` of the IT-Tabelander repository (since 1.0.7; before that a repository of its own), the ZIP wraps this folder in `ittabelanderpdf/`. The root `CLAUDE.md` of IT-Tabelander applies too (issues, branches, local check, release `dolibarr-vX.Y.Z`). Commit and PR titles English, docs German. Licence GPL-3.0-or-later, fonts OFL 1.1.

## Layout of the code

- `class/werkbankrenderer.class.php` draws a document from a plain array (head, address, info block, title, table, bottom block, footer, page breaks, fonts). No Dolibarr objects in there.
- `class/werkbankbuilder.class.php` + `werkbankbuilder.<type>.class.php`: one builder per document type turns the Dolibarr object into that array. A new type = a new builder + a model file + an entry in `WerkbankModel::$werkbankBuilders` and `modITTabelanderPDF::MODEL_TYPES`.
- `core/modules/*/doc/pdf_werkbank.modules.php`: thin; every file declares `pdf_werkbank` only if it does not exist yet, the class works for every type (two equally named classes in one process would be fatal, e.g. in the cron).
- Company documents as the module's object: `werkbankdoc.class.php` (draft, release = version + PDF + archive, takeover from the knowledge base), `unterlagen_list.php`, `unterlage_card.php`, REST API `class/api_ittabelanderpdf.class.php` (class name = endpoint `Ittabelanderpdf`, docblocks short, helpers start with `_`). Files: `werkbankunterlagen.class.php` (ECM folder and index, file list, mail defaults, PDFs appended to proposal/order/invoice, removal of the module's own old files, knowledge articles for the takeover), `werkbankbuilder.unterlage.class.php` (knowledge base article -> sheet; `html()` whitelists the article HTML and puts inline styles on it), hooks in `actions_ittabelanderpdf.class.php` (`knowledgerecordcard`, `formmail` via `getFormMail`, `ticketcard`). The renderer knows `layout => sheet` (no address/info block), `blocks` (`html` flows over pages) and `append` (PDF pages after the count).
- Workshop: `werkbankqs.class.php` (kinds of job, points, runs with their own copy of the points, close + test report, shipped checklists), `werkbankbuilder.qs.class.php` (test report), `werkbankintake.class.php` + `werkbankbuilder.intake.class.php` (device intake slip of a ticket, a sheet), `qs.php` + `ajax/qs.php` + `css/qs.css` + `js/qs.js` (phone page, autosave with `currentToken()` and `NOTOKENRENEWAL`), `admin/werkstatt.php` (setup tab, also for users with the right "manage"), trigger `interface_50_modITTabelanderPDF_ITTabPdfQs.class.php` (`FICHINTER_CLOSE`). Tables in `sql/llx_ittabpdf_qs_*.sql`.
- Signatures: `werkbanksignature.class.php` (load, roles, save/decode PNG, PDF again via `WerkbankQs::writePdf()` / `WerkbankIntake::write()` with `forPdf()`, copy to the third party, recipient, send), `sign.php` + `ajax/sign.php` + `js/sign.js` (pads), table `llx_ittabpdf_signature`, `sql/update_1.3.0.sql`. The renderer puts `signatures` given as array(label, image, name, when) on the line.
- Documents per customer: `werkbankdocs.class.php` (rules, who needs what, status computed from rules + events every time, events, proposal acceptance), `docs.php` (tab), `admin/docrules.php` (rules), trigger `interface_50_modITTabelanderPDF_ITTabPdfDocs.class.php` (`*_SENTBYMAIL` with `attachedfiles`, `PROPAL_CLOSE_SIGNED`, `LINECONTRACT_ACTIVATE`), widget `core/boxes/box_ittabpdf_docs.php`, signature kind `doc` in `werkbanksignature.class.php` + `werkbankbuilder.docsign.class.php`. Events are ordered by insertion (a tick may be dated back); a `revoked` event ends the history that counts.
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
- A signature (or any image) never goes as `data:` URL through a form: the WAF refuses `=data:`. Base64 without padding passes (no `=`, no `<`, no `:`), `$_POST` raw, decoded and checked server side. A transparent PNG is two image objects in the PDF (picture and mask).
- `GETPOST(..., 'alphanohtml')` strips `<...>`: an address like `Name <mail>` needs `alphawithlgt`.
- `dol_escape_htmltag()` turns line breaks into a literal `\n`: textareas need `dol_escape_htmltag($text, 0, 1)`.
- Page JavaScript and CSS go through `llxHeader(..., array('/ittabelanderpdf/js/qs.js'), array('/ittabelanderpdf/css/qs.css'))`, so they get Dolibarr's CSP nonce; no inline handlers.
- New pages use Dolibarr's own building blocks (load_fiche_titre, dol_get_fiche_head, table.noborder/border tableforfield, butAction, dolGetStatus); no own colours or cards (Fabian, 26.11.2026). The PDFs keep the Werkbank design.
- In `init()` a module switched on in the same request is invisible to `isModEnabled()`: check the extra field table instead.

- Builders must not depend on libraries the card page happens to load (cron context): require them (e.g. `functions2.lib.php`).
- `__ITTAB_FAKTEN__` and friends are built only in mode `formemail`; PDF substitutions must stay cheap and must not recurse into a builder.
- Mahnwesen prints its e-mail text into the dunning letter up to `<!--MAHNWESEN_PDF_END-->`.
- E-mail templates: no `<style>` (Dolibarr's waf.inc.php answers 403 to "<style" in any form, so the template could not be saved or sent by hand), no classes (restricthtml drops them), no layout tables. Dark mode exists only in `WerkbankMailTemplates::wrapDocument()` for the automatic send and finds elements by their inline colours.
- Bash heredocs on Windows mangle `\n` inside Python/PHP strings: write such edits with the Edit tool.

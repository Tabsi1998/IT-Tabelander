# CLAUDE.md

Dolibarr module `ittabelanderpdf` (module number 756230): Werkbank PDFs, e-mail templates, automatic sending. The repository root is the module folder; the ZIP wraps it in `ittabelanderpdf/`. Commit and PR titles English, docs German.

## Layout of the code

- `class/werkbankrenderer.class.php` draws a document from a plain array (head, address, info block, title, table, bottom block, footer, page breaks, fonts). No Dolibarr objects in there.
- `class/werkbankbuilder.class.php` + `werkbankbuilder.<type>.class.php`: one builder per document type turns the Dolibarr object into that array. A new type = a new builder + a model file + an entry in `WerkbankModel::$werkbankBuilders` and `modITTabelanderPDF::MODEL_TYPES`.
- `core/modules/*/doc/pdf_werkbank.modules.php`: thin; every file declares `pdf_werkbank` only if it does not exist yet, the class works for every type (two equally named classes in one process would be fatal, e.g. in the cron).
- E-mail: `werkbankmailtemplates.class.php` (templates, rollout blocks), `werkbankmaildata.class.php` (facts, greeting), `core/substitutions/functions_ittabelanderpdf.lib.php` (`__ITTAB_*__`), `werkbankmailqueue.class.php` (queue, cron, send), trigger `core/triggers/interface_99_modITTabelanderPDF_ITTabPdfAutoSend.class.php`, hooks `class/actions_ittabelanderpdf.class.php`, list `queue.php`.

## Check locally

```bash
python scripts/dev_dolibarr.py all    # Dolibarr 23.0.3 + MariaDB + Mailpit in Docker, module from the ZIP, sample data, PDFs, e-mail scenarios, rollout texts, UI check
python scripts/dev_dolibarr.py down
python scripts/build_release.py       # dist/module_ittabelanderpdf-x.y.z.zip
```

The `mails` step fails when one of its checks fails (see `docs/beispiele/mails/uebersicht.json`). Look at the PDFs in `docs/beispiele/` after layout changes. Never test against erp.tabelander.co.at.

## Traps

- Builders must not depend on libraries the card page happens to load (cron context): require them (e.g. `functions2.lib.php`).
- `__ITTAB_FAKTEN__` and friends are built only in mode `formemail`; PDF substitutions must stay cheap and must not recurse into a builder.
- Mahnwesen prints its e-mail text into the dunning letter up to `<!--MAHNWESEN_PDF_END-->`.
- E-mail templates: no `<style>` (Dolibarr's waf.inc.php answers 403 to "<style" in any form, so the template could not be saved or sent by hand), no classes (restricthtml drops them), no layout tables. Dark mode exists only in `WerkbankMailTemplates::wrapDocument()` for the automatic send and finds elements by their inline colours.
- Bash heredocs on Windows mangle `\n` inside Python/PHP strings: write such edits with the Edit tool.

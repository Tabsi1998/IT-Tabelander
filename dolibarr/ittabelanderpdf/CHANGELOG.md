# Changelog

## [2.0.0] - unreleased

### Operation and help (#180)

- Setup tab "Texte außerhalb des Moduls": the Mahnwesen templates and the ticket introduction in the module's look, compared with what Dolibarr has, written there after confirmation; the old values go into a backup file first (Documents → ittabelanderpdf → rollout). `TICKET_MESSAGE_MAIL_SIGNATURE` is emptied (the reply template ends with the user's signature). Replaces copying from `docs/rollout/`.
- Setup tab "Systemcheck": rights of the website's API user, the module's cron job, the mail logo reachable without sign-in, templates up to date, legal texts released and public, rules pointing to released documents that may be sent, price list categories, e-mail collector, CAPTCHA – each with its state, what it means and a link to fix it.
- Tab "Hilfe": short guides (send, generate, sign, documents, rules, following up, QS, intake, price list, templates, system check) with links.
- Clean-up: the old mail building blocks (sheet with logo on top, orange line, footer) are gone; English texts complete.

### Following up (#178)

- Start page widget "Offen": required documents missing, documents sent and not answered for N days (with the days), signatures begun on the device, QS checklists not closed – each with a link to where to act.
- Reminders: with "Erinnerung" switched on (off by default), the cron job reminds of required documents sent and not answered after N days (default 7), at most M times (default 2), with the new template "Erinnerung Unterlagen · Sie/Du" and the document attached again; in the history and the agenda ("Erinnerung verschickt").

### Sending (#177)

- "Alle fehlenden zusenden" on top of the tab: one e-mail with every open required document; templates per customer are generated on the way (missing details: the form first, then back).
- Facts box, bank details and buttons built from what Dolibarr's editor keeps (font on spans, tint as background-color, label above value): a hand-sent mail looks like an automatic one. The editor test checks these parts too.

### Documents tab and agenda (#176)

- Tab "Unterlagen": no menus any more. Each line shows what to do next as a button and every other action as a small link with an icon, wrapping on narrow screens; on a phone every document is a card. On top a progress bar "3 von 5 erledigt". The tab of the third party shows the number of open required documents.
- History in Dolibarr's agenda: event type "Unterlage" (`AC_ITTAB_DOC`, created on activation). Generated, signed, uploaded, ticked, added, removed, reset and "neue Fassung nötig" become finished events with the third party and, where there is one, the contract, proposal, intervention or ticket (tab "Ereignisse/Agenda" of both), with title, source, file and note. Sent by e-mail is left to Dolibarr's own event. QS test reports closed and intake slips signed likewise. The history so far is written once on activation (`fk_actioncomm` per row, `update_2.0.0.sql`). Setting "Verlauf in der Agenda" (default on).
- A new version of a document with "neu bestätigen" marks every customer who had confirmed an older one ("neue Fassung nötig", also in the tab's history).

## [1.9.0] - 2026-11-26

- New mail layout for every module mail (templates Sie/Du, automatic send, ticket confirmation and owner notice, signed copies, documents, rollout texts for Mahnwesen and tickets): no logo and no orange line on top any more (CKEditor turned the line into an empty block); the mail starts with the greeting and ends in the style of the owner's signature: closing words, name and company, a rule (`<hr />`), the logo at 180 px, address, e-mail, web and phone, all from the company data and variables.
- Only what CKEditor and the WAF keep: paragraphs, one div, `<hr />`, inline styles. The frame (560 px, background, dark design) is added only by the automatic send. The dark logo is a second image next to the light one (no blocks inside the paragraph).
- Untouched templates of 1.8.x are brought up to date on activation (`PREVIOUS` hashes); edited ones only with "Vorlagen aktualisieren".
- New test stage `editor`: the templates are opened in Dolibarr's CKEditor and saved as a person would; the text keeps its blocks and words and gains no empty block.
- `docs/rollout/` (Mahnwesen, ticket texts) in the new layout; the letter keeps its closing, the e-mail adds the signature after the marker.

## [1.8.2] - 2026-11-26

- No own hosting any more: the shipped rules ask for "Leistungsbeschreibung IT-Betreuung und Wartung" (`leistungsbeschreibung-betreuung-wartung`, and `-privat`) and, like the AVV, for contracts with `DL-WART*` (was `DL-HOST*,DL-WART*`).
- Upgrade: rules, default attachments and appended documents of the old file names (`leistungsbeschreibung-hosting-wartung`, `-privat`) move to the new ones as soon as the new document is released (on activation and when releasing it); a rule keeps a label or products the owner changed. The old documents stay, marked "nicht versendbar"; what was handed over under the old name stays in the history.
- Help texts name `DL-WART*` as the example.

## [1.8.1] - 2026-11-26

- Mail forms: one recipient is preselected when Dolibarr selects none (it does only with exactly one): the contact of the document (proposal, contract: customer contact), else a contact with a role at the third party, else the third party's address. Never several.
- The greeting of the module's templates follows the chosen recipient ("Guten Tag Frau Muster," / "Hallo Maria,"); changing the recipient builds the text again while nobody changed it. Unchanged texts are built again in every mail form on reload, not only on third parties.
- "Beilegen" without a file no longer shows Dolibarr's "file required" error.
- Several documents: "anbei erhalten Sie folgende Unterlagen als PDF:" (Du likewise).
- "Angehängte Dateien": the three choices one below the other, label left, field right, same width.
- Tab "Unterlagen": one main action per line (generate, continue or start signing, send), the rest in Dolibarr's "Weitere Aktionen" menu, so the page no longer scrolls sideways. History names documents by title. "Weitere Unterlage" offers only documents that may be sent. Kinds read "Rechtstext", "Unterlage (auf Anfrage)", "Vorlage je Kunde"; "nicht versendbar" is a mark of its own. "Vertrag"/"Angebot" translated in the generate form.

## [1.8.0] - 2026-11-26

- Any released document can be added to one customer by hand ("Weitere Unterlage" at the bottom of the tab "Unterlagen"): a line of its own with the same states, removable again (history stays). A template per customer goes on to its form right away. Also from contracts and proposals (button "Unterlage erzeugen"; the generated PDF is filed with the customer, the contract and the proposal) and from the card of a template per customer ("Für Kunden erzeugen …" with the customer choice). Technically a rule for one customer (`fk_soc`, `update_1.8.0.sql`); the setup lists only the general rules.
- A template per customer is never sent blank: it has no general PDF (an old one in the folder is removed on activation and when the kind changes), its preview is a "Muster". Chosen under "Unterlagen beilegen" it is generated for the customer of the form with what was entered last time and the contract or proposal of the card; missing required fields lead to the form, and back into the mail form with the finished document attached. Without a customer it cannot be chosen.
- "Unterlagen beilegen" in three groups: generate for this customer, already generated for this customer, general documents. New field "an Kunden versendbar" per document (default yes; the Geräteannahme text blocks, QS checklists and the imprint addition are off); only those are offered, also as default attachments and for appending to the proposal PDF. Other files in the folder are no longer offered.
- New templates "Unterlagen zusenden · Sie/Du": subject with the title for one document, "Ihre/Deine Unterlagen von …" for several, the list with title, "Stand" and what to do (confirm, sign and send back or sign on the device, for information; "für Sie ausgefüllt" for generated ones), opening and closing for one or several. Variables `__ITTAB_UNTERLAGEN_BETREFF__`, `__ITTAB_UNTERLAGEN_EINLEITUNG__`, `__ITTAB_UNTERLAGEN_LISTE__`, `__ITTAB_UNTERLAGEN_SCHLUSS__` (each also `_DU`). The text is built again when documents are attached or removed, as long as it was not changed by hand; otherwise the form offers to rebuild it.
- On a third party the mail form picks "Unterlagen zusenden" when documents are attached, else "Nachricht" (Sie or Du after the customer's setting) instead of Dolibarr's "Hallo," with the signature; a template picked by hand stays.
- "Unterlage · Sie/Du" is now "Unterschriebene Unterlage · Sie/Du" (the copy after signing on the device) with a text for that case; renamed on activation, untouched ones brought up to date. No facts box and no customer area button in the third party templates.

## [1.7.1] - 2026-11-26

- Mail template labels without brackets: "Angebot · Sie", "Nachricht · Du" … Dolibarr's mail form shows only the text in brackets of a label (it takes it for a translation key), so the templates appeared as "Sie", "Du", "Sie", "Du". On activation (and with "update templates" in the setup) the module's templates of 1.7.0 and earlier get the new label; topic and text stay, also of edited templates; a second run changes nothing. Chosen templates in the setup keep working (they are stored by id).
- Template "Nachricht": one empty line to write in between greeting and closing (`<p><br></p>`), the closing right below it; no placeholder text that could be sent by mistake. Untouched templates are brought up to date.

## [1.7.0] - 2026-11-26

- Price list from Dolibarr's products: placeholder `__ITTAB_PREISLISTE__` with one section per chosen product category (order of the category tree), only products and services for sale above 0 €, price with unit (unit or duration of a service). Setup block "Preisliste aus den Produkten": categories, section for the others, descriptions, automatic rewrite (on by default), button to write them now.
- When a product changes (created, modified, price, deleted, put into or out of a category), the released documents with `__ITTAB_PREISLISTE__` or `__ITTAB_PREIS:<REF>__` get their PDF written again with today as "Stand"; the archive copy of the released version stays.
- Company placeholders and the date are filled in general documents too; a block placeholder alone in a paragraph replaces the paragraph.
- `docs/unterlagen/preisliste.html` uses the placeholder instead of fixed prices.

## [1.6.0] - 2026-11-26

- Documents made for one customer: a document of the kind "template per customer" gets a button "Erzeugen" on the customer's "Unterlagen" tab. Contract, contact and the template's input fields (`[[FELD:name*|label|type|defaults]]`: text, textarea, choice of several, choice of one, date, number) are asked, prefilled from last time; missing required fields or placeholders left over block with a message. The PDF lands with the customer and the contract, with both signature lines, state "Erzeugt"; sending it marks it sent, signing on the device signs the generated document itself. Generating again later makes it "Erzeugt" again, so the new version has to go out or be signed again.
- Placeholders `__ITTAB_…__` for customer, company, contract, services, business hours (setting, else the company's opening hours), date and prices by product reference (`__ITTAB_PREIS:<REF>__`), plus Dolibarr's own; a line whose value is empty (e.g. no VAT number) disappears. The same placeholders work in the notices of the intake slip and the test report. A list to copy from in the setup tab "Unterlagen je Kunde" and on each document's card.
- `docs/unterlagen`: AVV and Fernwartung consent use the placeholders and input fields; the header line `Art: Vorlage je Kunde` makes the import script create a template per customer.

## [1.5.0] - 2026-11-26

- Company documents and legal texts are the module's own object now (menu Geschäftspartner → "Unterlagen", list and card in Dolibarr's look), apart from the knowledge base, which stays for the FAQ. A document has title, file name, kind (legal text, internal, template per customer), public yes/no and a text; "Freigeben" makes the draft a version that never changes (date, who, note), writes `IT-Tabelander/Unterlagen/<slug>.pdf` and archives `Archiv/<slug>-v<n>.pdf`, so the version a customer got can always be shown. PDF preview of the draft. Tables `llx_ittabpdf_doc`, `llx_ittabpdf_docversion`; rights "Unterlagen lesen" (on by default) and "Unterlagen bearbeiten und freigeben".
- Takeover: on activation (when there are no documents yet) and with a button in the setup and on the article card, the articles of the chosen knowledge base categories become documents (released ones as version 1 with their date, drafts as drafts, "Rechtstexte" as public legal texts). The articles stay; their card points to the document. PDFs the module wrote for an article under another file name (before the file name was set) are removed, uploads never; a file name changed later also leaves no duplicate.
- REST API of the module: `GET /ittabelanderpdf/unterlagen` (public released legal texts), `…/unterlagen/{slug}` (with the HTML of the released version), `…/unterlagen/{slug}/pdf` (base64); for the import script `GET …/unterlagen-entwuerfe` and `PUT …/unterlagen-entwuerfe/{slug}` (drafts only, right "bearbeiten").
- `scripts/import_unterlagen.py` writes into the module's documents (drafts, by file name); `--wissensmanagement` keeps the earlier target.
- Rules of "Unterlagen je Kunde" combine their conditions: customer type AND contract product AND customer tag, each optional (`update_1.5.0.sql` carries the single condition of 1.4.0 over). Shipped: Leistungsbeschreibung for companies and a second one for private persons.
- Pages in Dolibarr's own look: QS checklist (table with tick, value, does not apply, note), signing page and the customer's "Unterlagen" tab (table, actions as buttons, forms for upload, tick and reset on demand) use Dolibarr's titles, tables, buttons and status badges; the module's stylesheet only sizes tick boxes and the signature pad.

## [1.4.0] - 2026-11-26

- Documents per customer: tab "Unterlagen" on third parties lists every company document the customer needs or may get, with its state (missing, sent, signing, done, new version), date, how, who, the document's "Stand", the file and a history. Rules in the new setup tab "Unterlagen je Kunde" (document by file name, accept / sign / confirm receipt, for all customers, companies, private persons, a validated contract with an open line whose product ref matches, e.g. `DL-HOST*,DL-WART*`, or a customer tag; required or optional; "neue Fassung neu bestätigen"). Shipped rules: AGB Unternehmer (companies), Nutzungsbedingungen (private), AVV and Leistungsbeschreibung (hosting/maintenance contracts), Einverständnis Fernwartung (tag "Fernwartung", switched off until the tag exists). New tables `llx_ittabpdf_docrule`, `llx_ittabpdf_docevent`.
- Ticked automatically: "sent" when the document goes out attached to any e-mail (trigger `*_SENTBYMAIL`); "signed" when a proposal it went with (or that carries it appended) is signed, "im Kundenbereich angenommen" for the website's acceptance; "signed" after a signature on the device (`sign.php?kind=doc`: confirmation sheet with the document behind it, AVV and remote maintenance by both, the others by the customer, saved with the customer); "uploaded" for a signed scan or photo (PDF/JPG/PNG, also straight from the phone camera, stored with the customer). By hand: tick with date and note, reset with a reason.
- Visible: banner "2 Unterlagen fehlen: …" with a button on customer, proposal, contract and intervention cards (switch `ITTABPDF_DOCS_BANNER`); start page widget "Fehlende Unterlagen"; activating a contract line whose product needs a missing document warns or is refused (`ITTABPDF_DOCS_CONTRACT_CHECK` off/warn/block, default warn). "Per E-Mail senden" opens the customer's mail form with the document attached.
- New template "Unterlage (Sie/Du)" for sending the signed document.

## [1.3.0] - 2026-11-26

- Sign on the device (`sign.php`, simple electronic signature): after "QS abschließen" the page goes on to signing; the ticket card has "Am Gerät unterschreiben" for the intake slip. One pad per signer (technician and/or customer as the kind of job says; both on the intake slip), finger or pen, name in block letters. The drawing is cropped and sent as base64 without "data:" prefix and "=" padding (`ajax/sign.php`), so Dolibarr's WAF lets it pass; it is checked as a real PNG, stored in the object's documents (`signatures/`) and in the new table `llx_ittabpdf_signature`, and the PDF is written again at once: image on the signature line, name, "elektronisch unterschrieben am …". Signing again replaces; reopening a QS drops its signatures.
- Test report and intake slip are copied into the third party's documents as well.
- Sending: once every signature is there, the page shows the suggested recipient (contact of the document, else the third party, else the request's address; setting "Empfänger") to check or change, then sends with the new templates "Prüfprotokoll (Sie/Du)" and "Geräteannahme (Sie/Du)" (choosable in the setup), the BCC of the automatic send and the agenda entry `FICHINTER_SENTBYMAIL` / `TICKET_SENTBYMAIL`. The run notes when and to whom (`update_1.3.0.sql`: `date_sent`, `sent_to`).
- `WerkbankMailQueue::send()` takes given attachments and a confirmed recipient; facts box for tickets.

## [1.2.0] - 2026-11-26

- QS checklists: kinds of job (Auftragsart) with their points in the module setup, tab "Werkstatt und QS" (add, rename, sort, switch off; kinds Tick, Value with unit, Text; required yes/no; help text; who signs; notice on the report). Seven shipped checklists (repair, upgrade, PC build, controller mod, Windows reinstall and data transfer, website handover, on-site job), written only when an entity has none. New tables `llx_ittabpdf_qs_type`, `_item`, `_run`, `_result`; rights "QS ausführen" and "QS-Checklisten verwalten".
- `qs.php`, made for a phone or the workshop tablet: customer → open intervention or a new one → kind of job → tick points with value and note, each saved at once (`ajax/qs.php`, page token, `NOTOKENRENEWAL`), progress at the top, "entfällt" for points that do not apply. Tabs "QS" on interventions and third parties, menu entries under Interventions.
- "QS abschließen": refused while required points are open (they are marked), else the test report (Prüfprotokoll) as Werkbank PDF in the intervention's documents, `<REF>-pruefprotokoll-<n>.pdf`: every point with tick, value, note, who and when, remarks, signature lines. Closing the intervention closes finished QS and, with `ITTABPDF_QS_BLOCK_CLOSE` (default on), is refused while a QS has required points open. Optional: test reports behind the work report PDF (`ITTABPDF_QS_APPEND_WORKREPORT`).
- Device intake slip (Geräteannahmeschein) from the ticket card: one sheet with customer, device, accessories, condition, fault, access data needed and backup (select extra fields on tickets, options editable in Dolibarr), optionally a line for the password on paper only (switch `ITTABPDF_INTAKE_PASSWORD_FIELD`, off by default), notices by Sie or Du from the setup (defaults after the text proposals of 26.11.2026), two signature lines; saved in the ticket's documents.
- Renderer: blocks section, fields (empty = line to write on), choices with tick boxes, checks, notices; signatures may carry an image, name and time; no table gap on sheets.

## [1.1.0] - 2026-11-26

- Company documents (Unterlagen) from the knowledge base: released articles of the chosen categories (default "Rechtstexte" and "Unterlagen", chosen by id in the setup; "FAQ" gets none) become Werkbank PDFs with title, "Stand", headings, lists, tables and page numbers, saved as `IT-Tabelander/Unterlagen/<slug>.pdf` in Documents (ECM folder and index created on the fly). New extra field `ittab_slug` on articles (default from the title, umlauts spelt out); a name used by another article is refused.
- Written again automatically on release, change or when put into a chosen category (trigger `ITTabPdfUnterlagen`, switch `ITTABPDF_UNTERLAGEN_AUTO`), by hand with "PDF erzeugen" on the article card (which also shows where the file is) or "Alle freigegebenen Unterlagen neu erzeugen" in the setup. A failed PDF never blocks saving the article.
- "Unterlagen beilegen" in Dolibarr's e-mail form (proposal, order, invoice, contract, intervention, shipment, third party; posts as "addfile", so the text stays) and in a box above the ticket message form. Default attachments per document type for companies and private persons (third party type "Privatperson"); proposals default to `agb-unternehmer.pdf` (companies) and `nutzungsbedingungen.pdf`, `widerruf.pdf` (private persons), missing files are skipped.
- `werkbank` appends a company document to proposal, order and invoice PDFs (`ITTABPDF_APPEND_PROPAL`/`_ORDER`/`_INVOICE`), and honours Dolibarr's own `MAIN_PDF_ADD_TERMSOFSALE_*` when the module setting is empty.
- Renderer: sheet layout without address field, flowing HTML over pages with the follow-up head, appended PDFs after the page count.

## [1.0.9] - 2026-11-26

- Questions by e-mail or through the customer area, not by phone: the templates no longer say "Bei Fragen erreichen Sie mich unter <Telefon>". New variables `__ITTAB_FRAGEN_SATZ__`/`__ITTAB_FRAGEN_SATZ_DU__` ("antworten Sie einfach auf diese E-Mail", with the customer area on also "oder schreiben Sie mir im Kundenbereich"); supplier templates ask for a reply by e-mail. The phone number stays in the footer. Templates holding the 1.0.8 text are renewed on activation.
- Ticket confirmation: with the customer area on, a button to the ticket's page there (`/kundenbereich/anfragen/<id>`, `portalUrl()` knows tickets now) instead of the public status link, and "oder antworten Sie einfach auf diese E-Mail"; no phone sentence.
- Owner notice: long values (customer name with its number) stand below their label instead of pushing it under them on a phone.

## [1.0.8] - 2026-11-26

- Customer area links for every customer document: orders, shipments, interventions and contracts now open their own page of the website's customer area (`/kundenbereich/belege/auftraege|lieferscheine|arbeitsberichte|vertraege/<id>`), not just its start page.
- The button "Im Kundenbereich ansehen" (`__ITTAB_KUNDENBEREICH_BLOCK__`, only with `ITTABPDF_PORTAL_ENABLED`) is in every customer template now (invoice, down payment, credit note, payment confirmation, order, shipment, intervention, contract), after the transfer and tracking blocks. Templates that still hold the 1.0.7 text are renewed on activation; edited ones stay and get the variable by hand.

## [1.0.7] - 2026-11-24

- Ticket confirmation: line breaks of plain-text tickets were shown as a literal `\n` (escaping without keeping them). The customer's confirmation no longer repeats the ticket text, which may carry internal notes from the website; it shows number, subject and status link. Subject without the company suffix.
- New: the owner's notice for a new ticket (`TICKET_NOTIFICATION_EMAIL_TO`) in German in the same sheet: facts box (ticket, type, group, severity, customer, e-mail), the full text and a button into Dolibarr. Replaces Dolibarr's English list in the API user's language. Dolibarr's own notice still goes out if sending fails, if the customer confirmation failed, or if the ticket has an assignee.
- Recipient of the confirmation taken from `context['contact_id']` like Dolibarr.

## [1.0.6] - 2026-11-24

- Own confirmation for a new ticket (trigger `ITTabPdfTicketConfirm`, runs before Dolibarr's ticket trigger): the sheet of the automatic send with logo, greeting by Du/Sie of the third party, the request (number, subject, text) in the facts box, link to the public ticket page, closing and footer, light and dark. Dolibarr's built-in text ("please do not reply", every extra field as a line, no signature) is no longer sent. If sending fails, Dolibarr's own confirmation goes out as before. Switch `ITTABPDF_TICKET_CONFIRM` (default on).
- Template "Ticket-Antwort" now ends with "Mit freundlichen Grüßen" and `__USER_SIGNATURE__`; `TICKET_MESSAGE_MAIL_SIGNATURE` stays empty. The 1.0.5 default is renewed on activation, edited ones stay.

## [1.0.5] - 2026-11-24

- On activation, create the category link tables for proposals, interventions and supplier proposals from Dolibarr's own SQL files when the Categories module is on. Dolibarr 23 only creates them for invoices and orders; without them the proposal card fails with "Table llx_categorie_propal doesn't exist".

## [1.0.4] - 2026-11-24

- Dark mode of automatically sent e-mails: every tag gets a class from its inline colours before wrapping (`classFromColours`), the dark stylesheet uses these classes. Samsung Mail switched the background and the logo but ignored the attribute selectors, so the text stayed dark on dark. Attribute selectors without the CSS4 ` i` flag stay as a fallback.

## [1.0.3] - 2026-11-24

- E-mail templates without `<style>` and without layout tables: only blocks with inline styles. Dolibarr's WAF refuses `<style` in every form (templates could not be saved, hand-sent mails not sent), and editors drew frames around table cells.
- Two designs: light like the PDF (all paths) and a deliberate dark design (website dark colours, light logo) that only the automatic send adds as a full document with `@media (prefers-color-scheme: dark)` and Outlook's `[data-ogsc]`/`[data-ogsb]`. Hand-sent mails stay light and carry the light logo alone.
- Dark logo: `public/maillogo.php?variant=dark` from `ITTABPDF_MAIL_LOGO_DARK` (DMS path, default `IT-Tabelander/Bilder/Logo/IT-Tabelander Banner Hell Transparent.png`); without it the light logo on white.
- Mobile pass: 16 px text, 560 px column, full-width facts box (label left, value right) and button, the orange line as a 2 px border, one fine divider above a calm footer; no outer frame, no section borders, no invisible joiners in the address.
- Module templates still holding the 1.0.0-1.0.2 text are renewed on activation; Mahnwesen and ticket rollout texts in the same design.

## [1.0.2] - 2026-11-24

- E-mails always light like the PDF: full HTML document with `color-scheme: light only`, white surfaces set as `bgcolor` and style, explicit text colours, no grey page and no dark footer block any more; the sheet is framed by a thin line.
- Logo from the new public endpoint `public/maillogo.php`: the company logo as an opaque PNG on white (max. 480 px, shown at 240 px, cached), so forced dark modes cannot make it disappear. `ITTABPDF_MAIL_LOGO_URL` still overrides.
- New footer: hairline and four grey lines (company and manager, address, e-mail and web, phone); no register numbers in e-mails. The phone number is its own neutral `tel:` link, the address does not get auto-linked.
- Mobile: 24 px side padding, 2 px bar on the facts box, shorter button text ("Im Kundenbereich ansehen"), the phone number does not wrap.
- Closing lines: "Mit freundlichen Grüßen" (Sie and neutral), "Liebe Grüße" (Du).
- Setup page: "Modul-Vorlagen auf aktuellen Stand bringen" (with confirmation) overwrites only the module's own templates. On activation, module templates that still hold the text of 1.0.0/1.0.1 are renewed automatically; edited ones stay.
- Rollout texts for Mahnwesen and tickets in the same light design.

## [1.0.1] - 2026-11-24

- E-mail logo is now a linked image (public company logo thumbnail via `viewimage.php?modulepart=mycompany`, or `ITTABPDF_MAIL_LOGO_URL`) instead of an inline data:/cid: image. With the SMTPS send mode the inline image showed as broken plus an extra attachment on mobile clients.

## [1.0.0] - 2026-11-24

- PDF template `werkbank` for proposals and invoices (invoice, down payment, replacement invoice, credit note) with EPC QR code, service date, partial payments.
- `werkbank` for orders, shipments, interventions, contracts (minimum term, notice period, billing interval), supplier price requests, purchase orders and goods receipts.
- Brand fonts Sora, Source Sans 3 and Michroma embedded.
- 23 e-mail templates (Sie/Du), module e-mail variables `__ITTAB_*__`, company logo embedded as an inline image.
- Switch "customer area of the website live" (off by default): proposal PDF and e-mails ask for a reply by e-mail instead.
- Automatic sending after a delay, cancellable on the card, with queue page and setup.
- Production texts for Mahnwesen and tickets in `docs/rollout/`.

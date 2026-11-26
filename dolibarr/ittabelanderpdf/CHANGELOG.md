# Changelog

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

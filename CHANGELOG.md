# Changelog

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

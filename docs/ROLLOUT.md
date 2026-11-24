# Rollout auf erp.tabelander.co.at

Reihenfolge für die Umstellung der Produktion (Dolibarr 23.0.3) auf Werkbank-PDFs und -E-Mails. Jeder Schritt lässt sich einzeln prüfen; nichts davon löscht Daten.

## 0. Vorher

- Datenbank-Sicherung ziehen (wie bei jedem Modul-Update).
- Prüfen, dass unter **Einstellungen → Geplante Jobs** Jobs laufen (Server-Cron ruft alle 5 Minuten `scripts/cron/cron_run_jobs.php` auf).

## 1. Modul installieren und aktivieren

1. **Start → Einstellungen → Module/Anwendungen → Reiter „Externes Modul bereitstellen“**: `module_ittabelanderpdf-1.0.0.zip` hochladen.
2. In der Modulliste **IT-Tabelander PDF** einschalten.
   Danach gibt es: Vorlage `werkbank` bei allen neun Belegarten, die Zusatzfelder „Anrede per Du“ (Kunden/Lieferanten), „Mindestlaufzeit (Monate)“ und „Kündigungsfrist“ (Verträge), 23 E-Mail-Vorlagen, den geplanten Job „IT-Tabelander PDF: automatic e-mails“ und **Rechnungen → Automatischer Versand**. Der automatische Versand ist noch **aus**.

## 2. Firmendaten und Bankkonto prüfen

- **Einstellungen → Firma/Organisation**: Name, Adresse, Manager (`Fabian Tabelander`), Mobil (`+436766039945`), E-Mail, Web, Logo, Berufs-ID 2 `Innsbruck`, Berufs-ID 4 `GISA-Zahl: 39417879`, Berufs-ID 6 `GLN: 9110039041378`, „Umsatzsteuer: nein“.
- **Rechnungen → Einstellungen → Zahlungsarten**: Bankkonto für Überweisungen gewählt (`FACTURE_RIB_NUMBER`); beim Konto IBAN, BIC und Kontoinhaber eingetragen. Ohne IBAN gibt es auf Rechnungen keinen QR-Code.

## 3. Werkbank als Standardvorlage

Je Belegart **Einstellungen → Module → (Modul) Zahnrad → Dokumentvorlagen**, bei `werkbank` „Standard“ setzen:

Angebote · Rechnungen · Aufträge · Lieferungen · Serviceaufträge · Verträge · Lieferanten-Preisanfragen · Lieferantenbestellungen · Wareneingänge

Kontrolle: ein Angebot und eine Rechnung öffnen, „Erzeugen“ mit `werkbank`, PDF ansehen.

## 4. E-Mail-Vorlagen: neue an, alte aus

**Einstellungen → E-Mails → E-Mail-Vorlagen** (`admin/mails_templates.php`):

1. Die alten Vorlagen **„Angebot senden“**, **„Rechnung Senden“** und **„Auftragsbestätigung senden“** auf **inaktiv** stellen (nicht löschen).
2. Optional bei „Angebot (Sie)“, „Rechnung (Sie)“, „Auftragsbestätigung (Sie)“ „Standard für diesen Typ“ setzen, damit sie beim händischen Versand vorausgewählt sind.

## 5. Mahnwesen-Vorlagen im Werkbank-Look

Dateien: `docs/rollout/mahnwesen/` (je Stufe `*.html` = Inhalt, `*.betreff.txt` = Betreff). Platzhalter genau wie im Plugin (`{INVOICE_REF}`, `{INVOICE_DATE}`, `{DUE_DATE}`, `{OPEN_AMOUNT}`, `{DUNNING_TOTAL}`, `{FEE_PARAGRAPH}`, `{INTEREST_PARAGRAPH}`, `{PAYMENT_PARAGRAPH}`, `{COMPANY_NAME}`).

| Vorlage in Produktion | Datei |
| --- | --- |
| Mahnwesen - Zahlungserinnerung | `zahlungserinnerung.html` |
| Mahnwesen - 1. Mahnung | `mahnung-1.html` |
| Mahnwesen - 2. Mahnung | `mahnung-2.html` |
| Mahnwesen - 3. Mahnung | `mahnung-3.html` |

In der jeweiligen Vorlage den Editor auf **Quelltext** stellen, den bisherigen Inhalt ersetzen, speichern. Falls die Produktionstexte gegenüber den Plugin-Standardtexten geändert wurden: vorher vergleichen (die neuen Texte folgen inhaltlich den Standardtexten).

Wichtig: Mahnwesen druckt den E-Mail-Text auch in den Mahnbrief (PDF), und zwar alles **vor** `<!--MAHNWESEN_PDF_END-->`. Deshalb stehen Wortmarke und dunkler Fuß in diesen Vorlagen **nach** der Marke, nur in der E-Mail; im Brief kommen Logo und Fuß aus dem Mahnwesen-Briefkopf. Die Marke nicht entfernen.

## 6. Ticket-Texte

Dateien: `docs/rollout/ticket/`. Dolibarr setzt Ticket-Mails aus Teilen zusammen:

- Neues Ticket über das öffentliche Formular: `TICKET_MESSAGE_MAIL_NEW` + Ticket-Infos (Nummer, Link) + `TICKET_MESSAGE_MAIL_SIGNATURE`
- Antwort aus Dolibarr: `TICKET_MESSAGE_MAIL_INTRO` + Nachricht + `TICKET_MESSAGE_MAIL_SIGNATURE`

Deshalb enthält die Einleitung Wortmarke und Anrede, die Signatur Gruß und Fuß, und die neue Vorlage **„Ticket-Antwort“** (Typ `ticket_send`, aus dem Modul) nur den Mittelteil. So erscheint nichts doppelt.

| Einstellung | Wo | Datei |
| --- | --- | --- |
| `TICKET_MESSAGE_MAIL_NEW` | Einstellungen → Module → Tickets → Öffentliche Oberfläche | `neues-ticket.html` |
| `TICKET_MESSAGE_MAIL_INTRO` | Einstellungen → Module → Tickets | `einleitung.html` |
| `TICKET_MESSAGE_MAIL_SIGNATURE` | Einstellungen → Module → Tickets | `signatur.html` |

Im Editor auf Quelltext stellen und einfügen. Die Texte siezen neutral („Guten Tag,“), weil Anfragen von der Website ohne Du/Sie-Angabe kommen.

## 7. Fußtexte entschlacken

Der Kleinunternehmer-Hinweis steht jetzt unter dem Gesamtbetrag (Moduleinstellung `ITTABPDF_TAX_NOTICE`). In **Rechnungen → Einstellungen → „Freier Text auf Rechnungen“** (`INVOICE_FREE_TEXT`) und ggf. bei Angeboten (`PROPOSAL_FREE_TEXT`) den Satz „Umsatzsteuerbefreit – Kleinunternehmer …“ entfernen, sonst steht er zweimal im PDF. Übrig bleiben sollte nur, was sonst nirgends steht (z. B. ein AGB-Hinweis); leer ist auch in Ordnung.

## 8. Du-Kunden markieren

Bei Kunden, die geduzt werden, das Feld **„Anrede per Du“** setzen. Ohne Häkchen wird gesiezt.

## 9. Automatischen Versand einschalten

**Einstellungen → Module → IT-Tabelander PDF (Zahnrad)**:

- Angebot freigegeben / Rechnung freigegeben / Rechnung bezahlt: je nach Wunsch einschalten.
- Verzögerung: 5 Minuten. Blindkopie: `office@tabelander.co.at` (vorbelegt mit der Firmen-E-Mail).
- Adresse des Kundenbereichs: `https://it.tabelander.co.at/kundenbereich`.

Unter **Einstellungen → Geplante Jobs** prüfen, dass „IT-Tabelander PDF: automatic e-mails“ aktiv ist (alle 5 Minuten).

## 10. Testmail an office@

1. Testkunden mit E-Mail `office@tabelander.co.at` anlegen (oder einen vorhandenen internen Kunden nehmen).
2. Angebot mit einer Zeile anlegen und freigeben. Auf der Karte erscheint „Automatischer Versand geplant um …“.
3. Einmal **Abbrechen** testen, dann ein zweites Angebot freigeben und warten (oder den Job unter „Geplante Jobs“ von Hand ausführen).
4. In der Mail prüfen: Werkbank-Look, Anrede, Betrag, PDF im Anhang, BCC an office@. In der Ereignisliste des Angebots steht der Versand.
5. Dasselbe mit einer Rechnung und einer Zahlung (Zahlungsbestätigung).
6. Testbelege danach selbst bereinigen.

## Rückweg

Modul in der Modulliste ausschalten, bei den Belegarten wieder `cyan`/`sponge` als Standard setzen, die alten E-Mail-Vorlagen wieder aktivieren. Die Warteschlange und die Vorlagen des Moduls bleiben in der Datenbank, stören aber nicht.

# Rollout auf erp.tabelander.co.at

Reihenfolge für die Umstellung der Produktion (Dolibarr 23.0.3) auf Werkbank-PDFs und -E-Mails. Jeder Schritt lässt sich einzeln prüfen; nichts davon löscht Daten.

## 0. Vorher

- Datenbank-Sicherung ziehen (wie bei jedem Modul-Update).
- Prüfen, dass unter **Einstellungen → Geplante Jobs** Jobs laufen (Server-Cron ruft alle 5 Minuten `scripts/cron/cron_run_jobs.php` auf).

## 1. Modul installieren und aktivieren

1. **Start → Einstellungen → Module/Anwendungen → Reiter „Externes Modul bereitstellen“**: `module_ittabelanderpdf-1.0.2.zip` hochladen.
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
2. Optional bei „Angebot · Sie“, „Rechnung · Sie“, „Auftragsbestätigung · Sie“ „Standard für diesen Typ“ setzen, damit sie beim händischen Versand vorausgewählt sind.

## 5. Mahnwesen-Vorlagen im Werkbank-Look

**Ab 2.0.0:** Einstellungen → IT-Tabelander PDF → **Texte außerhalb des Moduls** schreibt diese Vorlagen und die Ticket-Einleitung per Knopf (Vorschau, Bestätigung, Sicherung der alten Werte). Die Schritte unten sind nur noch der Weg von Hand.

Dateien: `docs/rollout/mahnwesen/` (je Stufe `*.html` = Inhalt, `*.betreff.txt` = Betreff). Platzhalter genau wie im Plugin (`{INVOICE_REF}`, `{INVOICE_DATE}`, `{DUE_DATE}`, `{OPEN_AMOUNT}`, `{DUNNING_TOTAL}`, `{FEE_PARAGRAPH}`, `{INTEREST_PARAGRAPH}`, `{PAYMENT_PARAGRAPH}`, `{COMPANY_NAME}`).

| Vorlage in Produktion | Datei |
| --- | --- |
| Mahnwesen - Zahlungserinnerung | `zahlungserinnerung.html` |
| Mahnwesen - 1. Mahnung | `mahnung-1.html` |
| Mahnwesen - 2. Mahnung | `mahnung-2.html` |
| Mahnwesen - 3. Mahnung | `mahnung-3.html` |

In der jeweiligen Vorlage den Editor auf **Quelltext** stellen, den bisherigen Inhalt ersetzen, speichern. Falls die Produktionstexte gegenüber den Plugin-Standardtexten geändert wurden: vorher vergleichen (die neuen Texte folgen inhaltlich den Standardtexten).

Wichtig: Mahnwesen druckt den E-Mail-Text auch in den Mahnbrief (PDF), und zwar alles **vor** `<!--MAHNWESEN_PDF_END-->`. Deshalb steht die Signatur mit Linie, Logo und Kontakt (ab 1.9.0) in diesen Vorlagen **nach** der Marke, nur in der E-Mail; im Brief kommen Logo und Fuß aus dem Mahnwesen-Briefkopf. Die Marke nicht entfernen.

Das Logo steht in den Mahnwesen-Texten als `__ITTAB_LOGO__`. Mahnwesen schickt seine Texte durch Dolibarrs Variablen-Ersetzung, dabei setzt dieses Modul das Firmenlogo ein. Das funktioniert nur, solange IT-Tabelander PDF aktiv ist; sonst steht dort der Text `__ITTAB_LOGO__`.

## 6. Ticket-Texte

Dateien: `docs/rollout/ticket/`. Dolibarr setzt Ticket-Mails aus Teilen zusammen:

- Neues Ticket über das öffentliche Formular: `TICKET_MESSAGE_MAIL_NEW` + Ticket-Infos (Nummer, Link) + `TICKET_MESSAGE_MAIL_SIGNATURE`
- Antwort aus Dolibarr: `TICKET_MESSAGE_MAIL_INTRO` + Nachricht + `TICKET_MESSAGE_MAIL_SIGNATURE`

Deshalb enthält die Einleitung Wortmarke und Anrede, die Signatur Gruß und Fuß, und die neue Vorlage **„Ticket-Antwort“** (Typ `ticket_send`, aus dem Modul) nur den Mittelteil. So erscheint nichts doppelt.

**Stand ab 1.0.6/1.0.7:** Die Eingangsbestätigung für neue Tickets und die Benachrichtigung an `TICKET_NOTIFICATION_EMAIL_TO` schickt das Modul selbst (eigener Trigger, `TICKET_MESSAGE_MAIL_NEW` wird dafür nicht mehr verwendet). Die Vorlage „Ticket-Antwort“ endet mit „Mit freundlichen Grüßen“ und `__USER_SIGNATURE__` (Signatur des Benutzers mit Logo), `TICKET_MESSAGE_MAIL_SIGNATURE` bleibt leer.

| Einstellung | Wo | Datei |
| --- | --- | --- |
| `TICKET_MESSAGE_MAIL_NEW` | Einstellungen → Module → Tickets → Öffentliche Oberfläche | `neues-ticket.html` |
| `TICKET_MESSAGE_MAIL_INTRO` | Einstellungen → Module → Tickets | `einleitung.html` |
| `TICKET_MESSAGE_MAIL_SIGNATURE` | Einstellungen → Module → Tickets | `signatur.html` |

Im Editor auf Quelltext stellen und einfügen. Die Texte siezen neutral („Guten Tag,“), weil Anfragen von der Website ohne Du/Sie-Angabe kommen. Die Ticket-Texte zeigen die Wortmarke als Text statt des Logos: Dolibarr ersetzt in `TICKET_MESSAGE_MAIL_NEW` keine Variablen, ein Logo ginge dort nur als fest eingebettetes Bild.

## 7. Fußtexte entschlacken

Der Kleinunternehmer-Hinweis steht jetzt unter dem Gesamtbetrag (Moduleinstellung `ITTABPDF_TAX_NOTICE`). In **Rechnungen → Einstellungen → „Freier Text auf Rechnungen“** (`INVOICE_FREE_TEXT`) und ggf. bei Angeboten (`PROPOSAL_FREE_TEXT`) den Satz „Umsatzsteuerbefreit – Kleinunternehmer …“ entfernen, sonst steht er zweimal im PDF. Übrig bleiben sollte nur, was sonst nirgends steht (z. B. ein AGB-Hinweis); leer ist auch in Ordnung.

## 8. Du-Kunden markieren

Bei Kunden, die geduzt werden, das Feld **„Anrede per Du“** setzen. Ohne Häkchen wird gesiezt.

## 9. Automatischen Versand einschalten

**Einstellungen → Module → IT-Tabelander PDF (Zahnrad)**:

- Angebot freigegeben / Rechnung freigegeben / Rechnung bezahlt: je nach Wunsch einschalten.
- Verzögerung: 5 Minuten. Blindkopie: `office@tabelander.co.at` (vorbelegt mit der Firmen-E-Mail).
- Adresse des Kundenbereichs: `https://it.tabelander.co.at/kundenbereich`.
- **„Kundenbereich der Website aktiv“ bleibt aus**, solange der Kundenbereich auf der Website nicht freigeschaltet ist. Dann steht im Angebots-PDF „Bitte kurz per E-Mail an office@tabelander.co.at bestätigen. Gearbeitet wird erst nach Zusage.“ und die Angebotsmails bitten um eine Antwort auf die Mail, ohne Button. Sobald der Kundenbereich live ist, nur diesen Schalter einschalten: PDF und Mails zeigen dann Kundenbereich und Button, an den Vorlagen ist nichts zu ändern.

Unter **Einstellungen → Geplante Jobs** prüfen, dass „IT-Tabelander PDF: automatic e-mails“ aktiv ist (alle 5 Minuten).

## 10. Testmail an office@

1. Testkunden mit E-Mail `office@tabelander.co.at` anlegen (oder einen vorhandenen internen Kunden nehmen).
2. Angebot mit einer Zeile anlegen und freigeben. Auf der Karte erscheint „Automatischer Versand geplant um …“.
3. Einmal **Abbrechen** testen, dann ein zweites Angebot freigeben und warten (oder den Job unter „Geplante Jobs“ von Hand ausführen).
4. In der Mail prüfen: Werkbank-Look, Anrede, Betrag, PDF im Anhang, BCC an office@. In der Ereignisliste des Angebots steht der Versand.
5. Dasselbe mit einer Rechnung und einer Zahlung (Zahlungsbestätigung).
6. Testbelege danach selbst bereinigen.

## Update auf 1.0.3 (Mails hell und dunkel)

1. `module_ittabelanderpdf-1.0.3.zip` über „Externes Modul bereitstellen“ hochladen, Modul einmal aus- und einschalten. Unveränderte Modul-Vorlagen (Stand 1.0.0 bis 1.0.2) bekommen dabei den neuen Text; von Hand geänderte bleiben, bis du sie mit „Modul-Vorlagen auf aktuellen Stand bringen“ ersetzt.
2. Das Logo für den Dunkelmodus liegt im DMS unter `IT-Tabelander/Bilder/Logo/IT-Tabelander Banner Hell Transparent.png` (Einstellung `ITTABPDF_MAIL_LOGO_DARK`). Prüfen im privaten Fenster: `https://erp.tabelander.co.at/custom/ittabelanderpdf/public/maillogo.php?variant=dark`.
3. Mahnwesen-Vorlagen und Ticket-Texte aus `docs/rollout/` neu einfügen.
4. Was wo ankommt: Der automatische Versand schickt ein ganzes Dokument mit hellem und dunklem Design (Mail-Programme mit Dunkelmodus zeigen die dunkle Fassung mit hellem Logo). Händisch versendete Mails, Mahnwesen und Tickets tragen nur Inline-Stile: Sie sind immer die helle Fassung, die Programme mit erzwungenem Dunkelmodus selbst umfärben; das Logo bleibt dann ein weißes Schild.
5. Ab jetzt kann der HTML-Text direkt in den Vorlagen (Einstellungen → E-Mails → E-Mail-Vorlagen) angepasst und über office@ getestet werden. Grenzen: kein `<style>` (Dolibarr blockt das beim Speichern mit 403), keine Klassen (werden beim Speichern entfernt), Stile nur inline. Die dunkle Fassung findet die Elemente über ihre Inline-Farben (#3B4D63 Text, #1B3348 Dunkelblau, #4A5B6E Grau, #F7F9FB Kasten, #D6DEE7 Linie, #ffffff Fläche); neue Elemente in diesen Farben werden automatisch mit umgefärbt.

## Update auf 1.0.2 (helle Mails)

1. `module_ittabelanderpdf-1.0.2.zip` wie oben über „Externes Modul bereitstellen“ hochladen.
2. Das Modul in der Modulliste einmal **aus- und wieder einschalten** (Einstellungen bleiben). Dabei bekommen alle Modul-Vorlagen, die noch den Text von 1.0.0/1.0.1 haben, den neuen Stand. Von Hand geänderte Modul-Vorlagen bleiben; die Einstellungsseite zeigt, wie viele das sind. Mit **„Modul-Vorlagen auf aktuellen Stand bringen“** (mit Rückfrage) werden auch diese ersetzt. Eigene Vorlagen und die anderer Module fasst der Knopf nicht an.
3. In einem privaten Browserfenster `https://erp.tabelander.co.at/custom/ittabelanderpdf/public/maillogo.php` öffnen: Es muss das Logo auf Weiß kommen, ohne Anmeldung. Wird Dolibarr später von außen gesperrt, braucht dieses eine Bild weiter eine öffentliche Adresse; sonst `ITTABPDF_MAIL_LOGO_URL` auf ein PNG auf der Website setzen.
4. Mahnwesen-Vorlagen (`docs/rollout/mahnwesen/`) und Ticket-Texte (`docs/rollout/ticket/`) wie in Schritt 5 und 6 neu einfügen. Die Ticket-Texte zeigen auf `https://erp.tabelander.co.at/custom/ittabelanderpdf/public/maillogo.php` (fest eingetragen, weil Dolibarr dort keine Variablen ersetzt).
5. Testmail an office@ (Schritt 10), am Handy auch im dunklen Modus ansehen.

## Rückweg

Modul in der Modulliste ausschalten, bei den Belegarten wieder `cyan`/`sponge` als Standard setzen, die alten E-Mail-Vorlagen wieder aktivieren. Die Warteschlange und die Vorlagen des Moduls bleiben in der Datenbank, stören aber nicht.

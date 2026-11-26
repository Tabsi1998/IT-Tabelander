# IT-Tabelander PDF (Dolibarr-Modul `ittabelanderpdf`)

Belege im Werkbank-Design von IT-Tabelander, mit den echten Markenschriften, dazu passende E-Mail-Vorlagen und ein verzögerter, abbrechbarer automatischer Versand.

Modulnummer **756230** · Version 1.5.0 · getestet mit Dolibarr 23.0.3 (PHP 8.2) · Mindestversion Dolibarr 21, PHP 7.4

![Entwurf](docs/entwurf/mockup.png)

## Was das Modul kann

PDF-Vorlage **werkbank** für:

| Belegart | Titel im PDF | Besonderheiten |
| --- | --- | --- |
| Angebot | „Angebot.“ | Gültig bis, Annehmen-Text, Unterschriftszeile, leeres Signaturfeld bei `MAIN_PDF_PROPAL_USE_ELECTRONIC_SIGNING` |
| Rechnung | „Rechnung.“, „Anzahlungsrechnung.“, „Ersatzrechnung.“, „Gutschrift.“ | Leistungsdatum (§ 11 UStG), Überweisung mit EPC-QR-Code (GiroCode), Teilzahlungen („Bereits bezahlt“, „Offen“), Gutschrift mit „Zu Rechnung“ und „Erstattet“ |
| Auftrag | „Auftragsbestätigung.“ | Liefertermin, Zu Angebot |
| Lieferung | „Lieferschein.“ | ohne Preise, Bestellt/Geliefert, Versandart, Sendungsnummer, Unterschrift „Ware erhalten“ |
| Serviceauftrag | „Arbeitsbericht.“ (ohne Einsätze „Serviceauftrag.“) | Einsätze mit Datum und Dauer, Gesamtdauer, zwei Unterschriftszeilen |
| Vertrag | „Vertrag.“ | Mindestlaufzeit, Kündigungsfrist, je Leistung Beginn, Ende geplant, Abrechnungsintervall |
| Lieferanten-Preisanfrage | „Preisanfrage.“ | Mengen ohne Preise |
| Lieferantenbestellung | „Bestellung.“ | Lieferantenreferenzen, Lieferadresse |
| Wareneingang | „Wareneingang.“ | intern, Bestellt/Erhalten, Prüfzeile |

Für alle gilt: Kopf mit Logo und Kontakt, Falzmarken, Anschrift nach DIN 5008 (Dolibarr-Funktion `pdf_build_address`, Kontakt als „z. H.“), Infoblock, Titel mit Kundenreferenz, Einleitung aus der öffentlichen Notiz, Tabelle ohne senkrechte Linien, Summenblock, Fußtext der Belegart (`PROPOSAL_FREE_TEXT`, `INVOICE_FREE_TEXT`, … mit Variablen), Fuß mit Firmendaten auf jeder Seite, „Seite x / y“. Mehrseitig: Folgeseiten mit kleinem Kopf, Tabellenkopf wiederholt, Summen-/Zahlungsblock wird nie geteilt. Entwürfe bekommen das Dolibarr-Wasserzeichen (`PROPALE_DRAFT_WATERMARK`, `FACTURE_DRAFT_WATERMARK`, …).

Ist die Firma nicht umsatzsteuerpflichtig (Firmeneinstellung „Umsatzsteuer: nein“), steht unter dem Gesamtbetrag der Kleinunternehmer-Hinweis aus den Moduleinstellungen. Ist sie es, zeigt der Summenblock Netto, USt je Satz und Brutto.

**E-Mail-Vorlagen** (Einstellungen → E-Mail-Vorlagen, Modul „ittabelanderpdf“): Angebot, Rechnung, Anzahlungsrechnung, Gutschrift, Zahlungsbestätigung, Auftragsbestätigung, Lieferung, Arbeitsbericht, Vertrag und eine allgemeine Nachricht, jeweils als „· Sie“ und „· Du“ (z. B. „Angebot · Sie“; ohne Klammern, weil Dolibarrs Mailformular bei Klammern nur deren Inhalt zeigt); Preisanfrage und Bestellung an Lieferanten neutral; „Ticket-Antwort“ (Text mit Gruß und Benutzer-Signatur `__USER_SIGNATURE__`, die Einleitung kommt aus den Ticket-Einstellungen). **Eingangsbestätigung für neue Tickets:** Statt Dolibarrs eingebautem Text schickt das Modul eine eigene Bestätigung im selben Aufbau wie Angebot und Rechnung (Anrede nach Du/Sie, Nummer und Betreff in der Box, Link zum Stand, Signatur, hell und dunkel; den Anfragetext nicht, weil er interne Hinweise der Website enthalten kann). Die Benachrichtigung an `TICKET_NOTIFICATION_EMAIL_TO` kommt ebenfalls vom Modul, auf Deutsch mit allen Angaben, dem vollen Text und einem Knopf ins Ticket. Schlägt ein Versand fehl, geht Dolibarrs eigene Mail raus. Abschalten: Konstante `ITTABPDF_TICKET_CONFIRM` = 0. Vorhandene Vorlagen werden nie überschrieben. Werkbank-Fassungen der Mahnwesen- und Ticket-Texte für die Produktion liegen in `docs/rollout/`, die Schritte in `docs/ROLLOUT.md`.

**Automatischer Versand** für: Angebot freigegeben (mit PDF), Rechnung freigegeben (mit PDF; Gutschrift und Anzahlungsrechnung mit eigener Vorlage), Rechnung vollständig bezahlt (Zahlungsbestätigung ohne Anhang). Der Versand geht erst nach einer Verzögerung (Standard 5 Minuten) hinaus und lässt sich bis dahin auf der Karte des Belegs abbrechen.

**Unterlagen** (Nutzungsbedingungen, Widerruf, AGB, interne Checklisten …) kommen aus dem Wissensmanagement von Dolibarr und werden zu PDFs im selben Design – siehe [Unterlagen](#unterlagen).

## Installation

1. `module_ittabelanderpdf-1.5.0.zip` in Dolibarr hochladen: **Start → Einstellungen → Module/Anwendungen → Reiter „Externes Modul bereitstellen“**.
2. In der Modulliste **IT-Tabelander PDF** aktivieren. Dabei passiert:
   - die Vorlage `werkbank` wird für alle neun Belegarten eingeschaltet (noch nicht als Standard),
   - Zusatzfelder: Kunde/Lieferant „Anrede per Du“ (`ittab_du`), Vertrag „Mindestlaufzeit (Monate)“ (`ittab_mindestlaufzeit`) und „Kündigungsfrist“ (`ittab_kuendigungsfrist`),
   - 23 E-Mail-Vorlagen, die Tabelle `llx_ittabpdf_mailqueue`, der geplante Job „IT-Tabelander PDF: automatic e-mails“ (alle 5 Minuten),
   - Menüeintrag **Rechnungen → Automatischer Versand**,
   - mit eingeschaltetem Wissensmanagement das Zusatzfeld „Dateiname der Unterlage“ (`ittab_slug`) an Artikeln. Wird das Wissensmanagement erst später eingeschaltet: dieses Modul danach einmal aus- und wieder einschalten.

## Aktivierung als Standardvorlage

In der Einstellungsseite jeder Belegart unter **Dokumentvorlagen** bei `werkbank` das Häkchen „Standard“ setzen:

| Belegart | Weg |
| --- | --- |
| Angebote | Einstellungen → Module → Angebote (Zahnrad) → Dokumentvorlagen |
| Rechnungen | Einstellungen → Module → Rechnungen (Zahnrad) → Dokumentvorlagen |
| Aufträge, Lieferungen, Serviceaufträge, Verträge, Preisanfragen, Lieferantenbestellungen, Wareneingänge | jeweils die Einstellungsseite des Moduls |

Bereits erzeugte PDFs bleiben, wie sie sind; neu erzeugen über „Erzeugen“ auf der Belegkarte (dort kann auch jede andere Vorlage gewählt werden).

Damit die Rechnung Kontodaten und QR-Code zeigt, muss bei den Rechnungseinstellungen das Bankkonto für Überweisungen gewählt sein (`FACTURE_RIB_NUMBER`), mit IBAN, BIC und Kontoinhaber. Kopf und Fuß nehmen Name, Manager, Mobilnummer, E-Mail, Web, Adresse und die Berufs-IDs aus **Einstellungen → Firma/Organisation** (Berufs-ID 2 = Gerichtsstand, 4 und 6 = z. B. GISA-Zahl und GLN, so wie sie dort stehen).

## Moduleinstellungen

**Einstellungen → Module → IT-Tabelander PDF (Zahnrad)**:

- Kundenbereich der Website aktiv (Standard: aus). Steuert den Annehmen-Text im Angebots-PDF, den Satz in den Angebotsmails und den Button „Im Kundenbereich ansehen“ in allen Kunden-Mails.
- Text „Annehmen“ auf Angeboten mit Kundenbereich (Standard: „Online im Kundenbereich auf it.tabelander.co.at oder per E-Mail. Gearbeitet wird erst nach Zusage.“) und ohne (Standard: „Bitte kurz per E-Mail an __MYCOMPANY_EMAIL__ bestätigen. Gearbeitet wird erst nach Zusage.“, die Variable wird durch die Firmen-E-Mail ersetzt)
- Kleinunternehmer-Hinweis unter dem Gesamtbetrag (Standard: „Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG“)
- **Modul-Vorlagen auf aktuellen Stand bringen** (mit Rückfrage): ersetzt Betreff und Inhalt aller Vorlagen dieses Moduls; eigene Vorlagen und die anderer Module bleiben unberührt. Beim Aktivieren werden Modul-Vorlagen, die noch den Text einer früheren Version haben, automatisch erneuert.
- Automatischer Versand je Anlass ein/aus (Standard: aus), Verzögerung in Minuten (Standard 5), Blindkopie an (Standard: E-Mail der Firma), Adresse des Kundenbereichs, Vorlage je Anlass für Sie und Du (Standard: die Vorlagen des Moduls).

## Unterlagen

Unternehmensunterlagen und Rechtstexte sind ein eigener Bereich des Moduls (Menü **Geschäftspartner → Unterlagen**), getrennt von den FAQ im Wissensmanagement.

- **Liste und Karte:** Titel, Dateiname (der Name der PDF; die Website erwartet `nutzungsbedingungen`, `widerruf`, `datenschutz`, `agb-unternehmer`, `impressum-ergaenzung`), Art (**Rechtstext** – auf Wunsch öffentlich –, **Unterlage (intern)**, **Vorlage je Kunde**), Text im Editor. Speichern ändert nur den Entwurf.
- **Freigeben** macht aus dem Entwurf eine neue **Fassung** (Stand, wer, „Was hat sich geändert?“). Eine Fassung ändert sich nie mehr: Text und PDF jeder Fassung bleiben abrufbar (Karte → Fassungen; PDF unter `IT-Tabelander/Unterlagen/Archiv/<name>-v<n>.pdf`). So lässt sich zeigen, welche AGB-Fassung ein Kunde bekommen hat. Die aktuelle PDF liegt unter `IT-Tabelander/Unterlagen/<name>.pdf`. Vorlagen je Kunde bekommen nur Fassungen, keine allgemeine PDF.
- **PDF-Vorschau** des Entwurfs (mit „ENTWURF“ quer).
- **Aussehen der PDF:** Kopf mit Logo und Kontakt, Titel mit orangem Punkt, „Stand TT.MM.JJJJ“, Text in den Markenschriften (Überschriften, Absätze, Listen, Fettes, Links, einfache Tabellen; Bilder, Skripte und eigene Formatierungen fallen weg), Fuß mit Firmendaten und „Seite x / y“.
- **Übernahme aus dem Wissensmanagement:** Beim Aktivieren (solange es noch keine Unterlagen gibt), per Knopf in den Moduleinstellungen oder auf der Artikelkarte werden die Artikel der Kategorien „Rechtstexte“ und „Unterlagen“ übernommen: freigegebene als Fassung 1 mit ihrem Datum, Entwürfe als Entwurf, Rechtstexte öffentlich. Die Artikel bleiben stehen (löschen entscheidest du), ihre Karte zeigt, wo die Unterlage jetzt ist. PDFs, die das Modul früher unter einem anderen Namen geschrieben hat (z. B. `datenschutzerklaerung.pdf` neben `datenschutz.pdf`), werden entfernt; hochgeladene Dateien nie.
- **Website:** holt die öffentlichen, freigegebenen Rechtstexte über die REST-API des Moduls: `GET /ittabelanderpdf/unterlagen` (Liste mit Stand und Fassung), `GET /ittabelanderpdf/unterlagen/<name>` (mit HTML), `GET /ittabelanderpdf/unterlagen/<name>/pdf` (PDF als Base64). Dafür reicht ein API-Benutzer mit Zugang; interne Unterlagen und Entwürfe gibt die API nicht heraus.
- **Texte aus `docs/unterlagen/`:** `python scripts/import_unterlagen.py` (im Repository) legt sie als Entwürfe im Modul an oder aktualisiert den Entwurf derselben Datei; freigegeben wird nur in Dolibarr. API-Benutzer mit dem Recht „Unterlagen bearbeiten und freigeben“.
- **Mitschicken:** Im Mailformular von Angebot, Auftrag, Rechnung, Vertrag, Serviceauftrag, Lieferung und Geschäftspartner steht über den Anhängen **„Unterlagen beilegen“**; bei Tickets als Kasten über dem Nachrichtenformular. **Standard-Anhänge** je Belegart, getrennt für Firmen und Privatpersonen (Moduleinstellungen).
- **An das Beleg-PDF anhängen:** Je Angebot, Auftragsbestätigung und Rechnung lässt sich eine Unterlage hinter das Werkbank-PDF hängen; ohne Auswahl gilt Dolibarrs **Einstellungen → PDF → „AGB/Verkaufsbedingungen anhängen“**.
- **Rechte:** „Unterlagen lesen“ (beim Aktivieren für alle an), „Unterlagen bearbeiten und freigeben“.

## Preisliste aus den Produkten

- **Platzhalter `__ITTAB_PREISLISTE__`** in einer Unterlage (mitgeliefert: `docs/unterlagen/preisliste.html`) setzt die Preisliste aus Dolibarr ein: je gewählter **Produkt-Kategorie** ein Abschnitt (Reihenfolge des Kategorienbaums), darin „Bezeichnung: Preis“ mit Einheit („pro Stunde“, „pro Monat“, „pro km“ aus Einheit oder Dauer der Leistung). Nur Produkte/Leistungen **im Verkauf** und mit Preis **über 0 €** (Vorlagezeilen wie „Festpreis nach Angebot“ fallen weg).
- **Einstellungen** → IT-Tabelander PDF → Allgemein → „Preisliste aus den Produkten“: Kategorien wählen (ein-/ausblenden je Kategorie), Abschnitt „Weitere Leistungen“ für Produkte ohne gewählte Kategorie, Beschreibung zeigen, automatisch neu erzeugen (Vorgabe an). Knopf „Preis-Unterlagen jetzt neu erzeugen“.
- **Automatisch:** Ändert sich ein Produkt (Preis, Bezeichnung, im Verkauf, Kategorie), schreibt das Modul jede freigegebene Unterlage mit `__ITTAB_PREISLISTE__` oder `__ITTAB_PREIS:<Referenz>__` neu, **Stand heute**. Die Archiv-Kopie der freigegebenen Version bleibt, wie sie war.
- Platzhalter der Firma und `__ITTAB_DATUM__` gelten jetzt auch in allgemeinen Unterlagen.

## Unterlagen je Kunde: was unterschrieben ist und was fehlt

- **Reiter „Unterlagen“ beim Geschäftspartner:** jede Unterlage, die dieser Kunde braucht (Pflicht) oder bekommen kann (optional), mit Zustand: **Fehlt**, **Verschickt** (am …, Bestätigung fehlt), **Unterschrift läuft**, **Erledigt** (unterschrieben / hochgeladen / abgehakt am …, wie, von wem, Stand der Fassung, Datei), **Neue Fassung** (bestätigt war eine ältere). Oben die Zusammenfassung („2 Pflicht-Unterlagen offen“), unten der Verlauf. Am Handy bedienbar.
- **Je Zeile:** „Am Gerät unterschreiben“ bzw. „Empfang bestätigen lassen“ (Bestätigungsblatt mit Unterschrift, die Unterlage dahinter, liegt danach beim Kunden), „Per E-Mail senden“ (Mailformular des Kunden mit der Unterlage im Anhang), „Scan hochladen“ (PDF oder Foto, auch direkt mit der Handykamera), „Von Hand abhaken“ (Datum, Notiz), „Zurücksetzen“ (mit Grund; gilt wieder als offen, der Verlauf bleibt).
- **Automatisch:** Geht eine Unterlage per Mail hinaus („Unterlagen beilegen“ oder Standard-Anhang), steht sie auf „Verschickt“. Wird das Angebot, mit dem sie ging oder an dessen PDF sie hängt, angenommen – auch im Kundenbereich der Website –, ist sie erledigt („im Kundenbereich angenommen“). Unterschrift am Gerät und Upload haken sie ebenfalls ab.
- **Regeln** (Einstellungen → IT-Tabelander PDF → Reiter **Unterlagen je Kunde**): je Regel Bezeichnung, Unterlage (Dateiname), was der Kunde tut (akzeptieren, unterschreiben – dann unterschreiben beide –, Empfang bestätigen), Bedingungen, die alle gelten müssen und einzeln leer bleiben dürfen: **Kunde** (egal / Firmen / Privatpersonen) UND **Vertrag mit** Produkt-Referenz (z. B. `DL-WART*`) UND **Kategorie** des Kunden; Pflicht/optional, „Neue Fassung neu bestätigen“ (ändert sich der Artikel, gilt eine ältere Bestätigung nicht mehr). Mitgeliefert: AGB Unternehmer (Firmen), Nutzungsbedingungen (Privat), AVV und Leistungsbeschreibung IT-Betreuung und Wartung (Vertrag mit `DL-WART*`; ab 1.8.2, vorher Hosting/Wartung – beim Aktivieren ziehen Regeln und Standard-Anhänge auf die neuen Unterlagen um, sobald es sie gibt), Einverständnis Fernwartung (Kunden-Kategorie „Fernwartung“; ohne diese Kategorie ist die Regel aus, bis sie in den Einstellungen gewählt ist).
- **Damit nichts vergessen wird:** Hinweis „2 Unterlagen fehlen: …“ mit Knopf auf Kunden-, Angebots-, Vertrags- und Serviceauftragskarte (abschaltbar); Startseiten-Widget **„Fehlende Unterlagen“** (Kunden mit offenen Pflicht-Unterlagen); beim Aktivieren einer Vertragsleistung, deren Produkt eine fehlende Pflicht-Unterlage verlangt, eine Warnung oder – einstellbar – eine Sperre.

## Unterlagen für einen Kunden erzeugen (Platzhalter und Eingabefelder)

- **Vorlage:** Unterlage der Art **„Vorlage je Kunde“** (z. B. AVV, Einverständnis Fernwartung). Ihr Text enthält Platzhalter und Eingabefelder; ein allgemeines PDF gibt es dafür nicht.
- **Knopf „Erzeugen“** im Reiter „Unterlagen“ des Kunden, bei jeder Regel mit so einer Vorlage: Vertrag und Kontakt wählen, die Eingabefelder ausfüllen (vorbelegt mit dem, was beim letzten Mal für diesen Kunden eingegeben wurde). Fehlt ein Pflichtfeld oder bleibt ein Platzhalter übrig (Tippfehler, unbekannte Produkt-Referenz), erzeugt das Modul nichts und sagt, was fehlt.
- **Ergebnis:** PDF `<slug>-<datum>-<nr>.pdf` beim Kunden und – mit Vertrag – auch beim Vertrag, im Werkbank-Design mit Stand der Vorlage, dem Kunden in der Kopfzeile und Unterschriftszeilen für beide Seiten. Zustand **„Erzeugt“**; danach wie jede Unterlage per E-Mail senden (Verschickt) oder am Gerät unterschreiben lassen – unterschrieben wird das erzeugte Dokument selbst (Erledigt). Wird später neu erzeugt (anderer Vertrag, andere Werte), steht die Unterlage wieder auf „Erzeugt“ und muss neu verschickt oder unterschrieben werden; der Verlauf zeigt die ältere Fassung weiter.
- **Platzhalter:** eigene `__ITTAB_…__` (Kunde, Firma, Vertrag, Leistungen, Geschäftszeiten, Datum, Preise mit `__ITTAB_PREIS:<Produkt-Referenz>__`) und die üblichen Dolibarr-Platzhalter (`__THIRDPARTY_NAME__` …). Ist ein Wert leer – etwa keine UID –, fällt die ganze Zeile weg. Die vollständige Liste zum Kopieren steht unter Einstellungen → IT-Tabelander PDF → **Unterlagen je Kunde** und aufklappbar auf der Karte jeder Unterlage. Dort auch die **Geschäftszeiten** für `__ITTAB_GESCHAEFTSZEITEN__` (leer: Firma → Öffnungszeiten).
- **Eingabefelder:** `[[FELD:name*|Bezeichnung|Typ|Vorgaben]]`, `*` = Pflicht. Typen: `Text`, `Textfeld`, `Auswahl` (mehrere; Vorgaben mit `;` getrennt, `+` = vorausgewählt; weitere lassen sich dazuschreiben), `Einfach` (eine), `Datum`, `Zahl`. Ein leeres optionales Feld nimmt seine Zeile mit.
- **Dieselben Platzhalter** gelten in den Hinweisen des Geräteannahmescheins und des Prüfprotokolls.
- **Import:** In `docs/unterlagen/` macht die Kopfzeile `Art: Vorlage je Kunde` eine Datei zur Vorlage je Kunde.

## Unterlagen senden und von Hand hinzufügen

- **Weitere Unterlage** (Reiter „Unterlagen“ unten): jede freigegebene Unterlage für diesen Kunden hinzufügen, auch ohne Regel – mit „was der Kunde tut“ und Pflicht ja/nein. Sie erscheint als eigene Zeile („von Hand hinzugefügt“) mit Status, Erzeugen, Senden, Unterschreiben, Hochladen, Abhaken und **Entfernen** (Verlauf bleibt). Dasselbe über **„Unterlage erzeugen“** auf Vertrag und Angebot (das PDF liegt dann auch dort) und **„Für Kunden erzeugen …“** auf der Karte einer Vorlage je Kunde.
- **Unterlagen beilegen** (in jedem Mailformular): drei Gruppen – *Für diesen Kunden erzeugen* (Vorlagen je Kunde; das Modul füllt sie mit den letzten Angaben aus, fehlt etwas, kommt erst das Formular und danach geht es mit der fertigen Unterlage zurück ins Mailformular), *Für diesen Kunden schon erzeugt*, *Allgemeine Unterlagen*. Eine Vorlage je Kunde geht nie blanko hinaus; ihre Vorschau heißt „Muster“.
- **An Kunden versendbar** (Feld an der Unterlage): nur solche werden angeboten, auch als Standard-Anhang und zum Anhängen ans Angebot. Aus für Textbausteine (Geräteannahme, QS-Checklisten, Impressum-Ergänzung).
- **Mailtext:** Beim Geschäftspartner wählt das Formular „Unterlagen zusenden · Sie/Du“, sobald Unterlagen beiliegen, sonst „Nachricht · Sie/Du“ (nach „Du statt Sie“ am Kunden). Betreff und Liste (Titel, Stand, was zu tun ist) folgen den beigelegten Unterlagen, solange du den Text nicht selbst geändert hast; danach bietet das Formular an, ihn neu aufzubauen. Variablen: `__ITTAB_UNTERLAGEN_BETREFF__`, `…_EINLEITUNG__`, `…_LISTE__`, `…_SCHLUSS__`, jeweils auch mit `_DU`.
- Die Kopie nach dem Unterschreiben geht mit „Unterschriebene Unterlage · Sie/Du“.
- **Empfänger:** Vorgewählt ist genau einer – der Kontakt des Belegs (Angebot/Vertrag), sonst ein Kontakt mit Rolle beim Geschäftspartner, sonst dessen Adresse. Die Anrede passt sich dem gewählten Empfänger an („Guten Tag Frau Muster,“); ein Wechsel baut den Text neu auf, solange du ihn nicht geändert hast.
- **Reiter „Unterlagen“:** je Zeile eine Hauptaktion, der Rest unter „Weitere Aktionen“.

## Werkstatt: QS-Checklisten und Prüfprotokoll

![QS am Handy](docs/beispiele/qs-handy.png)

- **Wo:** Menü **Serviceaufträge → QS / Prüfprotokoll**, Reiter **QS** am Serviceauftrag und am Geschäftspartner. Die Seite ist fürs Handy und das Werkstatt-Tablet gebaut (große Häkchen, 16-px-Felder, eine Spalte); am Rechner sieht sie gleich aus.
- **Ablauf vor Ort oder in der Werkstatt:** Kunde wählen → offenen Serviceauftrag wählen oder neuen anlegen → Auftragsart antippen → Punkte abhaken. Jede Änderung wird sofort gespeichert (Häkchen, Messwert mit Einheit, Text, Notiz, „entfällt“), mit Name und Uhrzeit. Oben steht, wie viele Punkte erledigt und wie viele Pflichtpunkte offen sind.
- **QS abschließen:** geht erst, wenn alle Pflichtpunkte erledigt sind oder „entfallen“; sonst sagt die Seite, welche fehlen, und markiert sie. Danach liegt das **Prüfprotokoll** als Werkbank-PDF beim Serviceauftrag (`<REF>-pruefprotokoll-<n>.pdf`): Kunde, Auftragsart, jeder Punkt mit Häkchen, Wert, Notiz, wer und wann, Bemerkung, Unterschriftszeilen. Wieder öffnen darf, wer QS-Checklisten verwalten darf.
- **Serviceauftrag schließen:** schließt fertige QS mit (Prüfprotokoll entsteht) und wird verweigert, solange eine QS offene Pflichtpunkte hat (abschaltbar). Auf Wunsch hängen die Prüfprotokolle hinter dem Arbeitsbericht-PDF.
- **Checklisten pflegen:** Einstellungen → IT-Tabelander PDF → Reiter **Werkstatt und QS** (oder Menü **QS-Checklisten**): Auftragsarten anlegen, umbenennen, sortieren, abschalten, je Auftragsart die Unterschriften (Techniker, Kunde) und einen Hinweis fürs Protokoll; Punkte mit Art (Häkchen, Messwert, Text), Einheit, Pflicht, Hilfetext, Reihenfolge, aktiv. Gelöscht wird nichts; eine laufende oder fertige QS behält ihre eigene Kopie der Punkte. Mitgeliefert: Reparatur PC/Notebook, Aufrüstung, PC-Bau, Controller-Umbau, Windows-Neuinstallation und Datenübernahme, Webseite-Übergabe, Einsatz vor Ort.
- **Rechte:** „QS-Checklisten abhaken und mit Prüfprotokoll abschließen“ und „QS-Checklisten verwalten“ (Benutzer → Berechtigungen → IT-Tabelander PDF); dazu Leserecht auf Serviceaufträge, zum Anlegen Schreibrecht.

## Unterschreiben am Handy und an den Kunden senden

- **Wann:** Nach „QS abschließen“ geht die Seite direkt weiter zum Unterschreiben (oder später über „Unterschreiben und senden“ in der fertigen QS). Für den Geräteannahmeschein: Knopf **„Am Gerät unterschreiben“** auf der Ticketkarte.
- **Wer:** Techniker und/oder Kunde, so wie es die Auftragsart vorgibt (Einstellung „Unterschriften“ je Auftragsart); beim Annahmeschein beide. Mit Finger oder Stift aufs Feld, Name in Druckschrift darunter (beim Techniker vorausgefüllt), **Unterschrift speichern**. „Neu unterschreiben“ ersetzt eine Unterschrift; wird eine QS wieder geöffnet, fallen ihre Unterschriften weg.
- **Im PDF:** Die Unterschrift steht auf der Unterschriftslinie, darunter Name und „elektronisch unterschrieben am TT.MM.JJJJ HH:MM“. Das ist eine einfache elektronische Unterschrift; als Nachweis zählen Bild, Name, Zeitpunkt und angemeldeter Benutzer (Tabelle `llx_ittabpdf_signature`, auch die IP-Adresse). Das Bild liegt in den Dokumenten des Objekts im Unterordner `signatures/`.
- **Ablage:** Prüfprotokoll beim Serviceauftrag, Annahmeschein beim Ticket, beide zusätzlich in den Dokumenten des Geschäftspartners.
- **Senden:** Sobald alle Unterschriften da sind, zeigt die Seite den Empfänger zum Prüfen (Kontakt des Auftrags, sonst Geschäftspartner, bei Anfragen ohne Geschäftspartner die Adresse der Anfrage; einstellbar unter „Werkstatt und QS“) – **Jetzt senden** schickt das PDF mit der Vorlage „Prüfprotokoll“ bzw. „Geräteannahme“ (Sie/Du nach „Anrede per Du“; andere Vorlage wählbar in den Moduleinstellungen), Blindkopie wie beim automatischen Versand, Eintrag in der Ereignisliste des Serviceauftrags bzw. Tickets. Die QS merkt sich, wann und an wen gesendet wurde.
- **Technik:** Das Bild geht als Base64 ohne „data:“ und ohne „=“ an `ajax/sign.php`; Dolibarrs WAF blockt `=data:` und Muster wie `on…=` in jedem Formular. Der Server nimmt nur echte PNGs bis 600 KB.

## Werkstatt: Geräteannahmeschein

- **Knopf „Geräteannahmeschein“** auf der Ticketkarte (Anfragen kommen als Ticket von der Website). Das PDF liegt danach in den Dokumenten des Tickets: eine Seite zum Ausdrucken und Unterschreiben.
- **Inhalt:** Anfrage-Nr., Datum, Kundennummer; Kunde (Name, Adresse, Telefon, E-Mail – ohne Geschäftspartner leere Zeilen zum Ausfüllen); Gerät (Art, Marke/Modell, Seriennummer, Zubehör, Zustand/Schäden, Fehlerbeschreibung, sonst der Betreff des Tickets); „Zugangsdaten nötig“ und „Daten gesichert“ als Kästchen; auf Wunsch eine leere Zeile für Passwort/PIN **nur auf Papier** (nie gespeichert; Schalter „Feld für Passwort/PIN auf dem Schein“, Vorgabe aus); Hinweise; Unterschriften IT-Tabelander und Kunde.
- **Gerätedaten** sind Zusatzfelder am Ticket („Gerät: Art“, „Gerät: Marke und Modell“, …, „Zugangsdaten nötig“, „Daten vom Kunden gesichert“). Die Auswahl-Werte (Geräte-Arten, ja/nein/keine wichtigen Daten) lassen sich unter Einstellungen → Tickets → Zusatzfelder ändern; der Schein zeigt, was dort steht.
- **Hinweise** in den Moduleinstellungen (Reiter „Werkstatt und QS“), je eine Fassung per Sie (Firmen) und per Du (Privatkunden und Anfragen ohne Geschäftspartner), ein Hinweis pro Zeile. Vorgabe nach den Textvorschlägen vom 26.11.2026: Datensicherung, Zugangsdaten, Fehlersuche 29 € (bei Auftrag angerechnet) und unverbindlicher Kostenvoranschlag, Abholfrist 4 Wochen, danach 2 € pro Tag, Herstellergarantie, Datenschutz, Nutzungsbedingungen bzw. AGB für Unternehmer.

## Automatischer Versand im Betrieb

- Beim Freigeben bzw. bei vollständiger Bezahlung legt das Modul einen Eintrag an; auf der Karte steht dann „Automatischer Versand geplant um HH:MM“ mit **Abbrechen**.
- Der geplante Job schickt fällige Einträge. Vorher prüft er, ob der Beleg noch passt (Angebot noch offen, Rechnung nicht wieder Entwurf, Rechnung noch bezahlt); sonst wird der Eintrag abgebrochen.
- Empfänger: Rechnungskontakt (Rechnungen) bzw. Kundenkontakt (Angebote), sonst die E-Mail-Adresse des Kunden. Fehlt beides, steht der Eintrag auf „Fehler“ und die Karte zeigt das mit **Erneut senden**.
- Anrede: „Anrede per Du“ beim Kunden → Du-Vorlage, sonst Sie.
- Das PDF wird vor dem Versand mit `werkbank` neu erzeugt. Nach dem Versand steht in der Ereignisliste des Belegs derselbe Eintrag wie bei einem händischen Versand (`PROPAL_SENTBYMAIL` bzw. `BILL_SENTBYMAIL`, mit Empfänger, Betreff, Anhang).
- Ein Beleg wird je Anlass nur einmal automatisch verschickt, auch wenn er zurück auf Entwurf und erneut freigegeben wird. Nur abgebrochene oder fehlgeschlagene Einträge werden bei einer neuen Freigabe wieder geplant.
- Übersicht: **Rechnungen → Automatischer Versand** (Geplant/Gesendet/Fehler/Abgebrochen, Abbrechen, Erneut senden).
- Voraussetzung: Dolibarrs Cron läuft auf dem Server (z. B. alle 5 Minuten `scripts/cron/cron_run_jobs.php`) und der Job ist unter **Einstellungen → Geplante Jobs** aktiv.

## E-Mail-Variablen des Moduls

Zusätzlich zu Dolibarrs eigenen Variablen (`__REF__`, `__MYCOMPANY_NAME__`, …):

| Variable | Inhalt |
| --- | --- |
| `__ITTAB_HALLO__` | „Hallo Maria,“ (Vorname des Kontakts; bei Privatkunden ohne Kontakt das erste Wort des Namens) |
| `__ITTAB_GUTENTAG__` | „Guten Tag Frau Muster,“ / „Guten Tag,“ |
| `__ITTAB_FAKTEN__` | Kasten mit Belegnummer, Betrag, Fällig am / Gültig bis usw. |
| `__ITTAB_UEBERWEISUNG__` | Kontoinhaber, IBAN, BIC, Verwendungszweck (nur bei offener Rechnung) |
| `__ITTAB_SENDUNG__` | Sendungsnummer mit Link (nur Lieferungen mit Sendungsnummer) |
| `__ITTAB_KUNDENBEREICH_URL__` | Link auf den Beleg im Kundenbereich der Website (Angebot, Rechnung/Gutschrift, Auftrag, Lieferschein, Arbeitsbericht, Vertrag; sonst die Startseite) |
| `__ITTAB_KUNDENBEREICH_BLOCK__` | Button „Im Kundenbereich ansehen“, leer solange „Kundenbereich der Website aktiv“ aus ist |
| `__ITTAB_FRAGEN_SATZ__`, `__ITTAB_FRAGEN_SATZ_DU__` | Wie man fragt (Sie/Du): „einfach auf diese E-Mail antworten“, mit Kundenbereich zusätzlich „oder im Kundenbereich schreiben“. Kein Telefon-Satz; die Nummer steht nur in der Fußzeile |
| `__ITTAB_ANNAHME_SATZ__`, `__ITTAB_ANNAHME_SATZ_DU__` | Satz zur Annahme des Angebots (Sie/Du): mit Kundenbereich „online im Kundenbereich annehmen oder antworten“, ohne „einfach auf diese E-Mail antworten“ |
| `__ITTAB_LOGO__` | Firmenlogo als verlinktes Bild auf Weiß: `public/maillogo.php` des Moduls liefert das Logo der Firmeneinstellungen als undurchsichtiges PNG auf weißem Grund (ohne Anmeldung, zwischengespeichert), oder die Adresse aus `ITTABPDF_MAIL_LOGO_URL`. Ohne Logo: Wortmarke als Text |
| `__ITTAB_TELEFON_LINK__` | Telefonnummer als eigener `tel:`-Link in neutraler Farbe |

**Aufbau (ab 1.9.0).** Kein Logo und keine Linie oben: Die Mail beginnt mit der Anrede und endet mit dem Abschluss im Stil von Fabians Signatur – Gruß („Mit freundlichen Grüßen“ / „Liebe Grüße“), Name und Firma, eine Linie (`<hr />`), das Logo (180 px, `__ITTAB_LOGO__`), Adresse, E-Mail, Web und Telefon. Nur Absätze, ein `div`, `<hr />` und Inline-Stile: Das übersteht CKEditor beim Bearbeiten und die WAF (geprüft: Vorlage im CKEditor öffnen und speichern ändert nichts). Den Rahmen (560 px, Hintergrund) bekommt nur der automatische Versand.

**Hell und dunkel.** Die Vorlagen enthalten nur Blöcke mit Inline-Stilen, kein `<style>` und keine Tabellen: Dolibarrs WAF lehnt `<style` in jedem Formular ab (Speichern der Vorlage und händischer Versand), und der Bereinigungsfilter `restricthtml` entfernt Klassen. Diese helle Fassung gilt für jeden Versandweg und bleibt auch dann sauber, wenn ein Mail-Programm sie selbst abdunkelt (das Logo ist ein undurchsichtiges weißes Schild). Nur der automatische Versand legt um den Text ein ganzes Dokument mit `color-scheme`, `@media (prefers-color-scheme: dark)` und den Outlook-Selektoren `[data-ogsc]`/`[data-ogsb]`: Hintergrund #0D1823, Kasten #14263A, Texte #EEF2F6/#C5D0DC/#A9B8CA, Linie #2C4763, dazu das helle Logo für dunkle Flächen (`ITTABPDF_MAIL_LOGO_DARK`, Pfad im DMS; `public/maillogo.php?variant=dark`). Die dunklen Regeln finden die Elemente über ihre Inline-Farben, deshalb wirken sie auch auf Text, der später direkt in der Vorlage geändert wird.
| `__ITTAB_INHABER__`, `__ITTAB_TELEFON__`, `__ITTAB_ADRESSE__`, `__ITTAB_WEB__`, `__ITTAB_RECHTLICHES__` | Firmendaten für Gruß und Fuß |

Das Logo wird verlinkt, nicht eingebettet. Eingebettete Bilder (`data:` → `cid:`) zeigt Dolibarrs SMTPS-Versand auf Handy-Mailprogrammen als kaputtes Bild plus zusätzlichen Anhang (24.11.2026 in Produktion gesehen). Voraussetzung: Dolibarr ist von außen erreichbar. Wird es gesperrt (Website-Issue #67), `ITTABPDF_MAIL_LOGO_URL` (Einstellungen → Erweiterte Einstellungen) auf eine öffentliche Adresse setzen, z. B. ein PNG auf it.tabelander.co.at. Die Mails sind immer hell wie das PDF (`color-scheme: light only`, weiße Flächen und Textfarben fest im HTML); Programme mit erzwungenem Dunkelmodus können Flächen trotzdem umfärben, das Logo bleibt aber ein Bild auf Weiß und damit lesbar.

Die Vorlagen nutzen bewusst keinen Online-Zahlungslink (`__ONLINE_PAYMENT_URL__`); der lässt sich bei Bedarf in der Vorlage ergänzen.

## Elektronische Signatur

`pdf_cyan` legt bei `MAIN_PDF_PROPAL_USE_ELECTRONIC_SIGNING` ein leeres Signaturfeld an und schreibt die Seite mit dem Unterschriftsbereich als `PAGESIGN=n` in die Schlüsselwörter. `werkbank` macht beides, an der Unterschriftszeile „Datum, Unterschrift Auftraggeber“. Dolibarrs öffentliche Online-Signaturseite setzt das Unterschriftsbild dagegen immer an eine feste Stelle (x 120 mm, 60 mm über dem unteren Rand, änderbar über `PROPAL_SIGNATURE_XFORIMGSTART`/`YFORIMGSTART`). Weil der Unterschriftsbereich bei `werkbank` mit dem Inhalt mitwandert, trifft dieses Bild die Linie nicht genau. Angenommen wird bei IT-Tabelander über den Kundenbereich der Website; die Online-Signaturseite von Dolibarr ist dafür nicht nötig.

## Grenzen

- Eine einzelne Tabellenzeile, die länger als eine ganze Seite ist, wird nicht geteilt.
- Titel- und Zwischensummenzeilen des Moduls „Subtotal“ erscheinen als einfache Textzeilen ohne Beträge.
- Nicht übernommen aus `cyan`/`sponge`: Produktfotos, Incoterms, zweite Belegsprache (`PDF_USE_ALSO_LANGUAGE_CODE`), Zusatzfelder der Zeilen, Fremdwährungsbeträge (alle Beträge in der Firmenwährung).
- Der Fußtext darf allein auf eine neue Seite rutschen, wenn er nicht mehr passt (der Summenblock nicht).
- Die E-Mail-Vorlagen sind deutsch (gelten für alle Sprachen).

## Entwicklung

```bash
python scripts/dev_dolibarr.py all     # Dolibarr 23.0.3 + MariaDB + Mailpit, Modul aus dem ZIP, Testdaten, PDFs, Mails, Oberfläche, Tickets, Unterlagen
python scripts/dev_dolibarr.py down    # Container wieder weg
python scripts/build_release.py        # dist/module_ittabelanderpdf-x.y.z.zip + .sha256
python scripts/build_fonts.py          # fonts/ neu aus den WOFF-Dateien (nur bei Schriftwechsel)
```

Beispiel-PDFs liegen in `docs/beispiele/`, die Mails als HTML in `docs/beispiele/mails/` (`uebersicht.json`: Empfänger, BCC, Anhänge, Prüfergebnisse).

## Lizenz

Code: GPL-3.0-or-later (`LICENSE`). Schriften Sora, Source Sans 3 und Michroma: SIL Open Font License 1.1 (`fonts/LICENSE-*.txt`).

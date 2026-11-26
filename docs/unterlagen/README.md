# Unternehmensunterlagen

Texte für Verträge, Formulare und Übersichten von IT-Tabelander (#127). Jede
HTML-Datei ist ein Dokument und nutzt nur, was der Editor im
Dolibarr-Wissensmanagement behält: `p`, `h2`, `h3`, `ul`/`ol`/`li`, `strong`,
`em`, `a`, `br`. Keine Tabellen, keine Stile. Die Gestaltung als PDF
(Werkbank-Design) macht das Dolibarr-Modul.

> **Alle Texte sind Entwürfe – vor Verwendung vom WKO-Rechtsservice prüfen
> lassen.** Dieser Hinweis steht nur hier und im Kopfkommentar jeder Datei,
> nicht im Text für Kundinnen und Kunden.

Ausgangspunkt waren die Muster der WKO (Fachverband UBIT: AGB für
Organisations- und Programmierleistungen B2B und für IT-Betreiberdienstleistungen
B2B, Ausgabe 2024; Muster-Vereinbarung zur Auftragsverarbeitung) und die
Gesetze (ABGB, UGB, KSchG, FAGG, ECG, DSGVO/DSG, UrhG, VGG). Die Texte sind
eigene Fassungen in einfacher Sprache, keine Abschriften.

## Übersicht

| Datei | Titel in Dolibarr | Für wen | Anrede | Kategorie | Öffentlich | PDF-Slug | WKO-Prüfung |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `agb-unternehmer.html` | AGB für Unternehmer | Firmenkunden | Sie | Rechtstexte | ja (Website, PDF) | `agb-unternehmer` | offen |
| `widerruf.html` | Widerrufsbelehrung und Muster-Widerrufsformular | Privatkunden (aus den Nutzungsbedingungen) | Du | Rechtstexte | ja (Website, PDF) | `widerruf` | offen |
| `leistungsbeschreibung-hosting-wartung.html` | Leistungsbeschreibung Hosting und Wartung | Kunden mit Hosting/Wartung, Teil des Vertrags | Sie | Unterlagen | auf Anfrage, mit dem Angebot | `leistungsbeschreibung-hosting-wartung` | offen |
| `auftragsverarbeitung.html` | Vereinbarung zur Auftragsverarbeitung (Art. 28 DSGVO) | Firmenkunden mit Hosting, Wartung, Fernwartung | Sie | Unterlagen | auf Anfrage, zum Unterschreiben | `auftragsverarbeitung` | offen |
| `fernwartung-einverstaendnis.html` | Einverständniserklärung Fernwartung | alle Fernwartungskunden | neutral (Ich-Form des Kunden) | Unterlagen | auf Anfrage | `fernwartung-einverstaendnis` | offen |
| `geraeteannahme-bausteine.html` | Geräteannahmeschein – Textbausteine | Privatkunden (ein Baustein für Firmen) | Du | Unterlagen | nein, Text für das Modul | `geraeteannahme-bausteine` | offen |
| `qs-checklisten.html` | Qualitätsprüfung – Checklisten je Auftragsart | intern | – | Unterlagen | nein | `qs-checklisten` | nicht nötig |
| `firmendatenblatt.html` | Firmendatenblatt IT-Tabelander | Firmenkunden (Lieferantenanlage) | neutral | Unterlagen | auf Anfrage | `firmendatenblatt` | nicht nötig |
| `preisliste.html` | Preisliste IT-Tabelander | intern, auf Anfrage | neutral | Unterlagen | **nein** | `preisliste` | nicht nötig |

Nutzungsbedingungen, Datenschutzerklärung und Ergänzung zum Impressum liegen
schon freigegeben in Dolibarr (Artikel 1 bis 3, Quelle der Wahrheit) und als
Ausgangsentwürfe in `backend/app/legal_drafts/`. Sie gehören ebenfalls in die
Kategorie „Rechtstexte“. Änderungsvorschläge dazu stehen in
[`pruefung-bestehende-rechtstexte.md`](pruefung-bestehende-rechtstexte.md).

Die Bankverbindung steht nur als Platzhalter `__BANK__` im Firmendatenblatt.
Kontonummern gehören nicht ins Repo.

## Kategorien in Dolibarr

| Kategorie | Inhalt | Website |
| --- | --- | --- |
| FAQ (früher „Website“) | Fragen und Antworten | als FAQ |
| Rechtstexte | Impressum-Ergänzung, Datenschutz, Nutzungsbedingungen, Widerruf, AGB für Unternehmer | als Rechtsseite mit PDF |
| Unterlagen | alles Interne aus dieser Tabelle | nie; nur als PDF aus Dolibarr, Versand per Mail |

## Import nach Dolibarr

`scripts/import_unterlagen.py` legt jedes Dokument als **Entwurf** im
Wissensmanagement an. Es erkennt seine Artikel am Feld `import_key`
(Schlüssel `u-…` aus dem Kopfkommentar), nicht am Titel.

- Neu → als Entwurf anlegen.
- Schon da und noch Entwurf → Titel und Text aktualisieren (oder „unverändert“).
- Schon freigegeben → bleibt, wie es ist. Änderungen machst du dann in Dolibarr von Hand.
- Es gibt nie etwas frei.
- Fehlende Kategorien „Rechtstexte“ und „Unterlagen“ legt es an, wenn der API-Benutzer darf.
- **Kategorien zuordnen geht nicht per API** (Dolibarr 23.0.3 und 24.0.1 kennen den Typ
  Wissensmanagement beim Verknüpfen nicht). Das Skript listet am Ende jede fehlende
  Zuordnung mit Link zur Artikelkarte; das machst du in Dolibarr von Hand.

```bash
# Probelauf: zeigt, was passieren würde, schreibt nichts
DOLIBARR_URL=https://erp.example.com DOLIBARR_API_KEY=... python scripts/import_unterlagen.py --dry-run
# echter Lauf
DOLIBARR_URL=https://erp.example.com DOLIBARR_API_KEY=... python scripts/import_unterlagen.py
# die offenen [BITTE …]-Stellen, ohne Dolibarr
python scripts/import_unterlagen.py --offene-punkte
```

Nötige Rechte des API-Benutzers: „Wissensmanagement: Artikel lesen“ und
„Artikel anlegen/ändern“, „Kategorien: lesen“; zum Anlegen fehlender
Kategorien zusätzlich „Kategorien: anlegen/ändern“. Danach die Schreibrechte
wieder wegnehmen. Standardbibliothek reicht, kein venv nötig.

Getestet: Unit-Tests in `backend/tests/test_unterlagen.py`, Szenario gegen die
Wegwerf-Dolibarr in `backend/tests/test_dolibarr_unterlagen.py` (Gruppe
`dolibarr` von `scripts/local_check.py`).

## Ablauf bis zur Verwendung

1. Die `[BITTE …]`-Stellen unten entscheiden und in den Dateien ändern.
2. Alles vom WKO-Rechtsservice prüfen lassen (Tirol: Wirtschaftsrecht, Mitgliederservice).
3. Import laufen lassen, Kategorien zuordnen, in Dolibarr freigeben.
4. Die Modul-Sitzung erzeugt die PDFs; die öffentlichen legt sie in Dokumente (ECM)
   unter `IT-Tabelander/Unterlagen/<slug>.pdf` ab.
5. Bei jeder Änderung: Datei hier ändern, „Stand“ anpassen, Import erneut (nur Entwürfe)
   oder in Dolibarr direkt ändern und die Datei hier nachziehen.

## Offene Punkte

`python scripts/import_unterlagen.py --offene-punkte` listet alle Stellen
wörtlich. Zusammengefasst, jeweils mit Empfehlung:

**AGB für Unternehmer**
- Schwelle für Mehrkosten beim Kostenvoranschlag: 15 %.
- Barrierefreiheit (WCAG 2.1 AA): nur auf Vereinbarung zusagen.
- „Rechtstexte“ im Paket Webseite Basis: mit der WKO klären (keine Rechtsberatung).
- Prüffrist für die Abnahme: 14 Tage.
- Nutzungsrecht: nicht ausschließlich als Standard, ausschließlich gegen Aufpreis.
- Quellcode: Webseiten immer, Software nach Vereinbarung.
- Referenz und Verweis im Fußbereich: Standard mit Widerspruchsmöglichkeit.
- Abrechnungstakt: 15 Minuten (auch Leistungsbeschreibung und Preisliste).
- Anzahlung: 50 % ab 500 € Auftragswert bei Webseiten und Software.
- Sperre bei Zahlungsverzug nach Mahnung mit 14 Tagen Nachfrist: aufnehmen.
- Wertsicherung nach VPI 2020: aufnehmen.
- Gewährleistungsfrist: 12 Monate.
- Haftungsgrenze bei grober Fahrlässigkeit für Folgeschäden: aufnehmen; Betriebshaftpflicht prüfen.
- Stornogebühr: keine Pauschale.

**Widerruf**
- Rücksendekosten trägt der Kunde.
- Ausnahme für nach Wunsch gebaute PCs: mit der WKO klären.

**Leistungsbeschreibung Hosting und Wartung**
- Hosting auch für Privatkunden: ja, dann Du-Fassung und Abschnitt in den Nutzungsbedingungen (§ 15 KSchG).
- Technik beschreiben: USV, RAID, Internet; Monitoring-Werkzeug.
- Speicherplatz Webhosting, Benutzerzahl Nextcloud, Geräteanzahl IT-Wartung ergänzen.
- eigene Nextcloud je Kunde oder gemeinsame Instanz klären.
- E-Mail-Postfächer: nicht anbieten oder nur gegen Aufpreis.
- kleine Textänderungen: 15 Minuten im Monat, nicht übertragbar.
- Support-Stunde der IT-Wartung: verfällt am Monatsende.
- Sicherungen: zusätzlich 4 Wochensicherungen; **Kopie außer Haus vor dem ersten Hosting-Vertrag einrichten** (Stand 24.11.2026 fehlt sie).
- Geschäftszeiten ergänzen, Reaktionszeit bei Ausfall wie vorgeschlagen, Wartungsfenster dienstags 21–23 Uhr.
- Verfügbarkeit: Zielwert 99 % ohne Zusage, Gutschrift ab 24 Stunden Ausfall durch eigenen Fehler.

**Auftragsverarbeitung**
- Meldung von Datenpannen an den Kunden: innerhalb von 24 Stunden.
- Kostenregel für Prüfungen beim Kunden: übernehmen.
- Anlage 2 (TOMs) Punkt für Punkt bestätigen: abgeschlossener Raum, zweiter Faktor, Festplattenverschlüsselung, Trennung je Kunde, Server-Verschlüsselung, Kopie außer Haus, USV/RAID, Wiederherstellungsziel, #67 (Dolibarr von außen sperren).
- Fernwartungsprogramm nennen; läuft es über Server des Herstellers, gehört er in Anlage 3.

**Fernwartung**
- Programm nennen.
- dauerhafter Zugang für IT-Wartungskunden: nur auf ausdrücklichen Wunsch (Kästchen).

**Firmendatenblatt**
- Geschäftszeiten, Steuernummer (falls gewünscht), UID-Nummer (falls vorhanden).

**QS-Checklisten**
- Grenzwert Kreisgenauigkeit Controller, Lighthouse-Zielwert.

Zu klären außerhalb der Texte:
- Betriebshaftpflichtversicherung, gerade für Hosting und Fernwartung.
- Gerichtsstand: Telfs hat ein eigenes Bezirksgericht. Die AGB nennen wie gewünscht Innsbruck; für Verbraucher gilt ohnehin ihr Wohnsitz.

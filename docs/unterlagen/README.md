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
| `leistungsbeschreibung-hosting-wartung.html` | Leistungsbeschreibung Hosting und Wartung | Firmenkunden mit Hosting/Wartung, Teil des Vertrags | Sie | Unterlagen | auf Anfrage, mit dem Angebot | `leistungsbeschreibung-hosting-wartung` | offen |
| `leistungsbeschreibung-hosting-wartung-privat.html` | Leistungsbeschreibung Hosting und Wartung für Privatkunden | Privatkunden mit Hosting/Wartung (§ 15 KSchG) | Du | Unterlagen | auf Anfrage, mit dem Angebot | `leistungsbeschreibung-hosting-wartung-privat` | offen |
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

1. Die restlichen `[BITTE PRÜFEN/ERGÄNZEN …]`-Stellen (unten unter „Noch offen“) in den Dateien ausfüllen.
2. Alles vom WKO-Rechtsservice prüfen lassen (Tirol: Wirtschaftsrecht, Mitgliederservice).
3. Import laufen lassen, Kategorien zuordnen, in Dolibarr freigeben.
4. Die Modul-Sitzung erzeugt die PDFs; die öffentlichen legt sie in Dokumente (ECM)
   unter `IT-Tabelander/Unterlagen/<slug>.pdf` ab.
5. Bei jeder Änderung: Datei hier ändern, „Stand“ anpassen, Import erneut (nur Entwürfe)
   oder in Dolibarr direkt ändern und die Datei hier nachziehen.

## Entscheidungen (Fabian, 26.11.2026: Empfehlungen übernommen)

Alle `[BITTE ENTSCHEIDEN …]`-Stellen sind damit aufgelöst und im Text ausformuliert.

**AGB für Unternehmer:** Mehrkosten-Schwelle beim Kostenvoranschlag 15 % ·
Barrierefreiheit (WCAG 2.1 AA) nur auf Vereinbarung · Prüffrist für die
Abnahme 14 Tage · nicht ausschließliches Nutzungsrecht, ausschließliches nur
gegen Aufpreis · Quellcode bei Webseiten immer, bei Software nach
Vereinbarung · Referenz und Verweis im Fußbereich mit Widerspruchsmöglichkeit ·
Abrechnung je angefangene 15 Minuten · Anzahlung 50 % ab 500 € bei Webseiten
und Software · Sperre laufender Leistungen nach Mahnung mit 14 Tagen Nachfrist ·
Wertsicherung nach VPI 2020 · Gewährleistung 12 Monate · Haftungsgrenze für
Folgeschäden bei grober Fahrlässigkeit · keine Stornogebühr.

**Widerruf:** Rücksendekosten trägt der Kunde.

**Hosting und Wartung:** auch für Privatkunden, mit eigener Du-Fassung
(`leistungsbeschreibung-hosting-wartung-privat.html`, Kündigung nach § 15 KSchG) ·
keine E-Mail-Postfächer, Domain auf den Namen des Kunden · 15 Minuten
Textänderungen pro Monat und die Support-Stunde verfallen am Monatsende ·
täglich sichern, 14 Tage plus 4 Wochensicherungen · Reaktion bei Ausfall 4
Stunden in den Geschäftszeiten, spätestens am nächsten Werktag ·
Wartungsfenster dienstags 21–23 Uhr · Zielwert 99 % ohne Zusage, Gutschrift ab
24 Stunden Ausfall durch eigenen Fehler.

**Auftragsverarbeitung:** Datenpanne binnen 24 Stunden an den Kunden ·
Kostenregel für Prüfungen übernommen · Sicherungskopien höchstens 4 Wochen.

**Fernwartung:** dauerhafter Zugang nur, wenn das Kästchen angekreuzt ist.

## Noch offen

`python scripts/import_unterlagen.py --offene-punkte` listet die Stellen
wörtlich. Es sind nur noch Fakten, die Fabian bestätigen oder ergänzen muss,
und Fragen an die WKO:

- **Technik (AVV Anlage 2, Leistungsbeschreibungen):** abgeschlossener Raum,
  zweiter Faktor, Festplattenverschlüsselung (Arbeitsgeräte und Server),
  Trennung je Kunde, USV/RAID, Wiederherstellungsziel, Monitoring-Werkzeug,
  eigene oder gemeinsame Nextcloud, #67 (Dolibarr von außen sperren).
- **Sicherungskopie außer Haus vor dem ersten Hosting-Vertrag einrichten**
  (Stand 24.11.2026 fehlt sie).
- **Fernwartungsprogramm** nennen; läuft es über Server des Herstellers, gehört
  er in Anlage 3 des AVV und in die Datenschutzerklärung.
- **Zahlen ergänzen:** Speicherplatz Webhosting, Benutzer Nextcloud, Geräte
  IT-Wartung, Geschäftszeiten, Steuernummer und UID-Nummer im Firmendatenblatt.
- **QS-Checklisten:** Grenzwert Kreisgenauigkeit Controller, Lighthouse-Zielwert.
- **WKO:** Ausnahme vom Rücktrittsrecht für Wunsch-PCs; wie weit „Rechtstexte“
  im Paket Webseite Basis gehen dürfen (keine Rechtsberatung); was mit nie
  abgeholten Geräten passieren darf.
- **Nutzungsbedingungen:** Abschnitt zu Hosting und Wartung für Privatkunden
  einfügen (Vorschlag in `pruefung-bestehende-rechtstexte.md`).

Zu klären außerhalb der Texte:
- Betriebshaftpflichtversicherung, gerade für Hosting und Fernwartung.
- Gerichtsstand: Telfs hat ein eigenes Bezirksgericht. Die AGB nennen wie gewünscht Innsbruck; für Verbraucher gilt ohnehin ihr Wohnsitz.

# Unternehmensunterlagen

Texte für Verträge, Formulare und Übersichten von IT-Tabelander (#127). Jede
HTML-Datei ist ein Dokument und nutzt nur, was der Editor im
Dolibarr-Wissensmanagement behält: `p`, `h2`, `h3`, `ul`/`ol`/`li`, `strong`,
`em`, `a`, `br`. Keine Tabellen, keine Stile. Die Gestaltung als PDF
(Werkbank-Design) macht das Dolibarr-Modul.

> **Stand 26.11.2026: freigabefähig, WKO-Prüfung empfohlen.** Alle Texte sind
> fertig formuliert, ohne offene Stellen, nach den Mustern und Merkblättern der
> WKO und den Gesetzen im RIS. Eine Prüfung durch den WKO-Rechtsservice bleibt
> empfohlen. Vor der Freigabe muss Fabian die Punkte unter „Was Fabian dafür
> sicherstellen muss“ erledigen, damit jede Zusage stimmt. Der Hinweis zur
> WKO-Prüfung steht nur hier und im Kopfkommentar jeder Datei, nicht im Text
> für Kundinnen und Kunden.

Ausgangspunkt waren die Muster der WKO (Fachverband UBIT: AGB für
Organisations- und Programmierleistungen B2B und für IT-Betreiberdienstleistungen
B2B, Ausgabe 2024; Muster-Vereinbarung zur Auftragsverarbeitung) und die
Gesetze (ABGB, UGB, KSchG, FAGG, ECG, DSGVO/DSG, UrhG, VGG). Die Texte sind
eigene Fassungen in einfacher Sprache, keine Abschriften.

## Übersicht

| Datei | Titel in Dolibarr | Für wen | Anrede | Kategorie | Öffentlich | PDF-Slug | WKO-Prüfung |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `agb-unternehmer.html` | AGB für Unternehmer | Firmenkunden | Sie | Rechtstexte | ja (Website, PDF) | `agb-unternehmer` | empfohlen |
| `widerruf.html` | Widerrufsbelehrung und Muster-Widerrufsformular | Privatkunden (aus den Nutzungsbedingungen) | Du | Rechtstexte | ja (Website, PDF) | `widerruf` | empfohlen |
| `leistungsbeschreibung-hosting-wartung.html` | Leistungsbeschreibung Hosting und Wartung | Firmenkunden mit Hosting/Wartung, Teil des Vertrags | Sie | Unterlagen | auf Anfrage, mit dem Angebot | `leistungsbeschreibung-hosting-wartung` | empfohlen |
| `leistungsbeschreibung-hosting-wartung-privat.html` | Leistungsbeschreibung Hosting und Wartung für Privatkunden | Privatkunden mit Hosting/Wartung (§ 15 KSchG) | Du | Unterlagen | auf Anfrage, mit dem Angebot | `leistungsbeschreibung-hosting-wartung-privat` | empfohlen |
| `auftragsverarbeitung.html` | Vereinbarung zur Auftragsverarbeitung (Art. 28 DSGVO) | Firmenkunden mit Hosting, Wartung, Fernwartung | Sie | Unterlagen | auf Anfrage, zum Unterschreiben | `auftragsverarbeitung` | empfohlen |
| `fernwartung-einverstaendnis.html` | Einverständniserklärung Fernwartung | alle Fernwartungskunden | neutral (Ich-Form des Kunden) | Unterlagen | auf Anfrage | `fernwartung-einverstaendnis` | empfohlen |
| `geraeteannahme-bausteine.html` | Geräteannahmeschein – Textbausteine | Privatkunden (ein Baustein für Firmen) | Du | Unterlagen | nein, Text für das Modul | `geraeteannahme-bausteine` | empfohlen |
| `qs-checklisten.html` | Qualitätsprüfung – Checklisten je Auftragsart | intern | – | Unterlagen | nein | `qs-checklisten` | nicht nötig |
| `firmendatenblatt.html` | Firmendatenblatt IT-Tabelander | Firmenkunden (Lieferantenanlage) | neutral | Unterlagen | auf Anfrage | `firmendatenblatt` | nicht nötig |
| `preisliste.html` | Preisliste IT-Tabelander | intern, auf Anfrage | neutral | Unterlagen | **nein** | `preisliste` | nicht nötig |

Nutzungsbedingungen, Datenschutzerklärung und Ergänzung zum Impressum liegen
schon freigegeben in Dolibarr (Artikel 1 bis 3, Quelle der Wahrheit) und als
Ausgangsentwürfe in `backend/app/legal_drafts/`. Sie gehören ebenfalls in die
Kategorie „Rechtstexte“ und werden mit ihr zu Rechtstexten des Moduls (ab 1.5.0). Änderungsvorschläge dazu stehen in
[`pruefung-bestehende-rechtstexte.md`](pruefung-bestehende-rechtstexte.md).

Die Bankverbindung steht nur als Platzhalter `__BANK__` im Firmendatenblatt.
Kontonummern gehören nicht ins Repo.

## Wo die Texte in Dolibarr liegen

Ab Modul ittabelanderpdf 1.5.0 sind Rechtstexte und Unterlagen ein eigenes
Objekt des Moduls (Menü Geschäftspartner → Unterlagen) mit Versionen, Stand und
PDF. Das Wissensmanagement bleibt nur für die FAQ der Website.

| Art im Modul | Inhalt | Website |
| --- | --- | --- |
| Rechtstext (öffentlich) | Impressum-Ergänzung, Datenschutz, Nutzungsbedingungen, Widerruf, AGB für Unternehmer | als Rechtsseite mit PDF (REST-API des Moduls) |
| Intern | Firmendatenblatt, Preisliste, QS-Checklisten, Bausteine | nie; nur als PDF aus Dolibarr, Versand per Mail |
| Vorlage je Kunde | Auftragsverarbeitung, Leistungsbeschreibungen, Einverständnis Fernwartung | nie; je Kunde erzeugt (ab 1.6.0) |

Die bisherigen Wissensartikel (Kategorien „Rechtstexte“ und „Unterlagen“)
übernimmt das Modul beim Aktivieren oder per Knopf in den Einstellungen; die
Artikel selbst bleiben stehen.

## Import nach Dolibarr

`scripts/import_unterlagen.py` legt jedes Dokument als **Entwurf** unter den
Unterlagen des Moduls an (REST `ittabelanderpdf/unterlagen-entwuerfe`). Es
erkennt die Unterlage am Dateinamen (= Slug).

- Neu → als Entwurf anlegen.
- Schon da → nur den Entwurf aktualisieren (oder „unverändert“); die
  freigegebene Fassung bleibt, bis du in Dolibarr freigibst.
- Es gibt nie etwas frei.
- Öffentliche Texte (Spalte „Öffentlich“ = ja) werden als Rechtstext angelegt,
  alle anderen als interne Unterlage; die Art „Vorlage je Kunde“ stellst du an
  der Karte ein.
- `Versendbar: nein` im Kopfkommentar: die Unterlage wird nie an Kunden angeboten
  (Textbausteine wie Geräteannahme und QS-Checklisten).

```bash
# Probelauf: zeigt, was passieren würde, schreibt nichts
DOLIBARR_URL=https://erp.example.com DOLIBARR_API_KEY=... python scripts/import_unterlagen.py --dry-run
# echter Lauf
DOLIBARR_URL=https://erp.example.com DOLIBARR_API_KEY=... python scripts/import_unterlagen.py
# offene [BITTE …]-Stellen, ohne Dolibarr: keine Ausgabe und Rückgabe 0, wenn alles fertig ist
python scripts/import_unterlagen.py --offene-punkte
# früheres Ziel (Modul vor 1.5.0): Wissensartikel
DOLIBARR_URL=... DOLIBARR_API_KEY=... python scripts/import_unterlagen.py --wissensmanagement
```

Nötige Rechte des API-Benutzers: „Unterlagen und Rechtstexte lesen“ und
„Unterlagen und Rechtstexte bearbeiten und freigeben“ (Modul IT-Tabelander PDF). Danach das Schreibrecht wieder wegnehmen.
Standardbibliothek reicht, kein venv nötig.

Getestet: Unit-Tests in `backend/tests/test_unterlagen.py`, Szenario gegen die
Wegwerf-Dolibarr in `backend/tests/test_dolibarr_unterlagen.py` (Gruppe
`dolibarr` von `scripts/local_check.py`) und im Modul-Test
(`scripts/dev_dolibarr.py`, Stufe `unterlagen`).

## Ablauf bis zur Verwendung

1. Die Punkte unter „Was Fabian dafür sicherstellen muss“ erledigen.
2. Import laufen lassen, in Dolibarr unter Unterlagen prüfen und freigeben.
3. Beim Freigeben erzeugt das Modul das PDF und legt es in Dokumente (ECM)
   unter `IT-Tabelander/Unterlagen/<slug>.pdf` ab, ältere Fassungen unter
   `Archiv/`. Kundenfelder (Auftraggeber, Anschrift, Leistungen) sind derzeit
   neutrale Formularzeilen (`____`); ab Modul 1.6.0 werden sie zu Platzhaltern,
   die das Modul je Kunde füllt.
4. Empfohlen: vom WKO-Rechtsservice prüfen lassen (Tirol: Wirtschaftsrecht).
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

**Freigabefähig gemacht (26.11.2026, #143)**

- **Rücktrittsausnahme für Wunsch-PCs:** Ein PC aus handelsüblichen Teilen nach
  Auswahl des Kunden bleibt **rücktrittsfähig**. Ausgenommen sind nur Teile, die
  eigens angepasst oder angefertigt werden (Gravur, maßgefertigte Kabel, eigens
  gebaute Wasserkühlung). Begründung: Die Ausnahmen in § 18 FAGG sind abschließend
  und eng; die Kundenspezifikation muss der Unternehmer beweisen (WKO,
  Rücktrittsrecht bei Warenkauf im Internet). Standardteile lassen sich ohne
  Weiteres wieder trennen (so auch der deutsche BGH, VIII ZR 295/01, zu Notebooks
  nach Kundenwunsch). Die kundenfreundliche Lösung ist die sichere. In der Werkstatt
  geschlossene Verträge haben ohnehin kein Rücktrittsrecht.
- **„Rechtstexte“ im Webpaket:** Keine Rechtsberatung im Einzelfall. Fabian weist
  auf die Pflichten hin, erstellt Impressum und Datenschutzerklärung aus den
  Angaben des Kunden und den WKO-Mustern, bindet sie ein und geht sie bei der
  Übergabe mit dem **WKO-Übergabeprotokoll für gesetzeskonforme Webseiten**
  (Fachgruppen UBIT und Werbung) durch; der Kunde bestätigt dort seine Angaben.
  Begründung: Als Fachmann haftet der Webdesigner nach §§ 1299, 1300 ABGB dafür, dass
  die Seite gesetzeskonform ist, und das lässt sich per AGB nicht ausschließen
  (Präambel des WKO-Protokolls). Hinweis, Muster und Protokoll erfüllen die
  Warnpflicht. Preisliste: „Einbindung von Impressum und Datenschutzerklärung“ statt
  „Rechtstexte“.
- **Widerrufsbutton (§ 13a FAGG, für Verträge ab 1.10.2026):** Die
  Widerrufsbelehrung nennt die Online-Rücktrittsfunktion „Vertrag widerrufen“ auf
  `/rechtliches/widerruf` mit Eingangsbestätigung per E-Mail. Die Website-Funktion
  baut Issue #142.
- **Technik:** als Zusagen formuliert, die ein Ein-Personen-Betrieb mit eigenem
  Server erfüllen muss (Liste unten). USV und RAID sind nicht zugesagt; zugesagt ist
  die Wiederherstellung aus der Sicherung binnen 2 Werktagen.
- **Fernwartung:** kein Programmname, sondern „durchgehend verschlüsselt, nur nach
  Freigabe, Sitzung sichtbar“. Läuft die Verbindung über Server des Herstellers,
  sieht dieser nur Verbindungsdaten und steht dann in Anlage 3 des AVV.
- **Zahlen aus dem Angebot:** Speicherplatz, Benutzer und Geräte „im Umfang aus dem
  Angebot“. Geschäftszeiten: „werktags Montag bis Freitag, aktuelle Zeiten auf
  it.tabelander.co.at“.
- **Firmendatenblatt:** Steuernummer und UID-Nummer stehen nicht im Text. Das Modul
  kann sie als Variablen aus den Dolibarr-Firmendaten einsetzen (Kleinunternehmer
  ohne UID: Zeile entfällt).
- **QS-Richtwerte:** CPU unter Last mindestens 10 °C unter der Höchsttemperatur des
  Herstellers (Tjmax, bei aktuellen Desktop-CPUs meist 95–100 °C), Grafikkarte unter
  85 °C, Kreisfehler der Sticks unter 10 % (Hall-Effect unter 5 %), Lighthouse mobil
  mindestens 90.

## Was Fabian dafür sicherstellen muss

Jede Zusage in den Texten muss stimmen, bevor sie freigegeben werden:

- [ ] **Serverraum** abschließbar und abgeschlossen, wenn niemand da ist; Fremde nur in Begleitung (AVV Anlage 2).
- [ ] **SSH nur mit Schlüssel**, Passwort-Anmeldung am Server aus.
- [ ] **Passwortmanager** für alle Zugänge, jedes Passwort einzigartig; **zweiter Faktor** überall, wo es ihn gibt (Dolibarr, Nextcloud, Mail, Domain-Anbieter, GitHub).
- [ ] **Verwaltungsoberflächen nicht öffentlich** (Dolibarr, Server-Oberflächen, Nginx Proxy Manager): nur aus dem eigenen Netz oder per VPN (#67).
- [ ] **Arbeitsgeräte** mit Festplattenverschlüsselung (BitLocker) und automatischer Bildschirmsperre.
- [ ] **Datenträger mit Kundendaten und Sicherungen verschlüsselt** (z. B. LUKS). Geht das für bestehende Platten nicht sofort, Hosting erst danach anbieten.
- [ ] **Kunden getrennt:** eigene Datenbank, eigenes Verzeichnis, eigene Benutzer je Kunde.
- [ ] **Sicherung:** täglich, 14 Tage plus 4 Wochensicherungen, verschlüsselt, mit **Kopie an einem zweiten Ort** (z. B. NAS an einem anderen Standort). Stand 24.11.2026 fehlt die Kopie außer Haus. Liegt sie bei einem Cloud-Anbieter, ist er Unterauftragsverarbeiter (AVV Abschnitt 5 und Anlage 3, nur EU/EWR).
- [ ] **Wiederherstellung** einmal im Quartal testen und festhalten; das Ziel von 2 Werktagen muss realistisch sein (Ersatzhardware griffbereit).
- [ ] **Überwachung** alle 5 Minuten mit Warnung (z. B. Uptime Kuma), auch für Zertifikat und Speicherplatz.
- [ ] **Sicherheitsupdates** innerhalb von 3 Werktagen (z. B. automatische Updates plus monatliche Kontrolle).
- [ ] **Webserver-Protokolle** nach 14 Tagen löschen (logrotate).
- [ ] **Firewall:** von außen nur die nötigen Ports offen; Anmeldungen und Verwaltungszugriffe am Server werden protokolliert.
- [ ] **Alte Datenträger** vor dem Weggeben sicher löschen oder zerstören; Kopien aus Datenübernahmen nach dem Auftrag löschen.
- [ ] **Jährliche Prüfung** der Maßnahmen im Kalender eintragen (AVV Anlage 2).
- [ ] **Fernwartungsprogramm** wählen, das durchgehend verschlüsselt und nur nach Freigabe am Gerät verbindet (z. B. RustDesk mit eigenem Server, dann gibt es keinen Unterauftragsverarbeiter). Läuft es über Server des Herstellers: Hersteller vor dem ersten Einsatz in Anlage 3 des AVV und in die Datenschutzerklärung.
- [ ] **Verzeichnis der Verarbeitungstätigkeiten** als Auftragsverarbeiter anlegen (Art. 30 Abs. 2 DSGVO; WKO-Muster „Verzeichnis Verarbeitungstätigkeiten Auftragsverarbeiter“).
- [ ] **Ablauf bei Datenpannen** festhalten (Kunde binnen 24 Stunden informieren).
- [ ] **Rücktrittsfunktion** auf `/rechtliches/widerruf` live (#142), bevor die Widerrufsbelehrung freigegeben wird. Bis dahin die Online-Annahme für Privatkunden aussetzen oder die Widerrufsbelehrung erst mit #142 freigeben.
- [ ] **Leistungskatalog in Dolibarr:** Beschreibung von `DL-WEB-BASIS` auf „Einbindung von Impressum und Datenschutzerklärung“ ändern.
- [ ] **WKO-Übergabeprotokoll** für gesetzeskonforme Webseiten besorgen und bei jeder Übergabe verwenden.
- [ ] **Live-Rechtstexte** nach `pruefung-bestehende-rechtstexte.md` anpassen (Nutzungsbedingungen: Rücktritt mit Verweis auf die Widerrufsbelehrung, Abschnitt Hosting für Privatkunden).
- [ ] **Betriebshaftpflichtversicherung** prüfen, gerade für Hosting und Fernwartung.

Gerichtsstand: Telfs hat ein eigenes Bezirksgericht. Die AGB nennen wie gewünscht
Innsbruck; für Verbraucher gilt ohnehin ihr Wohnsitz.

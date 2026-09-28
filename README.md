# IT-Tabelander

Website (One-Pager, Design A) mit FastAPI-Backend, MongoDB und Online-Admin;
Kunden, Anfragen, Firmendaten und Rechtstexte liegen in Dolibarr. In
Produktion liefert FastAPI die Website, den Admin unter `/admin` und `/api`
über **einen einzigen internen Port** aus.

## Schnellstart – ein Befehl

Empfohlen ist Ubuntu 24.04 LTS. Im Projektverzeichnis genügt:

```bash
cd /var/www/IT-Tabelander
./start.sh
```

`start.sh` erledigt automatisch:

1. fehlende Ubuntu-Grundpakete installieren;
2. Node.js 24 LTS und Yarn 1.22.22 installieren;
3. MongoDB 8.0 Community installieren, aktivieren und starten, wenn eine lokale
   MongoDB konfiguriert ist;
4. `backend/.env` mit sicheren Zufallswerten anlegen;
5. Python-venv erstellen/reparieren und Runtime-Pakete installieren;
6. Website samt Admin (`web/`) exakt aus `web/yarn.lock` installieren und
   bauen;
7. den bestehenden App-Prozess sauber neu starten;
8. MongoDB, API und Website per Healthcheck prüfen.

Für Systempakete sind `root`-Rechte oder `sudo` erforderlich. Bereits korrekt
installierte Komponenten werden nicht erneut installiert. Ein mit `Ctrl-C`
abgebrochener Lauf kann einfach mit `./start.sh` fortgesetzt werden.

Beim ersten Start werden einmalig sichere Admin-Zugangsdaten ausgegeben. Danach
unter `/admin` anmelden und E-Mail/Passwort unter **Technik → Dein Zugang**
ändern.

## Reverse Proxy

Wenn der Reverse Proxy auf einem anderen Gerät oder in einem Container läuft,
ist das einzige Proxy-Ziel die LAN-IP dieses Servers mit Port `8001`, zum
Beispiel:

```text
http://192.168.2.123:8001
```

`start.sh` erkennt die Server-IP und zeigt das konkrete Ziel am Ende an. Das
Backend bindet standardmäßig an `0.0.0.0`, damit diese LAN-Verbindung möglich
ist. `0.0.0.0` wird niemals in den Reverse Proxy eingetragen. Läuft Apache oder
Nginx direkt auf demselben Server, kann dort weiterhin `127.0.0.1:8001`
verwendet werden.

Website, Admin und API laufen gemeinsam dort:

```text
/                 Website
/admin            Online-Admin
/api/*            Backend-API
/api/health       Healthcheck
/robots.txt       dynamisch
/sitemap.xml      dynamisch
```

### Apache

Benötigte Module einmalig aktivieren:

```bash
sudo a2enmod proxy proxy_http headers ssl
```

Im HTTPS-VHost:

```apache
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"

ProxyPass        / http://127.0.0.1:8001/
ProxyPassReverse / http://127.0.0.1:8001/
```

Danach:

```bash
sudo apachectl configtest
sudo systemctl reload apache2
```

### Nginx

```nginx
location / {
    client_max_body_size 10m;
    proxy_pass http://127.0.0.1:8001;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

HTTPS ist für den Admin-Login erforderlich, weil die Auth-Cookies absichtlich
nur sicher über HTTPS übertragen werden.

## Start, Stop und Updates

```bash
./start.sh                 # installieren/bauen und aktuellen Stand starten
./stop.sh                  # nur IT-Tabelander stoppen; MongoDB bleibt aktiv
./stop.sh --disable        # stoppen und Autostart abschalten (Wartung)
./update.sh                # git pull, vorbereiten, sauber neu starten
./start.sh --refresh       # Dependencies und Build vollständig erneuern
./start.sh --reset-admin   # neues Admin-Passwort erzeugen
```

Auf einem Server mit systemd richtet `start.sh` beim ersten Start den Dienst
`it-tabelander` ein. Die Website startet dann nach einem Server-Neustart von
selbst und nach einem Absturz nach drei Sekunden neu. `./stop.sh` stoppt sie
bis zum nächsten `./start.sh` oder Neustart des Servers; `./stop.sh --disable`
schaltet zusätzlich den Autostart ab. Zustand und Meldungen:

```bash
sudo systemctl status it-tabelander
tail -f logs/backend.log
```

Wer keinen Dienst möchte, setzt `USE_SYSTEMD="0"` in `deploy.config.local`;
dann läuft die App wie früher als Hintergrundprozess ohne Autostart.

### Was der Server ausliefert

- `/` die Website: ein One-Pager, fertig vorgerendert. Rechtliches unter
  `/rechtliches/impressum`, `/datenschutz` und `/nutzungsbedingungen`.
- `/admin` die Verwaltung (Teil derselben App, nur bei Bedarf geladen).
- **Alte Adressen leiten weiter** (dauerhaft, 301): z. B. `/impressum` →
  `/rechtliches/impressum`, `/pc-reparatur` → `/leistungen/pc-reparatur`
  (öffnet den passenden Tab), `/kontakt` und `/anfrage` → Kontaktbereich.
  Alte Links und Google-Einträge führen so an die richtige Stelle.
- Unbekannte Adressen bekommen eine echte „Seite nicht gefunden“ (404).
- Für Google und Link-Vorschauen setzt der Server beim Ausliefern die
  öffentliche Adresse, das Vorschaubild und die Firmendaten samt
  Öffnungszeiten (aus Dolibarr) ein. Die Sitemap nennt nur die neuen Adressen.
- Die Website lädt nichts von fremden Servern; eine strenge Sicherheitsregel
  (Content-Security-Policy) erlaubt nur Dateien vom eigenen Server. Google
  Analytics gibt es auf der neuen Seite nicht mehr – ohne Tracking braucht sie
  auch kein Cookie-Banner.

`update.sh` lädt ausschließlich Fast-Forward-Updates und merkt sich vorher den
laufenden Stand. Dependencies und ein neuer Frontend-Build werden vor dem
Stoppen vorbereitet (Website und Admin gemeinsam); schlägt das fehl, bleibt die Website unangetastet und der
Code auf der Platte geht auf den laufenden Stand zurück. Scheitert der Start
des neuen Stands, gehen Code, Python-Pakete und Frontend-Build komplett zurück
und der vorherige Stand wird wieder gestartet. Am Ende steht immer, welcher
Stand läuft.

Wer Systempakete bewusst selbst verwaltet, kann verwenden:

```bash
./start.sh --no-system-install
```

## Was wird wo eingestellt?

### Online im Admin unter `/admin`

Jedes Thema steht an genau einer Stelle:

| Bereich | Was du dort pflegst |
|---|---|
| **Übersicht** | was wartet, was neu ist, was noch fehlt (z. B. E-Mail-Versand); bei jeder neuen Anfrage **Etikett** für das Gerät |
| **Texte** | „Über mich“: Text, Qualifikationen, Foto |
| **Leistungen** | die Tabs der Website: Name, Überschrift, Text, Stichpunkte, Bild, Reihenfolge, sichtbar; Vorschau je Leistung |
| **Galerie** | Fotos deiner Arbeiten, auch direkt mit der Handy-Kamera: Titel, Bereich, Reihenfolge, sichtbar |
| **Bewertungen** | Bewertungen eintragen, wie Kunden sie geschrieben haben (Google mit Link, persönlich, E-Mail); Bewertungen über den Link nach dem Auftrag **freigeben**; Bewertungsbitten mit **Jetzt prüfen** |
| **Dolibarr** | Verbindung (mit Test, zeigt die Version), Inhalte aus der Wissensbasis, Rechtstexte mit Prüfliste und Entwürfen, Themengruppen je Anfrageart, Warteschlange, Altdaten-Umzug |
| **Technik** | öffentliche Adresse, Einzugsgebiet, Titel und Beschreibung der Startseite für Google, Google-Profil, E-Mail-Versand mit **Test-Mail senden**, **Kundenbereich** ein/aus mit Bankverbindung für den QR-Code, dein Zugang |

Die Anmeldung verlängert sich im Hintergrund von selbst (bis zu 7 Tage).
Läuft sie doch ab, sagt der Admin das und öffnet nach dem neuen Anmelden
wieder dieselbe Seite. Formulare lassen sich erst speichern, wenn ihre Daten
geladen sind – so überschreibt ein Ladefehler nie etwas.

### Bewertungsbitte nach dem Auftrag

Im Anfrageformular kann der Kunde freiwillig Ja sagen: „Nach Abschluss darf
ich dich einmal per Mail um eine kurze Bewertung bitten.“ Ohne dieses Ja kommt
keine Mail – so will es das Gesetz für solche Mails (§ 174 TKG 2021). Das Ja
steht auch im Ticket in Dolibarr.

Schließt du das Ticket in Dolibarr, schickt die Website innerhalb von 30
Minuten **eine einzige** Mail an die E-Mail-Adresse im Ticket, mit einem
persönlichen Link (60 Tage gültig, für eine Bewertung). Wird das Ticket
abgebrochen, kommt keine Mail. Was der Kunde schreibt, landet unter
**Bewertungen** oben mit „Wartet auf deine Freigabe“; erst mit **Freigeben**
erscheint es auf der Website. Die Mail geht über den E-Mail-Versand der Website
(**Technik**). Unter **Bewertungen → Bewertungsbitten** siehst du, wie viele
warten, verschickt und beantwortet sind; **Jetzt prüfen** schickt sofort statt
erst beim nächsten 30-Minuten-Takt.

### Etikett für das Gerät

Unter **Übersicht → Neueste Anfragen** gibt es bei jeder Anfrage **Etikett**
(oder `/admin/etikett` und die Anfrage- oder Ticket-Nummer eingeben). Das
Etikett zeigt Nummer, Gerät (so, wie das Ticket in Dolibarr heißt), Datum und
einen QR-Code zur Status-Seite – der Kunde scannt ihn und sieht den Stand. Ein
Name steht bewusst nicht drauf, weil das Etikett sichtbar am Gerät klebt.
Drucken geht auf Etikettendrucker mit 62 × 29 mm (z. B. Brother DK-11209) oder
auf ein normales A4-Blatt zum Ausschneiden.

Firmendaten, Öffnungszeiten, Social-Media-Links, Impressum, Datenschutz,
Nutzungsbedingungen und FAQ stehen **nicht** mehr hier, sondern in Dolibarr
(siehe „Firmendaten, Rechtstexte und FAQ aus Dolibarr“).

Gespeicherte API-Keys werden vom Backend niemals wieder an den Browser
ausgegeben. Der Admin zeigt nur an, ob ein Key vorhanden ist. Ein neuer Wert
ersetzt den bisherigen Key; vorhandene Keys können dort auch entfernt werden.
Dolibarr-Basis-URL und -API-Key dürfen aus Sicherheitsgründen nur vom
`super_admin` geändert werden, ebenso Mailserver, Benutzername und Passwort
des E-Mail-Versands. Das Mail-Passwort ist wie die API-Keys ein reines
Schreibfeld.

### E-Mail-Versand der Website einrichten

Die Website schickt selbst nur zwei Arten von Mails: die Warnung an dich, wenn
eine Anfrage nicht in Dolibarr ankommt, und die eine Bewertungsbitte nach einem
geschlossenen Ticket (nur mit dem Ja des Kunden). Bestätigungen an Kunden
verschickt Dolibarr.

1. `/admin/technik` öffnen, Block **E-Mail-Versand der Website**.
2. Mailserver, Port und Verschlüsselung deines Mail-Anbieters eintragen (meist
   Port 587 mit STARTTLS), dazu Benutzername und Passwort des Postfachs.
3. Absender-Adresse (z. B. `office@tabelander.co.at`) und bei **Warnungen gehen
   an** die Adresse, die du täglich liest. Leer = die E-Mail der Super-Admins.
4. **Speichern**, dann **Test-Mail senden**. Kommt sie an, passt alles; sonst
   steht dort in Worten, was nicht stimmt (z. B. Passwort abgelehnt).

## Anfrageformular und Dolibarr

Alle Kundenanliegen laufen zentral über `/anfrage`. Dort stehen Reparatur,
PC-Neubau, PC-/Notebook-Upgrade, Controller-Umbau, Beratung und Sonstiges zur
Auswahl. Je nach Anfrageart werden passende Geräte-, Wunsch-, Budget- und
Zeitraumfelder angezeigt; außerdem können bis zu fünf Fotos mit jeweils maximal
8 MB hochgeladen werden.
Auch die Kontaktseite führt für neue schriftliche Anliegen in dieses zentrale
Formular; direkte E-Mail- und Telefonlinks bleiben dort erhalten.
Die früheren Builder-Adressen leiten auf die passende vorausgewählte Anfrageart
weiter.

Eine abgesendete Anfrage wird immer zuerst lokal in MongoDB gespeichert und
erhält eine Referenz `ANF-XXXXXXXX`. Wenn Dolibarr aktiv ist, passiert danach
automatisch Folgendes:

1. Geschäftspartner anhand der E-Mail-Adresse suchen. Einen vorhandenen Kunden
   oder Interessenten ändert die Website **nie** – Name, Adresse, UID und
   Telefon in Dolibarr bleiben, wie sie sind. Weichen die Angaben im Formular
   ab, stehen sie nur im Ticket, mit einem Hinweis für die Werkstatt;
2. andernfalls einen neuen Interessenten mit den freiwillig angegebenen Firmen-,
   Adress-, Telefon-, UID-, Firmenbuch-, Gerichtsstand-, EORI- und Steuerdaten
   anlegen;
3. ein Ticket mit allen Angaben erstellen, klassifizieren und mit dem
   Geschäftspartner verknüpfen;
4. die Fotos als Dokumente an das Ticket hängen. Danach löscht die Website ihre
   eigene Kopie; Kundenfotos sind nie öffentlich abrufbar;
5. Dolibarr schickt dem Kunden die Eingangsbestätigung und der Werkstatt eine
   Benachrichtigung – beides aus Dolibarr, nicht von der Website.

Kunden sehen den Stand ihrer Anfrage über die Anfragenummer und ihre E-Mail
oder über den Link in der Bestätigung (Schnittstelle `/api/inquiries/status`,
die Seite dazu kommt mit der neuen Website). Angezeigt werden nur Anfrageart und
Schritt, keine persönlichen Daten. Die Schritte kommen aus dem Ticket:

| Schritt | Woher in Dolibarr |
|---|---|
| eingegangen | Ticket neu, gelesen oder zugewiesen |
| Angebot bereit | ein **freigegebenes** Angebot ist mit dem Ticket verknüpft (Angebot aus dem Ticket heraus anlegen oder im Ticket unter „Verknüpfte Objekte“ verknüpfen); nach der Annahme zählt wieder das Ticket |
| in Arbeit | Ticket „In Bearbeitung“ |
| wartet auf dich | Ticket „Benötigt weitere Informationen“ |
| pausiert | Ticket „Wartend“ |
| abholbereit | Häkchen **Gerät abholbereit** im Ticket (Zusatzfeld, siehe unten) |
| abgeschlossen / abgebrochen | Ticket geschlossen / abgebrochen |

Ein Dolibarr-Fehler verliert deshalb keine Kundenanfrage. Klappt die Übergabe
nicht (Dolibarr aus, Netz weg, Recht fehlt), versucht es die Website
automatisch weiter: nach 5, 10, 20, 40 Minuten, dann stündlich, etwa drei Tage
lang. Wartet eine Anfrage länger als 30 Minuten, bekommst du **eine** Warn-Mail
mit Anfragenummer und Grund – pro Anfrage nur einmal. Unter `/admin/dolibarr`
siehst du im Block **Warteschlange**, was wartet und warum, und kannst mit
**Jetzt erneut senden** sofort nachschieben. Die vom Browser erzeugte
Anfrage-ID verhindert Doppelanlagen bei einem Netzwerk-Retry.

Sobald das Ticket in Dolibarr vollständig ist (mit Fotos und Rückruf-Termin),
löscht die Website Beschreibung, Fotos und Kontaktdaten. Übrig bleiben nur
Anfragenummer, Ticket-Bezug und Zeitpunkte – damit dieselbe Anfrage nicht
zweimal übergeben wird. Kundendaten stehen danach nur noch in Dolibarr.

**Kontaktformular** (`/api/contact`, die Seite dazu kommt mit der neuen
Website): Name, E-Mail, optional Telefon und Nachricht werden ein Ticket in
Dolibarr. Ein neuer Absender wird dabei **kein** Interessent; schreibt ein
bekannter Kunde, hängt das Ticket an ihm (ohne ihn zu ändern). Für eine eigene
Themengruppe „Kontakt“ in Dolibarr deren Code (z. B. `KONTAKT`) unter
`/admin/dolibarr` unter **Themengruppen je Anfrageart** bei **Kontaktnachricht** eintragen.

**Rückruf-Wunsch**: Wer im Formular eine Wunschzeit angibt (Telefonnummer
Pflicht, frühestens in 10 Minuten, höchstens 60 Tage voraus), bekommt in
Dolibarr einen Termin „Rückruf“ (Art: Telefonat, 15 Minuten) im Kalender,
verknüpft mit Ticket und Kunde. Du findest ihn in der Kalenderansicht; der
Termin gehört dem Website-Benutzer, als Administrator siehst du ihn trotzdem.

### Altdaten einmalig umziehen

Anfragen und Kontaktnachrichten von vor dieser Version liegen noch auf der
Website. So kommen sie nach Dolibarr:

1. `/admin/dolibarr` öffnen, Block **Altdaten umziehen (einmalig)**.
2. **Probelauf** klicken. Die Liste zeigt jede Anfrage und was mit ihr
   passieren würde. Der Probelauf ändert nichts.
3. Passt die Liste, **Jetzt übergeben** klicken (nur Super-Admin). Die Kunden
   bekommen dabei **keine** Mail. Pro Klick werden bis zu 25 Einträge
   übergeben; steht danach „noch übrig“, einfach nochmal klicken.
4. Zum Schluss nochmal **Probelauf**: „Keine Altdaten mehr“.

Schon früher übergebene Anfragen bekommen dabei ihre Fotos ans Ticket, danach
werden auch ihre Daten auf der Website gelöscht.
Nicht abgesendete Foto-Entwürfe laufen nach 24 Stunden ab und werden samt Datei
automatisch bereinigt. Kunden-Uploads liegen nur unter `backend/uploads/` und
werden ausdrücklich nicht in Git aufgenommen.

### Dolibarr einmalig vorbereiten

Die Bezeichnungen sind die der deutschen Oberfläche von Dolibarr 23 und 24 (sie sind gleich).

1. Unter **Einstellungen → Module/Anwendungen** aktivieren: **Geschäftspartner**,
   **Tickets**, **Agenda (Ereignisse/Termine)**, **Angebote**, **Rechnungen**,
   **Wissensmanagement-System**, **Kategorien** und **REST-API**.
2. Einen eigenen Benutzer für die Website anlegen (kein Administrator) und ihm
   unter **Benutzer → Berechtigungen** genau diese Rechte geben:
   - Geschäftspartner: **einsehen** und **anlegen/bearbeiten**,
   - Geschäftspartner: **alle einsehen, nicht nur die verknüpften** – ohne
     dieses Recht findet die Website Stammkunden nicht und legt sie doppelt an,
   - Kontakte: **einsehen** (Anmeldung im Kundenbereich mit der Adresse eines
     Kontakts),
   - Tickets: **lesen** und **anlegen/ändern**,
   - Agenda: **eigene Termine einsehen** und **eigene Termine anlegen**
     (für Rückruf-Wünsche),
   - Wissensmanagement: Artikel **lesen**, Kategorien: **lesen**
     (Rechtstexte, FAQ),
   - Angebote: **lesen** (Schritt „Angebot bereit“) und **anlegen/ändern**
     (Annehmen und Ablehnen im Kundenbereich),
   - Rechnungen: **lesen** (Kundenbereich).
   Im Benutzer einen API-Schlüssel erzeugen. **Keine** Admin-Rechte.
   Nur vorübergehend zusätzlich „Wissensmanagement: Artikel anlegen/ändern“ –
   für die Entwürfe der Rechtstexte und den einmaligen Umzug der alten FAQ –,
   danach wieder wegnehmen.
3. Unter **Einstellungen → Erweiterte Einstellungen** zwei Einträge anlegen,
   jeweils mit dem Login des Website-Benutzers als Wert:
   `API_LOGINS_ALLOWED_FOR_GET_COMPANY` (Firmendaten) und
   `API_LOGINS_ALLOWED_FOR_CONST_READ` (Öffnungszeiten). So braucht der
   Website-Benutzer keine Admin-Rechte.
4. Zwei **Ergänzende Attribute**, jeweils Typ **Boolean (ein Kontrollkästchen)**:
   - in der Einrichtung des Ticketmoduls: Bezeichnung „Gerät abholbereit“,
     Code `abholbereit`,
   - in der Einrichtung der Geschäftspartner: Bezeichnung „Kundenbereich
     gesperrt“, Code `kundenbereich_gesperrt`.
5. In der **Einrichtung des Ticketmoduls** bei der Benachrichtigung über neue
   Tickets die Werkstatt-Adresse eintragen und beim Absender die
   Absender-Adresse; das öffentliche Interface einschalten und als seine URL
   `https://it.tabelander.co.at/status/` eintragen. Dolibarr schreibt den
   Status-Link nur mit eingeschaltetem öffentlichen Interface in die
   Bestätigung – der Link führt dann auf die Status-Seite der Website, nicht zu
   Dolibarr. Der E-Mail-Versand von Dolibarr selbst muss eingerichtet sein
   (**Einstellungen → E-Mails**).
6. Unter `/admin/dolibarr` (Block **Verbindung**) Dolibarr aktivieren, die
   Basis-URL der Installation (ohne `/api/index.php`) und den API-Key
   eintragen, dann **Verbindung prüfen**.

Die Anfrageart wird ohne weitere Einrichtung passend gesetzt: Reparaturen als
`ISSUE`, Neubau/Beratung als `COM`, Umbau/Upgrade als `REQUEST` und Sonstiges als
`OTHER`; die Dringlichkeit ist zunächst `NORMAL`. Eigene Themengruppen können in
Dolibarr angelegt und deren Codes unter `/admin/dolibarr` je Anfrageart
zugeordnet werden. Sinnvolle Codes sind `REPARATUR`, `PC_BAU`, `PC_UPGRADE`,
`CONTROLLER`, `BERATUNG` und `SONSTIGES`. Leere Zuordnungen werden nicht an
Dolibarr gesendet und können den Sync daher nicht stören.

Die genaue Bezeichnung einzelner Rechte kann je nach Sprache leicht abweichen.

### Firmendaten, Rechtstexte und FAQ aus Dolibarr

Alles, was die Website über die Firma sagt, pflegst du in Dolibarr:

- **Firmendaten** unter **Einstellungen → Unternehmen/Institution**:
  Firmenname, Firmenadresse, Postleitzahl, Stadt, Telefon, E-Mail, Internet,
  „Name(n) des/der Manager“, „Gegenstand des Unternehmens“, Umsatzsteuer-ID,
  Steuernummer, Gerichtsstand (Firmenbuchgericht), Firmenbuchnummer und die
  sozialen Netzwerke. Daraus baut die Website das Impressum.
- **Öffnungszeiten** im Reiter **Öffnungszeiten** derselben Seite.
- **FAQ** als Artikel der **Wissensbasis**:
  1. Unter **Kategorien** eine Kategorie für die Wissensbasis anlegen,
     z. B. „Website“.
  2. Je eine Frage pro FAQ-Eintrag als Artikel schreiben, ihm die Kategorie
     „Website“ geben und ihn **freigeben**. Artikel ohne diese Kategorie
     bleiben intern – interne Notizen sieht nie jemand auf der Website.
  3. Auf der Website unter `/admin/dolibarr` im Block **Inhalte aus
     Dolibarr** die Kategorie auswählen, **Auswahl speichern**.
- **Rechtstexte** ebenfalls als Artikel der Wissensbasis, siehe nächster
  Abschnitt.

In den FAQ erscheinen nur freigegebene Artikel der Kategorie, die kein
Rechtstext sind.
Bis der erste FAQ-Artikel freigegeben ist, zeigt die Website ihre bisherigen
FAQ weiter. Die Website fragt Dolibarr höchstens alle 10 Minuten; **Neu laden**
im Admin holt sofort. Ist Dolibarr nicht erreichbar, zeigt sie den letzten
bekannten Stand. Was fehlt oder nicht erlaubt ist, steht im Block in Worten.

Bankdaten zeigt die Website bewusst nicht: Das Dolibarr-Recht dafür würde auch
Kontobewegungen lesbar machen. Die IBAN steht auf den Rechnungen aus Dolibarr,
die im Kundenportal (Meilenstein 5) abrufbar werden.

### Rechtstexte: Impressum, Datenschutzerklärung, Nutzungsbedingungen

Unter `/admin/dolibarr` im Block **Rechtstexte** steht alles an einer Stelle:

- **Impressum aus den Firmendaten** als Prüfliste: ✓ ausgefüllt, **!** fehlt
  (Pflicht), **–** leer, aber nur nötig, wenn es auf dich zutrifft (z. B. UID).
  Bei jedem fehlenden Punkt steht das Feld in Dolibarr dabei.
- Drei Texte mit ihrem Stand (fehlt, Entwurf, freigegeben) und den Stellen,
  die noch zu ergänzen sind:
  - **Ergänzung zum Impressum**: Rechtsform, Gewerbe, Gewerbebehörde, Kammer,
    Berufsrecht und Offenlegung nach dem Mediengesetz. Dafür hat Dolibarr kein
    Feld, das die Website lesen kann (Unternehmensform und „Hinweis“ gibt die
    Schnittstelle nicht heraus).
  - **Datenschutzerklärung**
  - **Nutzungsbedingungen** für Reparaturen, mit dem Rücktrittsrecht und dem
    Muster-Widerrufsformular für online angenommene Angebote

So kommst du zu fertigen Texten:

1. Dem Website-Benutzer in Dolibarr vorübergehend das Recht
   **Wissensmanagement: Artikel anlegen/ändern** geben.
2. Beim Text **Entwurf in Dolibarr anlegen** klicken. Der Entwurf landet als
   Artikel in der Wissensbasis und ist sofort für die Website gewählt; dort
   steht er mit dem Hinweis „Entwurf“. Eine Kategorie braucht er nicht.
3. In Dolibarr **Wissensbasis** → Artikel öffnen → **Ändern** → alle Stellen
   „[BITTE …]“ ergänzen oder entscheiden → **Speichern** → **Freigeben**.
4. Im Admin **Neu laden**. Solange etwas fehlt, ein Text Entwurf ist oder eine
   Stelle offen ist, sagt es auch die **Übersicht**.
5. Das Recht aus Schritt 1 wieder wegnehmen.

Die Entwürfe beschreiben, was diese Website wirklich tut: keine Cookies für
Besucher, nichts von fremden Servern, Anfragen gehen nach Dolibarr und die
Website vergisst sie danach, die Bewertungsbitte nur mit Ja. Sie sind in
einfacher Sprache geschrieben, ersetzen aber keine Rechtsberatung – vor der
Freigabe am besten vom Rechtsservice der WKO prüfen lassen. Den früher üblichen
Link zur EU-Plattform für Online-Streitbeilegung braucht es nicht mehr; die
Plattform wurde im Juli 2025 eingestellt.

**Nutzungsbedingungen an Angebote hängen:** die Seite
`/rechtliches/nutzungsbedingungen` im Browser drucken und „Als PDF speichern“
(gedruckt wird nur der Text). In Dolibarr unter **Einstellungen → PDF →
Spezifische Parameter** die Datei hochladen und **Ein PDF am Ende eines
Angebots-PDF hinzufügen** einschalten. Nach jeder Änderung der Bedingungen die
Datei neu hochladen.

**Protokolle:** Die Website selbst schreibt keine IP-Adressen von Besuchern in
`logs/backend.log`. Das Zugriffsprotokoll führt nur der Webserver davor
(Apache/Nginx). Wie lange er es behält, steht in `/etc/logrotate.d/apache2`
bzw. `/etc/logrotate.d/nginx`; diese Frist gehört in die
Datenschutzerklärung.

### Kundenbereich

Unter `/kundenbereich` sehen Kunden ihre Anfragen und Reparaturen, ihre
Angebote und Rechnungen. Alles kommt bei jedem Aufruf frisch aus Dolibarr,
immer nur für diesen einen Kunden; die Website speichert davon nichts.

**Anmelden ohne Passwort:** Der Kunde gibt seine E-Mail-Adresse ein. Gehört sie
in Dolibarr zu einem aktiven Kunden oder Interessenten oder zu einem aktiven
Kontakt eines solchen, bekommt genau diese Adresse einen Link, der 15 Minuten
gilt und einmal funktioniert. Die Seite sagt nie, ob eine Adresse bekannt ist.
Danach bleibt der Kunde 7 Tage angemeldet. Konten legt niemand an – jede
E-Mail-Adresse aus Dolibarr funktioniert. Ein Kontakt einer Firma sieht die
Anfragen, Angebote und Rechnungen der Firma.

**Sperren** geht direkt in Dolibarr am Kunden, und die Website fragt spätestens
alle 5 Minuten neu:

- Zusatzfeld **Kundenbereich gesperrt** ankreuzen (einmal anlegen, siehe
  „Dolibarr einmalig vorbereiten“, Schritt 4),
- oder den Kunden auf **geschlossen** stellen,
- oder einen einzelnen Kontakt **deaktivieren**.

Die „Webzugriffskonten“ in Dolibarr taugen dafür nicht: Ihre Schnittstelle
gibt nicht heraus, ob ein Konto deaktiviert ist.

**Angebote annehmen:** Der Kunde sieht Positionen, Summe und PDF, liest den
Hinweis zum Rücktrittsrecht, kann „Bitte sofort beginnen“ ankreuzen und
bestätigt mit seinem Namen über den Knopf **Angebot kostenpflichtig annehmen**
(so will es das Gesetz für Verträge im Internet). In Dolibarr wird das Angebot
als unterschrieben geschlossen; in der internen Notiz stehen Zeitpunkt, Name,
E-Mail-Adresse, IP-Adresse und ob sofort begonnen werden soll. Steht dort
„Sofort beginnen verlangt: NEIN“, erst nach 14 Tagen anfangen oder vorher
nachfragen. Der Kunde bekommt die Bestätigung mit Rücktrittsrecht und
Muster-Formular per Mail, du eine kurze Nachricht an die Warn-Adresse aus
**Technik**. Ablehnen geht mit einem freiwilligen Grund, der ebenfalls in der
Notiz landet. Tipp: In der Dolibarr-Mailvorlage für Angebote einen Satz mit dem
Link `https://it.tabelander.co.at/kundenbereich` ergänzen.

**Rechnungen:** Offene Rechnungen zeigen Empfänger, IBAN, BIC, offenen Betrag
und Rechnungsnummer als Verwendungszweck – zum Kopieren und als QR-Code, den
österreichische Banking-Apps mit „Zahlen mit Code“ lesen. Die Bankverbindung
dafür trägst du unter **Technik → Kundenbereich** ein (dasselbe Konto wie auf
den Rechnungen aus Dolibarr): Sie aus Dolibarr zu lesen, bräuchte ein Recht,
das auch alle Kontobewegungen zeigt. Die IBAN wird beim Speichern geprüft.

**Einschalten:** Rechte und Zusatzfeld in Dolibarr wie oben, E-Mail-Versand der
Website eingerichtet, dann **Technik → Kundenbereich** einschalten. Erst dann
zeigt die Website den Link „Kundenbereich“ im Kopf und im Fuß.

### Dolibarr von außen unsichtbar machen

Mit dem Kundenbereich braucht kein Kunde mehr die Dolibarr-Oberfläche. Die
Anmeldeseite von Dolibarr sollte deshalb von außen nicht mehr erreichbar sein.
Die Website braucht nur die Schnittstelle unter `/api/`. Drei Wege, vom
einfachsten zum sichersten:

1. **Nur aus dem Heimnetz oder über VPN** (z. B. WireGuard oder Tailscale auf
   dem Server): Dolibarr erlaubt nur Adressen aus diesen Netzen.
2. **Zugriffsliste im Reverse Proxy:** alles außer `/api/` nur für deine
   Netze, `/api/` nur für den Website-Server.
3. **Website und Dolibarr auf demselben Server:** Unter `/admin/dolibarr` als
   Adresse die interne Adresse eintragen (z. B. `http://127.0.0.1:8080`) und
   den öffentlichen Zugang zu Dolibarr ganz sperren.

Nginx, im `server`-Block von `erp.tabelander.co.at` (Adressen anpassen):

```nginx
location / {
    allow 10.8.0.0/24;      # dein VPN-Netz
    allow 192.168.1.0/24;   # dein Heimnetz, falls Dolibarr dort steht
    deny all;
    # ... die bisherigen Zeilen (proxy_pass bzw. fastcgi_pass)
}
location /api/ {
    allow 203.0.113.10;     # öffentliche Adresse des Website-Servers
    deny all;
    # ... die bisherigen Zeilen
}
```

Apache, im `VirtualHost` von `erp.tabelander.co.at`:

```apache
<Location "/">
    Require ip 10.8.0.0/24 192.168.1.0/24
</Location>
<Location "/api/">
    Require ip 203.0.113.10
</Location>
```

Danach prüfen: Mit dem Handy **ohne WLAN** `https://erp.tabelander.co.at`
öffnen – es muss „Forbidden“ kommen. Im Admin unter **Dolibarr → Verbindung
prüfen** muss es weiter grün sein, und eine Test-Anfrage muss als Ticket
ankommen.

### Automatisch in `backend/.env`

Diese Datei enthält nur Start-/Infrastrukturwerte und wird von `start.sh`
automatisch mit Dateimodus `600` erzeugt:

- `MONGO_URL` und `DB_NAME`
- MongoDB-Timeouts
- `JWT_SECRET`
- initiale Admin-Zugangsdaten
- CORS-/Canonical-Fallback für den ersten Datenbank-Seed

Normalerweise muss dort nichts geändert werden. Nur eine externe MongoDB muss
vor dem Start über `MONGO_URL` eingetragen werden. Die Datenbankverbindung kann
nicht sinnvoll im Online-Admin umgestellt werden, weil der Admin selbst diese
Verbindung benötigt.

### Selten nötig: `deploy.config.local`

Die versionierte `deploy.config` enthält die Projekt-Standardwerte. Für eine
serverlokale Abweichung wird `deploy.config.local` angelegt; diese Datei wird
danach geladen, von Git ignoriert und deshalb bei `./update.sh` nicht
überschrieben. Es müssen nur die abweichenden Werte enthalten sein, zum
Beispiel:

```bash
BACKEND_PORT="8010"
# Nur nötig, wenn der Reverse Proxy auf einem anderen LAN-Gerät läuft:
FORWARDED_ALLOW_IPS="127.0.0.1,192.168.2.20"
```

Host und Port sind Prozess-/Reverse-Proxy-Einstellungen und können deshalb
nicht im laufenden Online-Admin geändert werden. Bei einem getrennten Reverse
Proxy wird die von `start.sh` ausgegebene `192.168.2.xxx:8001`-Adresse
eingetragen. Der Port sollte in einer aktiven Firewall nur für die IP des
Reverse Proxys oder zumindest nur für das lokale Netz freigegeben werden; eine
Portweiterleitung am Internet-Router ist nicht erforderlich.
`FORWARDED_ALLOW_IPS` enthält ausschließlich vertrauenswürdige Proxy-IP-Adressen,
niemals pauschal `*`. So zählt der Schutz gegen zu viele Anfragen pro echte
Kunden-IP statt alle Besucher unter der Proxy-IP zusammenzufassen.

## Logs und Diagnose

```bash
sudo systemctl status it-tabelander
tail -f logs/backend.log
curl http://127.0.0.1:8001/api/health
systemctl status mongod
```

Ein gesunder Healthcheck liefert:

```json
{"status":"ok","db":true}
```

| Problem | Lösung |
|---|---|
| MongoDB startet nicht | `systemctl status mongod` und `/var/log/mongodb/mongod.log` prüfen |
| Dolibarr meldet HTTP 403 | API-Benutzerrechte für **Geschäftspartner** und **Tickets** prüfen; die genaue fehlgeschlagene Stufe steht unter `/admin/dolibarr` bei der Warteschlange |
| Stammkunde wird in Dolibarr doppelt angelegt | dem API-Benutzer das Recht **Geschäftspartner: alle einsehen, nicht nur die verknüpften** geben |
| Firmendaten oder Öffnungszeiten fehlen auf der Website | `/admin/dolibarr` → **Inhalte aus Dolibarr** zeigt den Grund; meist fehlt die Konstante `API_LOGINS_ALLOWED_FOR_GET_COMPANY` bzw. `API_LOGINS_ALLOWED_FOR_CONST_READ` |
| Ein FAQ-Artikel erscheint nicht | Kategorie „Website“ gesetzt? Freigegeben? Nicht als Rechtstext ausgewählt? Dann **Neu laden** |
| **Entwurf in Dolibarr anlegen** meldet fehlende Rechte | dem Website-Benutzer vorübergehend **Wissensmanagement: Artikel anlegen/ändern** geben |
| Kunde bekommt keine Eingangsbestätigung | Dolibarr unter **Einstellungen → E-Mails** eine Test-Mail senden lassen; die Website verschickt diese Mail nicht selbst |
| Keine Warn-Mail, obwohl Anfragen warten | unter `/admin/technik` **Test-Mail senden**; unter `/admin/dolibarr` steht bei der Warteschlange, warum die Warnung nicht rausging |
| Rückruf-Termin fehlt, Anfrage wartet mit „Anlegen des Rückruf-Termins“ | Modul **Agenda (Ereignisse/Termine)** aktivieren und dem API-Benutzer **eigene Termine anlegen** geben, dann unter `/admin/dolibarr` **Jetzt erneut senden** |
| Dolibarr meldet HTTP 404 | Als Basis-URL nur die Dolibarr-Installation eintragen, z. B. `https://erp.example.at/dolibarr`, nicht `/api/index.php` anhängen |
| Port 8001 ist belegt | fremden Dienst stoppen oder `BACKEND_PORT` in `deploy.config.local` und im Reverse Proxy gemeinsam ändern |
| Admin-Passwort vergessen | `./start.sh --reset-admin` ausführen |
| Installation wurde abgebrochen | `./start.sh` erneut ausführen |
| Frontend zeigt alten Stand | `./update.sh` oder `./start.sh --refresh` |
| Start schlägt fehl | letzte Zeilen aus `logs/backend.log` prüfen |

## Lokale Entwicklung

Backend:

```bash
cd backend
python3 -m venv venv
source venv/bin/activate
python -m pip install -r requirements-dev.txt
uvicorn server:app --reload --port 8001
```

Website und Admin (`web/`):

```bash
cd web
yarn install --frozen-lockfile
yarn dev          # http://127.0.0.1:3010 (Admin: /admin), /api geht an das Backend auf Port 8001
yarn lint && yarn test
yarn build        # dist/ mit fertig vorgerenderten Seiten
yarn test:e2e     # Playwright in 390, 768, 1280 und 1440 px, mit Barrierefreiheits-Check
```

Sie ist ein One-Pager nach Design A: Leistungen als Tabs, Ablauf, „Aus der
Werkstatt“ (nur mit Fotos sichtbar), Bewertungen (nur mit echten Bewertungen
sichtbar), Über mich und der Kontaktbereich mit den Tabs Nachricht, Anfrage
und Status. Eigene Seiten gibt es nur für Rechtliches. Schriften liegen im
Projekt; die Seite lädt nichts von fremden Servern. Hell und dunkel folgen der
Systemeinstellung. Der Link aus Dolibarrs Bestätigungsmail
(`/status/view.php?track_id=…`) öffnet direkt den Status.

Prüfungen:

```bash
cd backend && python -m pytest tests/test_unit_runtime.py tests/test_inquiry_dolibarr.py tests/test_handover.py tests/test_site_data.py -q
cd web && yarn lint && yarn test && yarn build
bash -n start.sh stop.sh update.sh
```

Der Ablauf gegen einen echten Dolibarr (mit MariaDB und Mailpit, alles in
Docker) läuft mit `python scripts/local_check.py --only dolibarr`. Geprüft wird
die Version, die auf erp.tabelander.co.at läuft (23.0.3); vor einem
Dolibarr-Update dieselben Szenarien gegen die neue Version:
`IT_TABELANDER_DOLIBARR=24.0.1 python scripts/local_check.py --only dolibarr`.

Die mutierenden API-Integrationstests sind absichtlich gesperrt. Sie laufen nur
mit `IT_TABELANDER_RUN_INTEGRATION=1`, einer lokalen URL und einer `DB_NAME`, die
`test` enthält. Dabei wird ein eigener temporärer Test-Admin verwendet; echte
Admin-Zugangsdaten und Produktionsdaten werden nicht benutzt.

## Dateien und Laufzeitdaten

```text
backend/             FastAPI, MongoDB-Zugriff und Tests
web/                 Website und Admin (Vite, React 19, Tailwind) mit Tests
deploy.config        interner Host/Port und Startparameter
deploy.config.local  optionale serverlokale Overrides (ignoriert)
start.sh             Bootstrap, Build, Start und Healthchecks
stop.sh              sicherer Prozess-Stopp per PID/Prozessgruppe
update.sh            Fast-Forward-Update mit vorbereitetem Build
run/                 PID, Lock und temporäre Deployment-Artefakte (ignoriert)
logs/                Backend-Log (ignoriert)
```

Secrets, `backend/.env`, `deploy.config.local`, venv, `node_modules`, Builds,
Logs und PID-Dateien werden nicht committed.

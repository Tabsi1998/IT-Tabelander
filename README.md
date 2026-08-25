# IT-Tabelander

React-Website mit FastAPI-Backend, MongoDB und integriertem Online-Admin. In
Produktion liefert FastAPI sowohl die Website als auch `/api` über **einen
einzigen internen Port** aus.

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
6. Frontend exakt aus `frontend/yarn.lock` installieren und bauen;
7. den bestehenden App-Prozess sauber neu starten;
8. MongoDB, API und Website per Healthcheck prüfen.

Für Systempakete sind `root`-Rechte oder `sudo` erforderlich. Bereits korrekt
installierte Komponenten werden nicht erneut installiert. Ein mit `Ctrl-C`
abgebrochener Lauf kann einfach mit `./start.sh` fortgesetzt werden.

Beim ersten Start werden einmalig sichere Admin-Zugangsdaten ausgegeben. Danach
unter `/admin` anmelden und E-Mail/Passwort unter **Einstellungen →
Admin-Zugang** ändern.

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
./update.sh                # git pull, vorbereiten, sauber neu starten
./start.sh --refresh       # Dependencies und Build vollständig erneuern
./start.sh --reset-admin   # neues Admin-Passwort erzeugen
```

`update.sh` lädt ausschließlich Fast-Forward-Updates. Dependencies und ein neuer
Frontend-Build werden vor dem Stoppen vorbereitet. Schlägt die Vorbereitung
fehl, bleibt die bisherige Website aktiv. Nach erfolgreicher Vorbereitung wird
automatisch gestoppt, der neue Build aktiviert und wieder gestartet.

Wer Systempakete bewusst selbst verwaltet, kann verwenden:

```bash
./start.sh --no-system-install
```

## Was wird wo eingestellt?

### Online unter `/admin/einstellungen`

- Unternehmensname, Adresse, Land, E-Mail, Telefon, Servicegebiet und Öffnungszeiten
- SEO-Titel, Beschreibung und öffentliche Website-URL
- Google Analytics Measurement-ID und Google Place-ID
- Google Places API-Key als geschütztes Schreibfeld
- Dolibarr aktiv/inaktiv, Basis-URL, API-Key, Timeout und Ländercode
- Light-/Dark-Logos und Social-Media-Links
- Impressum und Datenschutz
- Admin-Login-E-Mail und Admin-Passwort

Gespeicherte API-Keys werden vom Backend niemals wieder an den Browser
ausgegeben. Der Admin zeigt nur an, ob ein Key vorhanden ist. Ein neuer Wert
ersetzt den bisherigen Key; vorhandene Keys können dort auch entfernt werden.
Dolibarr-Basis-URL und -API-Key dürfen aus Sicherheitsgründen nur vom
`super_admin` geändert werden.

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

1. vorhandenen Interessenten anhand der E-Mail-Adresse suchen und wiederverwenden;
2. andernfalls einen neuen Interessenten mit den freiwillig angegebenen Firmen-,
   Adress-, Telefon-, UID-, Firmenbuch-, Gerichtsstand-, EORI- und Steuerdaten
   anlegen;
3. ein Ticket mit allen Angaben erstellen, klassifizieren und mit dem
   Interessenten verknüpfen.

Ein Dolibarr-Fehler verliert deshalb keine Kundenanfrage. Unter
`/admin/anfragen` bleiben Fehlermeldung und Zwischenstand sichtbar und die
Übertragung kann per Klick erneut gestartet werden. Die vom Browser erzeugte
Anfrage-ID verhindert Doppelanlagen bei einem Netzwerk-Retry.
Nicht abgesendete Foto-Entwürfe laufen nach 24 Stunden ab und werden samt Datei
automatisch bereinigt. Kunden-Uploads liegen nur unter `backend/uploads/` und
werden ausdrücklich nicht in Git aufgenommen.

### Dolibarr einmalig vorbereiten

1. In Dolibarr die REST-API und das Ticket-Modul aktivieren.
2. Dem API-Benutzer Lese- und Schreibrechte für **Dritte/Firmen** sowie
   **Tickets** geben. Produktrechte werden für den Anfrageablauf nicht benötigt.
3. Unter `/admin/einstellungen` Dolibarr aktivieren, die Basis-URL der
   Installation (ohne `/api/index.php`) und den API-Key eintragen.
4. Unter `/admin/dolibarr` auf **Verbindung prüfen** klicken.

Die Anfrageart wird ohne weitere Einrichtung passend gesetzt: Reparaturen als
`ISSUE`, Neubau/Beratung als `COM`, Umbau/Upgrade als `REQUEST` und Sonstiges als
`OTHER`; die Dringlichkeit ist zunächst `NORMAL`. Eigene Themengruppen können in
Dolibarr angelegt und deren Codes unter `/admin/einstellungen` je Anfrageart
zugeordnet werden. Sinnvolle Codes sind `REPARATUR`, `PC_BAU`, `PC_UPGRADE`,
`CONTROLLER`, `BERATUNG` und `SONSTIGES`. Leere Zuordnungen werden nicht an
Dolibarr gesendet und können den Sync daher nicht stören.

Für einen Link auf der Danke-Seite zusätzlich in Dolibarr unter **Ticket →
Einstellungen → Öffentliches Interface** die öffentliche Oberfläche aktivieren
und danach im Website-Admin **Öffentlichen Dolibarr-Ticketlink anzeigen**
einschalten. Die Website erzeugt ausschließlich den öffentlichen Link mit
Tracking-ID und Kunden-E-Mail; interne Karten-URLs oder Admin-Token werden nie
an Kunden ausgegeben. Wenn Dolibarr das öffentliche Interface nicht aktiviert
hat oder der Sync fehlschlägt, bleibt die Danke-Seite bewusst bei der lokalen
`ANF-…`-Referenz.

Die genaue Bezeichnung der Rechte kann je nach Dolibarr-Version/Sprache leicht
abweichen. Entscheidend ist, dass der API-Benutzer Dritte suchen und anlegen
sowie Tickets lesen und anlegen darf.

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
cat run/backend.pid
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
| Dolibarr meldet HTTP 403 | API-Benutzerrechte für **Dritte/Firmen** und **Tickets** prüfen; die genaue fehlgeschlagene Stufe steht unter `/admin/anfragen` |
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

Frontend-Entwicklungsserver:

```bash
cd frontend
yarn install --frozen-lockfile
echo 'REACT_APP_BACKEND_URL=http://localhost:8001' > .env
yarn start
```

Prüfungen:

```bash
cd backend && python -m pytest tests/test_unit_runtime.py tests/test_inquiry_dolibarr.py -q
cd frontend && CI=true yarn build
bash -n start.sh stop.sh update.sh
```

Die mutierenden API-Integrationstests sind absichtlich gesperrt. Sie laufen nur
mit `IT_TABELANDER_RUN_INTEGRATION=1`, einer lokalen URL und einer `DB_NAME`, die
`test` enthält. Dabei wird ein eigener temporärer Test-Admin verwendet; echte
Admin-Zugangsdaten und Produktionsdaten werden nicht benutzt.

## Dateien und Laufzeitdaten

```text
backend/             FastAPI, MongoDB-Zugriff und Tests
frontend/            React-App und festes Yarn-Lockfile
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

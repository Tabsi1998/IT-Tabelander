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

Das einzige Proxy-Ziel ist standardmäßig:

```text
http://127.0.0.1:8001
```

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
- PC-Builder-Texte
- Impressum und Datenschutz
- Admin-Login-E-Mail und Admin-Passwort

Gespeicherte API-Keys werden vom Backend niemals wieder an den Browser
ausgegeben. Der Admin zeigt nur an, ob ein Key vorhanden ist. Ein neuer Wert
ersetzt den bisherigen Key; vorhandene Keys können dort auch entfernt werden.

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

### Selten nötig: `deploy.config`

```bash
BACKEND_HOST="127.0.0.1"
BACKEND_PORT="8001"
PYTHON_BIN="python3"
STARTUP_TIMEOUT_SECONDS="30"
BACKEND_WORKERS="1"
```

Der Port ist eine Prozess-/Reverse-Proxy-Einstellung und kann deshalb nicht im
laufenden Online-Admin geändert werden. Mit dem Standardwert muss im Proxy nur
`127.0.0.1:8001` eingetragen werden.

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
| Port 8001 ist belegt | fremden Dienst stoppen oder `BACKEND_PORT` in `deploy.config` und im Reverse Proxy gemeinsam ändern |
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
cd backend && python -m pytest tests -q
cd frontend && CI=true yarn build
bash -n start.sh stop.sh update.sh
```

## Dateien und Laufzeitdaten

```text
backend/             FastAPI, MongoDB-Zugriff und Tests
frontend/            React-App und festes Yarn-Lockfile
deploy.config        interner Host/Port und Startparameter
start.sh             Bootstrap, Build, Start und Healthchecks
stop.sh              sicherer Prozess-Stopp per PID/Prozessgruppe
update.sh            Fast-Forward-Update mit vorbereitetem Build
run/                 PID, Lock und temporäre Deployment-Artefakte (ignoriert)
logs/                Backend-Log (ignoriert)
```

Secrets, `backend/.env`, venv, `node_modules`, Builds, Logs und PID-Dateien werden
nicht committed.

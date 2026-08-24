# IT-Tabelander

Premium Website & Web-App für **IT-Tabelander** (Tirol/Österreich): IT-Service &
Reparatur, individueller **PC Builder**, **PS5 Custom Controller Builder** mit
Live-Vorschau, Admin-CMS und Dolibarr-Anbindung.

**Stack:** React (CRA) · FastAPI · MongoDB · Tailwind · JWT-Auth

---

## Inhalt
- [Architektur](#architektur)
- [Schnellstart (Server)](#schnellstart-server)
- [Skripte](#skripte)
- [Konfiguration](#konfiguration)
- [Apache Reverse-Proxy](#apache-reverse-proxy)
- [Admin-Bereich](#admin-bereich)
- [Dolibarr / Google / GA4](#integrationen)
- [Lokale Entwicklung](#lokale-entwicklung)
- [Troubleshooting](#troubleshooting)

---

## Architektur

```
Browser ──HTTPS──▶ Apache (VHost it.tabelander.co.at)
                     ├── /            → statischer Frontend-Build  (Port 3000)
                     └── /api         → FastAPI Backend            (Port 8001)
                                          └── MongoDB (localhost:27017)
```

- Alle Backend-Routen sind mit **`/api`** geprefixt.
- Das Frontend spricht das Backend über `REACT_APP_BACKEND_URL` an (gleiche Origin).
- Admin-Login nutzt JWT + `Secure`-Cookies → **HTTPS ist Pflicht**.

```
.
├── backend/            FastAPI (app/ = Router, Models, Security, Seeds)
├── frontend/           React (src/pages, src/components, src/context)
├── start.sh            Backend + Frontend im Hintergrund starten
├── stop.sh             beide Dienste stoppen
├── update.sh           git pull + Deps + Build + Neustart
├── deploy.config       zentrale Deployment-Einstellungen
└── memory/             PRD & Test-Credentials
```

---

## Schnellstart (Server)

Einmalige Voraussetzungen auf Ubuntu:

```bash
# MongoDB 7
curl -fsSL https://pgp.mongodb.com/server-7.0.asc | sudo gpg -o /usr/share/keyrings/mongodb-server-7.0.gpg --dearmor
echo "deb [signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg] https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" | sudo tee /etc/apt/sources.list.d/mongodb-org-7.0.list
sudo apt update && sudo apt install -y mongodb-org && sudo systemctl enable --now mongod

# Node 20 + yarn, Python 3.11
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs python3 python3-venv python3-pip
sudo npm install -g yarn
```

App holen und Backend-`.env` anlegen:

```bash
git clone <REPO> /var/www/it-tabelander && cd /var/www/it-tabelander

# Backend-Secrets setzen (JWT_SECRET & Admin-Passwort unbedingt ändern!)
cat > backend/.env <<'EOF'
MONGO_URL="mongodb://localhost:27017"
DB_NAME="it_tabelander"
JWT_SECRET="HIER_LANGER_ZUFALLSWERT"        # z.B. openssl rand -hex 48
ADMIN_EMAIL="admin@it-tabelander.at"
ADMIN_PASSWORD="DEIN_SICHERES_PASSWORT"
DOLIBARR_ENABLED="false"
DOLIBARR_BASE_URL="https://erp.tabelander.co.at"
DOLIBARR_API_KEY=""
EOF

# Öffentliche URL in deploy.config eintragen (Standard: it.tabelander.co.at)
nano deploy.config
```

Starten:

```bash
./start.sh          # baut beim ersten Mal Frontend + venv automatisch
```

Danach → Apache-VHost einrichten ([siehe unten](#apache-reverse-proxy)) und
`https://it.tabelander.co.at` aufrufen.

---

## Skripte

| Skript        | Zweck                                                                 |
|---------------|-----------------------------------------------------------------------|
| `./start.sh`  | Startet Backend (uvicorn) **und** Frontend (statischer `serve`) im Hintergrund. Legt beim ersten Lauf venv an, installiert Deps, baut das Frontend. |
| `./stop.sh`   | Stoppt beide Dienste sauber (PID-Dateien + Fallback-pkill).           |
| `./update.sh` | `git pull` → Backend-Deps → Frontend neu bauen → Neustart.            |

- **Logs:** `logs/backend.log`, `logs/frontend.log`
- **PIDs:** `run/backend.pid`, `run/frontend.pid`
- Beide Ordner sind in `.gitignore`.

```bash
tail -f logs/backend.log        # Backend-Log live
./update.sh                     # nach jedem Git-Push
```

---

## Konfiguration

**`deploy.config`** (zentral, wird von allen Skripten gelesen):

```ini
PUBLIC_BACKEND_URL="https://it.tabelander.co.at"   # öffentliche URL = Origin für /api
BACKEND_HOST="127.0.0.1"
BACKEND_PORT="8001"
FRONTEND_PORT="3000"
BACKEND_WORKERS="2"
```

**`backend/.env`** (Secrets, **nicht** committen – ist in `.gitignore`):

| Variable            | Beschreibung                                  |
|---------------------|-----------------------------------------------|
| `MONGO_URL`         | `mongodb://localhost:27017`                   |
| `DB_NAME`           | z.B. `it_tabelander`                          |
| `JWT_SECRET`        | langer Zufallswert (`openssl rand -hex 48`)   |
| `ADMIN_EMAIL`       | Admin-Login-Adresse                           |
| `ADMIN_PASSWORD`    | Admin-Passwort (beim 1. Start angelegt)       |
| `DOLIBARR_*`        | optional, besser im Admin-Bereich pflegen     |

> Der Frontend-Build liest `REACT_APP_BACKEND_URL` (aus `PUBLIC_BACKEND_URL`).
> `start.sh`/`update.sh` schreiben dafür automatisch `frontend/.env.production`.

---

## Apache Reverse-Proxy

Dein bestehendes Dolibarr bleibt unberührt – dies ist ein eigener VHost.

```bash
sudo a2enmod proxy proxy_http rewrite ssl headers
```

`/etc/apache2/sites-available/it-tabelander.conf`:

```apache
<VirtualHost *:80>
    ServerName it.tabelander.co.at
    Redirect permanent / https://it.tabelander.co.at/
</VirtualHost>

<VirtualHost *:443>
    ServerName it.tabelander.co.at

    # Frontend (statischer serve auf Port 3000)
    ProxyPreserveHost On
    ProxyPass        /api  http://127.0.0.1:8001/api
    ProxyPassReverse /api  http://127.0.0.1:8001/api
    ProxyPass        /      http://127.0.0.1:3000/
    ProxyPassReverse /      http://127.0.0.1:3000/

    # SSL-Zeilen fügt Certbot automatisch ein
</VirtualHost>
```

```bash
sudo a2ensite it-tabelander && sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d it.tabelander.co.at
```

> **Alternative ohne zweiten Node-Prozess:** Statt das Frontend über Port 3000 zu
> proxen, kann Apache den Build-Ordner auch direkt ausliefern
> (`DocumentRoot /var/www/it-tabelander/frontend/build` + SPA-Rewrite auf
> `index.html`). Dann braucht `start.sh` nur das Backend zu starten.

**Autostart nach Reboot** (optional) – Cron des Deploy-Users:
```bash
crontab -e
# @reboot /var/www/it-tabelander/start.sh
```

---

## Admin-Bereich

- URL: `https://it.tabelander.co.at/admin`
- Login: `ADMIN_EMAIL` / `ADMIN_PASSWORD` aus `backend/.env`
- Verwaltbar: Reparaturen, Nachrichten, Leistungen, FAQs, Bewertungen,
  **PC Builder** (Kategorien/Komponenten/Bilder/Texte), **Controller Builder**
  (Modelle/Kategorien/Produkte/Varianten), Medien, Dolibarr-Sync, Einstellungen.

---

## Integrationen

Alle Keys werden **serverseitig** gespeichert und nie an den Browser gesendet.
Am besten im Admin unter **Einstellungen** pflegen:

- **Dolibarr:** aktivieren, Basis-URL + DOLAPIKEY eintragen → Produkt-Sync +
  Interessent/Ticket bei Anfragen. *(Hinweis: bis ein echter Key hinterlegt ist,
  läuft alles im Demo-Modus.)*
- **Google Places:** Place ID + API-Key → echte Google-Bewertungen.
- **Google Analytics 4:** Measurement ID → lädt erst nach Cookie-Zustimmung.

---

## Lokale Entwicklung

```bash
# Backend
cd backend && python3 -m venv venv && source venv/bin/activate
grep -vE 'emergentintegrations|litellm @' requirements.txt > /tmp/req.txt && pip install -r /tmp/req.txt
uvicorn server:app --reload --port 8001

# Frontend
cd frontend && yarn install
echo 'REACT_APP_BACKEND_URL=http://localhost:8001' > .env
yarn start        # http://localhost:3000

# Backend-Tests
cd backend && python -m pytest tests/ -q
```

---

## Troubleshooting

| Problem | Lösung |
|---|---|
| **Admin-Login klappt nicht** | HTTPS aktiv? `Secure`-Cookies brauchen TLS. Zertifikat via Certbot prüfen. |
| `{"db":false}` bei `/api/health` | MongoDB läuft nicht: `sudo systemctl start mongod`. |
| Frontend zeigt alte Version | `./update.sh` (Build neu erzeugen). |
| Port belegt | Ports in `deploy.config` ändern und Apache-Proxy anpassen. |
| Dienste-Status | `cat run/*.pid`, `tail -f logs/backend.log`. |

> Hinweis: `emergentintegrations` und die `litellm`-Zeile in
> `backend/requirements.txt` werden vom Code **nicht** verwendet; die Skripte
> überspringen sie automatisch beim Installieren.

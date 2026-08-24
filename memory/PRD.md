# IT-Tabelander – PRD

## Problem statement
Production-ready premium dark-tech website for IT-Tabelander (Austria/Tirol): IT service, PC/notebook/console/controller repair, custom gaming hardware, PS5 DualSense visual configurator, Gaming-PC configurator, admin CMS, Dolibarr ERP integration, SEO, GA4/consent. Target domain: it.tabelander.co.at, ERP: erp.tabelander.co.at.

## Tech stack
- Frontend: React (CRA) + Tailwind + framer-motion + lucide-react + react-router + react-helmet-async + sonner
- Backend: FastAPI (modular app/ package) + Motor/MongoDB
- Auth: JWT (bcrypt, httpOnly cookies + bearer fallback, brute-force lockout, roles prepared)

## User choices
- JWT admin auth. Dark mode default + light mode. Dolibarr/Google Places/GA4 in placeholder/DEMO mode until keys provided. Brand assets (logo/banner light+dark, CompTIA badges) supplied and used.

## Implemented (2026-06)
- Design system, dark/light theme (persisted, auto logo switch), responsive header/mobile menu/footer (discreet admin link)
- Homepage (hero, trust bar, services, configurator teasers, reviews, FAQ, CTA)
- Leistungen + SEO landing pages: /pc-reparatur, /notebook-reparatur, /pc-aufruestung, /konsolen-reparatur, /controller-reparatur, /gaming-pc (each own title/H1/meta/FAQ/JSON-LD/CTA)
- Über mich (CompTIA cert showcase + zoom), Bewertungen, Kontakt (validated form), Impressum, Datenschutz (cookie settings)
- Multi-step repair request (/reparatur) with image upload + ref number
- PS5 DualSense configurator: live SVG preview, categories/options, summary, save config
- Gaming-PC configurator: per-category product images, client-side compatibility checks, summary, save config
- Admin /admin (JWT): dashboard, repairs, contact messages, services CRUD, FAQs CRUD, reviews (curate/feature/visibility), configurator (categories/options + Dolibarr product linking), media manager (WebP optimize), Dolibarr sync page (demo), settings (company/SEO/GA4/social/legal)
- Backend: modular routers, Dolibarr integration layer (httpx, caching cache collection, timeouts, demo mode, sync logs), media upload w/ validation, SEO sitemap.xml + robots.txt, JSON-LD (Organization/LocalBusiness/Service/Breadcrumb)
- SEO: per-page Helmet meta, canonical, OG/Twitter, semantic structure
- Consent-gated GA4, honeypot + consent + server validation on forms
- Seed: real services/FAQs (no fake data), demo configurator data flagged is_demo

## Testing
- Backend: 69/69 pytest (/app/backend/tests). Frontend: 100% of core flows (iteration_2.json). All critical bugs fixed.

## Config / secrets (env, not hardcoded)
backend/.env: MONGO_URL, DB_NAME, JWT_SECRET, ADMIN_EMAIL/PASSWORD, DOLIBARR_ENABLED/BASE_URL/API_KEY/TIMEOUT, GOOGLE_PLACES_API_KEY, GOOGLE_PLACE_ID, GA_MEASUREMENT_ID.

## Deployment (Self-Host, 2026-06)
- `deploy.config` (zentral), `start.sh` / `stop.sh` / `update.sh` (Backend uvicorn :8001 + Frontend `serve` :3000 im Hintergrund, PID in `run/`, Logs in `logs/`). `update.sh` = git pull + Deps + Frontend-Build + Neustart.
- README.md komplett neu: Architektur, Ubuntu-Schnellstart, Apache-Reverse-Proxy (neben bestehendem Dolibarr), Env-Vars, Admin, Integrationen, Troubleshooting.
- `.gitignore` neu (venv/build/node_modules/run/logs/.env ausgeschlossen).
- Hinweis: `emergentintegrations` + `litellm`-Zeile in requirements.txt werden vom Code NICHT genutzt; Skripte filtern sie beim Install automatisch. HTTPS ist Pflicht (Secure-Cookies für Admin-Login).

## Update 2026-06 (2) – PC Builder, echte Controller-Fotos, Theme & Cleanup
- **Repo aufgeräumt**: alle alten PHP-Dateien + Verzeichnisse (`/private`, altes `/public`, `/docs`, `/tests`, Root-`*.php`, `sitemap.php`, `.htaccess`) entfernt. Nur noch React (`/frontend`) + FastAPI (`/backend`).
- **Rename „Gaming-PC" → „PC Builder"** überall: Header-Nav, Home-Hero/Teaser, Footer, PC-Builder-Seite (Hero/SEO), Admin-Sidebar + Tab, Admin-Header. Route `/gaming-pc-konfigurator` bleibt (SEO), nur Anzeige geändert.
- **PC Builder voll admin-verwaltbar**: AdminConfigurator hat jetzt vollständiges **Kategorie-CRUD** (Hinzufügen/Bearbeiten/Löschen: Name, Schlüssel, Beschreibung, Pflicht/Mehrfach, Sortierung, Aktiv) zusätzlich zum bestehenden Options-/Produkt-Management (Bilder, Preise, Specs, Dolibarr-Link). Kategorie-Löschung kaskadiert jetzt (Optionen werden mitgelöscht, 404 bei unbekannter ID).
- **Editierbare PC-Builder-Inhalte**: neue Settings-Felder `pc_builder_title / pc_builder_subtitle / pc_builder_note` (Admin-Panel „PC Builder – Inhalte"), live auf der PC-Builder-Seite gerendert. Public in `/api/settings`.
- **Echte Controller-Fotos** (Nutzer hat Erlaubnis für offizielle Sony/eXtremeRate-Bilder erteilt): ControllerCanvas nutzt jetzt reale transparente Controller-PNGs als Basis (DualSense front/back, Edge official front/back) statt abstraktem SVG. Live-Recoloring via `mix-blend-mode` (color/multiply/screen), maskiert durch die PNG-Silhouette. SVG bleibt Fallback. Controller-Modelle haben `preview_front`/`preview_back` (idempotent geseedet).
- **Varianten-IDs Fix (war kritisch)**: eingebettete Varianten bekommen jetzt stabile `id` (`{product_id}:{index}`) in `/api/builder/{model}`; Auswahl/Recolor/Preis/Speichern funktionieren. Client hat defensiven Fallback. `/builder/save` rundet Total auf 2 Dezimalstellen.
- **Theme/Light-Fix**: Admin-Login, Header, Footer, AdminLayout nutzen jetzt die theme-bewusste `Logo`-Komponente (kein hartes weißes Banner mehr → in Light sichtbar). Toaster folgt dem Theme. FinalCTA-Sekundärbutton auf dunkler Karte in Light lesbar (weiße Schrift). Theme persistiert in localStorage.
- **FAQ** „Gaming-PC-Konfigurator" → „PC Builder" (Seed + bestehendes DB-Dokument aktualisiert).

### Testing (2026-06-2)
- Backend: 69/69 pytest weiterhin grün; Kategorie-CRUD, Varianten-IDs, Settings-Felder per curl verifiziert.
- Frontend (Testing-Agent iteration_3 + iteration_4): Rename, Admin-Kategorie-CRUD, PC-Builder-Inhalte, echte Controller-Fotos, Varianten-Auswahl + Live-Tint + Speichern, Cascade-Delete, Light-Theme-CTA, FAQ – alle bestätigt (100% der getesteten Szenarien).

## Offen / Backlog (aktualisiert)
- Dolibarr echt anbinden: Nutzer-API-Key im Admin hinterlegen, Produkt-Sync + Ticket/Interessent Ende-zu-Ende validieren (aktuell Demo-Modus).
- Fotorealistische per-Teil-Recolorierung (Buttons/Sticks separat) braucht teil-spezifische transparente Layer-Assets; aktuell recoloriert die Shell die ganze Silhouette + Farbchips in Zusammenfassung.
- Google Places live reviews + GA4 Measurement ID (Nutzer-Werte fehlen noch).
- Optional: Admin-eigener Theme-Toggle; Consent-Banner-Offset über sticky CTAs; visueller Drag&Drop-Layer-Editor für Varianten (aktuell JSON).

## Update 2026-06 – Controller Builder Overhaul
- Neuer datengetriebener **PS5 Custom Controller Builder** (ersetzt alten PS5-Konfigurator) mit 2 getrennten Modellen: DualSense (BDM-010…060) + DualSense Edge.
- Backend-Collections: controllers, builder_categories, builder_products (+ eingebettete variants), builder_configs. Router /api/builder/* (public) + /api/admin/builder/* (CRUD). Versions-/Kompatibilitätsfilter, requires/excludes-Felder, Dolibarr-Preis-Verknüpfung.
- Frontend: ControllerCanvas (SVG-Master Vorder-/Rückseite, Live-Recolor per region_key, framer-motion Crossfade/Rotation, Overlay-PNG-Support je Variante mit x/y/scale/rotation/z/side), ControllerBuilder-Seite (Modell+Version-Wahl, Kategorien/Produkte/Farbvarianten, Live-Preis, Zusammenfassung, Speichern/Anfrage), Admin „Controller Builder" (Modelle/Kategorien/Produkte + Varianten-JSON, Dolibarr-Link, Kompatibilität).
- Dolibarr: Config jetzt aus Admin-Einstellungen (Key/URL/enabled) statt nur Env; Reparatur- UND Kontaktanfrage legen best-effort Interessent (thirdparty/prospect) + Ticket an (Demo-Modus ohne Key).
- Admin-Einstellungen: Dolibarr-Key/URL, Google-Places-Key, GA4/Place-ID, Logo Light/Dark (URL). Secrets nur admin-seitig, nie public.
- Theme-Fix: Admin nutzt jetzt Theme-Tokens (Light/Dark), kein erzwungenes Dark. Logo-Komponente nutzt Admin-Logos (light/dark) falls gesetzt. Theme in localStorage gespeichert.

## Deferred / bewusst offen (Controller Builder)
- Visueller Drag&Drop-Layer-Editor: aktuell numerische Layer-Felder (x/y/scale/rotation/z/side) via Varianten-JSON im Admin. 
- Fotorealistische transparente PNG/WebP-Layer aus eXtremeRate NICHT automatisch gescraped (Rechte/Feasibility) – System unterstützt Upload solcher Overlays je Variante; Standard sind hochwertige recolorbare SVG-Master.
- Variantenpflege im Admin derzeit als JSON-Editor (funktional, ohne Code); komfortablere Einzelfeld-UI + Medien-Picker als nächster Schritt.

## Backlog / next
- P1: Wire real Dolibarr API key -> live product sync + repair ticket creation; Google Places live reviews; GA4 id
- P1: Idempotent/versioned seed (currently seeds only when empty)
- P2: Admin FAQ/configurator drag-drop sort; per-option PC images; global search; email transport (SendGrid/Resend) for repair/contact notifications & password reset
- P2: Configurator compatibility rules (incompatible_with/depends_on) enforcement in UI; inline error when submitting incomplete PC config
- P2: Legal texts to be finalized by operator

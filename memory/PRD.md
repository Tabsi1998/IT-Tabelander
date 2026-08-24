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

## Backlog / next
- P1: Wire real Dolibarr API key -> live product sync + repair ticket creation; Google Places live reviews; GA4 id
- P1: Idempotent/versioned seed (currently seeds only when empty)
- P2: Admin FAQ/configurator drag-drop sort; per-option PC images; global search; email transport (SendGrid/Resend) for repair/contact notifications & password reset
- P2: Configurator compatibility rules (incompatible_with/depends_on) enforcement in UI; inline error when submitting incomplete PC config
- P2: Legal texts to be finalized by operator

# Test Credentials – IT-Tabelander

## Admin (Website /admin)
- Email: `admin@it-tabelander.at`
- Password: `ITtabelander2026!`
- Role: `super_admin`

Seeded idempotently on backend startup from `backend/.env` (ADMIN_EMAIL / ADMIN_PASSWORD).

## Auth endpoints
- POST /api/auth/login, GET /api/auth/me, POST /api/auth/logout, POST /api/auth/refresh

## Notes
- No third-party API keys configured yet (Dolibarr / Google Places / GA4 run in placeholder/demo mode).
- Configurator data (PS5 + PC) is seeded as clearly flagged DEMO data (`is_demo: true`).

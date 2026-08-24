# Auth Testing – IT-Tabelander

## Credentials (from backend/.env)
- Admin: `admin@it-tabelander.at` / `ITtabelander2026!` (role: super_admin)

## Endpoints (prefix /api/auth)
- POST /login {email,password} -> sets httpOnly cookies + returns {user, access_token}
- GET /me (cookie or Bearer) -> {user}
- POST /logout
- POST /refresh (refresh cookie)
- POST /forgot-password, POST /reset-password
- GET/POST/DELETE /users (super_admin)

## Quick test
```
curl -c c.txt -X POST http://localhost:8001/api/auth/login -H "Content-Type: application/json" -d '{"email":"admin@it-tabelander.at","password":"ITtabelander2026!"}'
curl -b c.txt http://localhost:8001/api/auth/me
```
Notes: bcrypt hash ($2b$), brute-force lockout after 5 fails (15 min), idempotent admin seed.

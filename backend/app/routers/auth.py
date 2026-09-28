from datetime import timedelta, timezone

from bson import ObjectId
from fastapi import APIRouter, Depends, HTTPException, Request, Response

from ..db import get_db, now_utc, serialize, to_oid
from ..models import AccountUpdate, LoginInput
from ..security import (clear_auth_cookies, create_access_token,
                        create_refresh_token, get_current_user, hash_password,
                        require_admin, set_access_cookie, set_auth_cookies,
                        verify_password)

router = APIRouter(prefix="/api/auth", tags=["auth"])

# Five wrong passwords from one address lock that address for this e-mail.
# The e-mail itself only locks after many more failures, so a stranger cannot
# lock the owner out with a handful of attempts (#32).
MAX_ATTEMPTS = 5
MAX_ATTEMPTS_PER_EMAIL = 20
LOCK_MINUTES = 15


def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    peer = request.client.host if request.client else "unknown"
    if xff and peer in ("127.0.0.1", "::1"):
        return xff.split(",")[0].strip()
    return peer


def _as_aware(dt):
    if dt is None:
        return None
    return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)


async def _check_lockout(identifier: str):
    rec = await get_db().login_attempts.find_one({"identifier": identifier})
    locked_until = _as_aware((rec or {}).get("locked_until"))
    if locked_until and locked_until > now_utc():
        raise HTTPException(status_code=429,
                            detail="Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.")


async def _register_failure(identifier: str, limit: int):
    """Count a failure; old failures and expired locks start a fresh count."""
    db = get_db()
    now = now_utc()
    rec = await db.login_attempts.find_one({"identifier": identifier}) or {}
    last_failure = _as_aware(rec.get("updated_at"))
    locked_until = _as_aware(rec.get("locked_until"))
    window_open = last_failure is not None and last_failure > now - timedelta(minutes=LOCK_MINUTES)
    lock_expired = locked_until is not None and locked_until <= now
    count = (rec.get("count", 0) if window_open and not lock_expired else 0) + 1
    update = {"$set": {"count": count, "updated_at": now}}
    if count >= limit:
        update["$set"]["locked_until"] = now + timedelta(minutes=LOCK_MINUTES)
    else:
        update["$unset"] = {"locked_until": ""}
    await db.login_attempts.update_one({"identifier": identifier}, update, upsert=True)


@router.post("/login")
async def login(payload: LoginInput, request: Request, response: Response):
    db = get_db()
    email = payload.email.lower().strip()
    identifier = f"{_client_ip(request)}:{email}"
    email_id = f"email:{email}"
    await _check_lockout(identifier)
    await _check_lockout(email_id)
    user = await db.users.find_one({"email": email})
    if not user or not verify_password(payload.password, user["password_hash"]):
        await _register_failure(identifier, MAX_ATTEMPTS)
        await _register_failure(email_id, MAX_ATTEMPTS_PER_EMAIL)
        raise HTTPException(status_code=401, detail="E-Mail oder Passwort ist falsch")
    await db.login_attempts.delete_many({"identifier": {"$in": [identifier, email_id]}})
    uid = str(user["_id"])
    set_auth_cookies(
        response,
        create_access_token(uid, email, user.get("role", "staff")),
        create_refresh_token(uid),
    )
    # The token stays in the httpOnly cookie; scripts never get it (#32).
    return {"user": serialize(user)}


@router.post("/logout")
async def logout(response: Response, _: dict = Depends(get_current_user)):
    clear_auth_cookies(response)
    return {"ok": True}


@router.get("/me")
async def me(user: dict = Depends(get_current_user)):
    return {"user": serialize(user)}


@router.put("/account")
async def update_account(payload: AccountUpdate,
                         current_user: dict = Depends(get_current_user)):
    db = get_db()
    user_id = ObjectId(current_user["_id"])
    user = await db.users.find_one({"_id": user_id})
    if not user or not verify_password(payload.current_password, user["password_hash"]):
        raise HTTPException(status_code=400, detail="Aktuelles Passwort ist falsch")

    updates = {}
    if payload.email:
        email = str(payload.email).lower().strip()
        owner = await db.users.find_one({"email": email})
        if owner and owner["_id"] != user_id:
            raise HTTPException(status_code=400, detail="E-Mail bereits vergeben")
        updates["email"] = email
    if payload.new_password:
        updates["password_hash"] = hash_password(payload.new_password)
    if not updates:
        raise HTTPException(status_code=400, detail="Keine Änderung angegeben")

    # The login lives in MongoDB only. backend/.env keeps the bootstrap values
    # for ./start.sh --reset-admin and is no longer rewritten from here (#32).
    updates["updated_at"] = now_utc()
    await db.users.update_one({"_id": user_id}, {"$set": updates})
    updated = await db.users.find_one({"_id": user_id})
    return {"user": serialize(updated)}


@router.post("/refresh")
async def refresh_token(request: Request, response: Response):
    import jwt
    from ..security import _secret, JWT_ALGORITHM
    token = request.cookies.get("refresh_token")
    if not token:
        raise HTTPException(status_code=401, detail="Kein Refresh-Token")
    try:
        payload = jwt.decode(token, _secret(), algorithms=[JWT_ALGORITHM])
        if payload.get("type") != "refresh":
            raise HTTPException(status_code=401, detail="Ungültiger Token")
        user = await get_db().users.find_one({"_id": to_oid(payload["sub"])})
        if not user:
            raise HTTPException(status_code=401, detail="Benutzer nicht gefunden")
        set_access_cookie(
            response,
            create_access_token(str(user["_id"]), user["email"], user.get("role", "staff")),
        )
        return {"ok": True}
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=401, detail="Ungültiger Token")

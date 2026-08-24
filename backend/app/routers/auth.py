import secrets
from datetime import timedelta, timezone

from bson import ObjectId
from fastapi import APIRouter, Depends, HTTPException, Request, Response

from ..db import get_db, now_utc, serialize
from ..models import (ForgotPasswordInput, LoginInput, ResetPasswordInput,
                      UserCreate)
from ..security import (clear_auth_cookies, create_access_token,
                        create_refresh_token, get_current_user, hash_password,
                        require_admin, set_auth_cookies, verify_password)

router = APIRouter(prefix="/api/auth", tags=["auth"])

MAX_ATTEMPTS = 5
LOCK_MINUTES = 15


def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


def _as_aware(dt):
    if dt is None:
        return None
    return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)


async def _check_lockout(identifier: str):
    db = get_db()
    rec = await db.login_attempts.find_one({"identifier": identifier})
    if rec and rec.get("count", 0) >= MAX_ATTEMPTS:
        locked_until = _as_aware(rec.get("locked_until"))
        if locked_until and locked_until > now_utc():
            raise HTTPException(status_code=429,
                                detail="Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.")


async def _register_failure(identifier: str):
    db = get_db()
    rec = await db.login_attempts.find_one({"identifier": identifier})
    count = (rec.get("count", 0) if rec else 0) + 1
    update = {"count": count, "updated_at": now_utc()}
    if count >= MAX_ATTEMPTS:
        update["locked_until"] = now_utc() + timedelta(minutes=LOCK_MINUTES)
    await db.login_attempts.update_one({"identifier": identifier},
                                       {"$set": update}, upsert=True)


@router.post("/login")
async def login(payload: LoginInput, request: Request, response: Response):
    db = get_db()
    email = payload.email.lower().strip()
    ip = _client_ip(request)
    identifier = f"{ip}:{email}"
    email_id = f"email:{email}"
    await _check_lockout(identifier)
    await _check_lockout(email_id)
    user = await db.users.find_one({"email": email})
    if not user or not verify_password(payload.password, user["password_hash"]):
        await _register_failure(identifier)
        await _register_failure(email_id)
        raise HTTPException(status_code=401, detail="E-Mail oder Passwort ist falsch")
    await db.login_attempts.delete_one({"identifier": identifier})
    await db.login_attempts.delete_one({"identifier": email_id})
    uid = str(user["_id"])
    access = create_access_token(uid, email, user.get("role", "staff"))
    refresh = create_refresh_token(uid)
    set_auth_cookies(response, access, refresh)
    return {"user": serialize(user), "access_token": access}


@router.post("/logout")
async def logout(response: Response, _: dict = Depends(get_current_user)):
    clear_auth_cookies(response)
    return {"ok": True}


@router.get("/me")
async def me(user: dict = Depends(get_current_user)):
    return {"user": serialize(user)}


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
        user = await get_db().users.find_one({"_id": ObjectId(payload["sub"])})
        if not user:
            raise HTTPException(status_code=401, detail="Benutzer nicht gefunden")
        access = create_access_token(str(user["_id"]), user["email"], user.get("role", "staff"))
        response.set_cookie("access_token", access, httponly=True, secure=True,
                            samesite="none", max_age=3600, path="/")
        return {"access_token": access}
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=401, detail="Ungültiger Token")


@router.post("/forgot-password")
async def forgot_password(payload: ForgotPasswordInput):
    db = get_db()
    user = await db.users.find_one({"email": payload.email.lower().strip()})
    if user:
        token = secrets.token_urlsafe(32)
        await db.password_reset_tokens.insert_one({
            "user_id": user["_id"], "token": token, "used": False,
            "expires_at": now_utc() + timedelta(hours=1), "created_at": now_utc(),
        })
        print(f"[PASSWORD RESET] Link-Token für {user['email']}: {token}")
    return {"ok": True, "message": "Falls die E-Mail existiert, wurde ein Reset-Link erstellt."}


@router.post("/reset-password")
async def reset_password(payload: ResetPasswordInput):
    db = get_db()
    rec = await db.password_reset_tokens.find_one({"token": payload.token, "used": False})
    if not rec or rec["expires_at"] < now_utc():
        raise HTTPException(status_code=400, detail="Ungültiger oder abgelaufener Token")
    await db.users.update_one({"_id": rec["user_id"]},
                              {"$set": {"password_hash": hash_password(payload.password)}})
    await db.password_reset_tokens.update_one({"_id": rec["_id"]}, {"$set": {"used": True}})
    return {"ok": True}


# ---- admin user management ----
@router.get("/users")
async def list_users(_: dict = Depends(require_admin)):
    docs = await get_db().users.find().to_list(200)
    return [serialize(d) for d in docs]


@router.post("/users")
async def create_user(payload: UserCreate, admin: dict = Depends(require_admin)):
    if admin.get("role") != "super_admin":
        raise HTTPException(status_code=403, detail="Nur Super-Admins dürfen Benutzer anlegen")
    db = get_db()
    if await db.users.find_one({"email": payload.email.lower().strip()}):
        raise HTTPException(status_code=400, detail="E-Mail bereits vergeben")
    doc = {"email": payload.email.lower().strip(), "password_hash": hash_password(payload.password),
           "name": payload.name, "role": payload.role, "created_at": now_utc()}
    res = await db.users.insert_one(doc)
    doc["_id"] = res.inserted_id
    return serialize(doc)


@router.delete("/users/{user_id}")
async def delete_user(user_id: str, admin: dict = Depends(require_admin)):
    if admin.get("role") != "super_admin":
        raise HTTPException(status_code=403, detail="Nur Super-Admins dürfen Benutzer löschen")
    if str(admin["_id"]) == user_id:
        raise HTTPException(status_code=400, detail="Eigenen Account nicht löschen")
    await get_db().users.delete_one({"_id": ObjectId(user_id)})
    return {"ok": True}

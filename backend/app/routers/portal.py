import httpx
from fastapi import APIRouter, BackgroundTasks, HTTPException, Request, Response
from pydantic import BaseModel, Field, field_validator, model_validator

from .. import portal
from ..db import get_db

router = APIRouter(prefix="/api/portal", tags=["portal"])

COOKIE_PATH = "/api/portal"
NOT_ON = "Der Kundenbereich ist noch nicht eingeschaltet."
SIGNED_OUT = "Bitte melde dich an."
AWAY = "Der Kundenbereich ist gerade nicht erreichbar. Bitte versuch es später noch einmal."


class LoginInput(BaseModel):
    email: str = Field(max_length=254)

    @field_validator("email")
    @classmethod
    def valid_email(cls, value):
        return portal.clean_email(value)


class SessionInput(BaseModel):
    token: str = Field(pattern=r"^[A-Za-z0-9_-]{20,100}$")


class AnswerInput(BaseModel):
    """Yes with the name as signature (and whether to start at once), or no with a reason (#65)."""
    accept: bool
    name: str = Field(default="", max_length=128)
    reason: str = Field(default="", max_length=1000)
    start_now: bool = False

    @field_validator("name", "reason", mode="before")
    @classmethod
    def strip_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @model_validator(mode="after")
    def name_for_a_yes(self):
        if self.accept and len(self.name) < 2:
            raise ValueError("Bitte gib zur Bestätigung deinen Namen an.")
        return self


async def _switched_on() -> None:
    settings = await get_db().settings.find_one({"_id": "site"}) or {}
    if not settings.get("portal_enabled"):
        raise HTTPException(status_code=404, detail=NOT_ON)


async def _session(request: Request) -> dict:
    await _switched_on()
    session = await portal.current(request.cookies.get(portal.COOKIE))
    if not session:
        raise HTTPException(status_code=401, detail=SIGNED_OUT)
    return session


@router.post("/login")
async def login(payload: LoginInput, background: BackgroundTasks):
    """Always the same answer; whether the address is known stays unsaid (#63)."""
    await _switched_on()
    background.add_task(portal.send_link, payload.email)
    return {"ok": True}


@router.post("/session")
async def open_session(payload: SessionInput, response: Response):
    """The link from the mail, sent by the sign-in page, becomes the session cookie."""
    await _switched_on()
    try:
        session_token, session = await portal.redeem(payload.token)
    except portal.PortalError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from None
    response.set_cookie(portal.COOKIE, session_token, httponly=True, secure=True, samesite="lax",
                        path=COOKIE_PATH, max_age=int(portal.SESSION_VALID.total_seconds()))
    return portal.public(session)


@router.get("/me")
async def me(request: Request):
    return portal.public(await _session(request))


@router.post("/logout")
async def logout(request: Request, response: Response):
    await portal.end(request.cookies.get(portal.COOKIE))
    response.delete_cookie(portal.COOKIE, path=COOKIE_PATH)
    return {"ok": True}


def _problem(exc: Exception) -> HTTPException:
    if isinstance(exc, portal.NotFound):
        return HTTPException(status_code=404, detail=str(exc))
    if isinstance(exc, portal.PortalError):
        return HTTPException(status_code=409, detail=str(exc))
    return HTTPException(status_code=503, detail=AWAY)


def _pdf(name: str, content: bytes) -> Response:
    return Response(content=content, media_type="application/pdf", headers={
        "Content-Disposition": f'inline; filename="{name}"', "Cache-Control": "private, no-store"})


@router.get("/offers/{offer_id}")
async def offer(offer_id: int, request: Request):
    try:
        return await portal.offer(await _session(request), offer_id)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/offers/{offer_id}/pdf")
async def offer_pdf(offer_id: int, request: Request):
    try:
        return _pdf(*await portal.document(await _session(request), "proposals", offer_id))
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.post("/offers/{offer_id}/answer")
async def answer_offer(offer_id: int, payload: AnswerInput, request: Request):
    session = await _session(request)
    try:
        return await portal.answer(session, offer_id, accept=payload.accept, name=payload.name, reason=payload.reason,
                                   start_now=payload.start_now, ip=request.client.host if request.client else "")
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/invoices/{invoice_id}")
async def invoice(invoice_id: int, request: Request):
    try:
        return await portal.invoice(await _session(request), invoice_id)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/invoices/{invoice_id}/pdf")
async def invoice_pdf(invoice_id: int, request: Request):
    try:
        return _pdf(*await portal.document(await _session(request), "invoices", invoice_id))
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/overview")
async def overview(request: Request):
    session = await _session(request)
    try:
        return await portal.overview(session)
    except portal.PortalError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from None
    except httpx.HTTPError:
        raise HTTPException(status_code=503, detail=AWAY) from None

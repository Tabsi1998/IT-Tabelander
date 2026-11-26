import httpx
from fastapi import APIRouter, BackgroundTasks, HTTPException, Path, Request, Response
from pydantic import BaseModel, Field, field_validator, model_validator

from .. import portal, portal_inquiries
from ..db import get_db

router = APIRouter(prefix="/api/portal", tags=["portal"])

COOKIE_PATH = "/api/portal"
NOT_ON = "Der Kundenbereich ist noch nicht eingeschaltet."
SIGNED_OUT = "Bitte melde dich an."
AWAY = "Der Kundenbereich ist gerade nicht erreichbar. Bitte versuch es später noch einmal."


class LoginInput(BaseModel):
    email: str = Field(max_length=254)
    # The page to open after signing in, e.g. the inquiry from a Dolibarr mail (#122).
    next: str | None = Field(default=None, max_length=200)

    @field_validator("email")
    @classmethod
    def valid_email(cls, value):
        return portal.clean_email(value)

    @field_validator("next")
    @classmethod
    def only_the_customer_area(cls, value):
        return value if value and portal.NEXT_PAGE.fullmatch(value) else None


class SessionInput(BaseModel):
    token: str = Field(pattern=r"^[A-Za-z0-9_-]{20,100}$")


class MessageInput(BaseModel):
    """An answer to an inquiry (#122)."""
    message: str = Field(max_length=5000)

    @field_validator("message", mode="before")
    @classmethod
    def strip_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @field_validator("message")
    @classmethod
    def something_to_say(cls, value):
        if len(value) < 2:
            raise ValueError("Bitte schreib kurz, was du mitteilen möchtest.")
        return value


TicketId = Path(ge=1, le=2_147_483_647)


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
    background.add_task(portal.send_link, payload.email, payload.next)
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


@router.get("/documents/{kind}/{object_id}")
async def document(kind: str, object_id: int, request: Request):
    """Order confirmations, delivery notes, work reports and contracts (#100)."""
    try:
        return await portal.document_detail(await _session(request), kind, object_id)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/documents/{kind}/{object_id}/pdf")
async def document_pdf(kind: str, object_id: int, request: Request):
    session = await _session(request)
    if kind not in portal.DOCUMENT_KINDS:  # offers and invoices have their own address
        raise HTTPException(status_code=404, detail="Das gibt es hier nicht.")
    try:
        return _pdf(*await portal.document(session, kind, object_id))
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/inquiries/track/{track_id}")
async def inquiry_by_track(track_id: str, request: Request):
    """The link in Dolibarr's mails (/status/view.php?track_id=…) leads here (#122)."""
    try:
        return await portal_inquiries.find_by_track(await _session(request), track_id)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/inquiries/{ticket_id}")
async def inquiry(request: Request, ticket_id: int = TicketId):
    try:
        return await portal_inquiries.detail(await _session(request), ticket_id)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.post("/inquiries/{ticket_id}/messages")
async def answer_inquiry(payload: MessageInput, request: Request, ticket_id: int = TicketId):
    session = await _session(request)
    try:
        return await portal_inquiries.reply(session, ticket_id, payload.message)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.post("/inquiries/{ticket_id}/close")
async def close_inquiry(request: Request, ticket_id: int = TicketId):
    """"Hat sich erledigt" (#122)."""
    session = await _session(request)
    try:
        return await portal_inquiries.close(session, ticket_id)
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

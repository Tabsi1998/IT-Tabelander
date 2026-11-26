from typing import Literal, Optional
from urllib.parse import quote

import httpx
from fastapi import APIRouter, BackgroundTasks, File, HTTPException, Path, Request, Response, UploadFile
from pydantic import BaseModel, Field, field_validator, model_validator

from .. import portal, portal_docs, portal_files, portal_inquiries
from ..db import get_db
from ..models import TOPIC_MAX

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
    """An answer to an inquiry (#122), with the files uploaded for it (#123)."""
    message: str = Field(default="", max_length=5000)
    files: list[str] = Field(default_factory=list, max_length=5)

    @field_validator("message", mode="before")
    @classmethod
    def strip_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @field_validator("files")
    @classmethod
    def only_uploaded_files(cls, value):
        if any(not portal_files.UPLOADED.fullmatch(name) for name in value):
            raise ValueError("Unbekannte Datei.")
        return list(dict.fromkeys(value))

    @model_validator(mode="after")
    def something_to_say(self):
        if len(self.message) < 2 and not self.files:
            raise ValueError("Bitte schreib kurz, was du mitteilen möchtest.")
        return self


class NewInquiryInput(BaseModel):
    """A new inquiry in the customer area (#123): what the form on the website
    asks, without the contact details - those are the customer's in Dolibarr."""
    request_type: Literal["repair", "pc_build", "pc_upgrade", "controller_custom", "consulting", "other"]
    customer_id: Optional[int] = None
    device_type: str = Field(default="", max_length=120)
    device_source: Literal["new_controller", "send_in", "unsure", ""] = ""
    manufacturer: str = Field(default="", max_length=160)
    model: str = Field(default="", max_length=160)
    topic: str = Field(default="", max_length=TOPIC_MAX)
    description: str = Field(min_length=10, max_length=10000)
    budget: str = Field(default="", max_length=120)
    timeframe: str = Field(default="", max_length=120)

    @field_validator("device_type", "manufacturer", "model", "topic", "description", "budget", "timeframe",
                     mode="before")
    @classmethod
    def strip_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @model_validator(mode="after")
    def details_for_the_kind(self):
        if self.request_type in ("repair", "pc_upgrade", "other") and not self.device_type:
            raise ValueError("Bitte wähle das Gerät.")
        if self.request_type == "controller_custom" and not (self.device_source and self.manufacturer and self.model):
            raise ValueError("Für einen Controller-Umbau brauche ich Herkunft, Hersteller und Modell.")
        return self


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


class DocumentNameInput(BaseModel):
    """Who confirms or signs a document (#187): the name, the Stand shown, the customer."""
    name: str = Field(max_length=128)
    version: str = Field(default="", pattern=r"^(\d{4}-\d{2}-\d{2})?$")
    customer_id: Optional[int] = None

    @field_validator("name", mode="before")
    @classmethod
    def strip_name(cls, value):
        return " ".join(value.split()) if isinstance(value, str) else value

    @field_validator("name")
    @classmethod
    def a_real_name(cls, value):
        if len(value) < 2 or "<" in value or ">" in value:
            raise ValueError("Bitte gib zur Bestätigung deinen Namen an.")
        return value


class ConfirmInput(DocumentNameInput):
    """AGB or Nutzungsbedingungen: the name and the tick (#187)."""
    accepted: bool

    @field_validator("accepted")
    @classmethod
    def ticked(cls, value):
        if value is not True:
            raise ValueError("Bitte bestätige, dass du die Unterlage gelesen hast.")
        return value


class SignInput(DocumentNameInput):
    """AVV or Fernwartung: the name and the signature drawn on the screen,
    as PNG in base64 without "data:" and "=" (Dolibarr's WAF, #187)."""
    png: str = Field(min_length=60, max_length=portal_docs.PNG_MAX)

    @field_validator("png")
    @classmethod
    def plain_base64(cls, value):
        if not portal_docs.PNG_BASE64.fullmatch(value):
            raise ValueError("Die Unterschrift ist nicht angekommen. Bitte unterschreib noch einmal.")
        return value


DocumentSlug = Path(pattern=r"^[a-z0-9-]{1,64}$")


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


@router.post("/inquiries")
async def new_inquiry(payload: NewInquiryInput, request: Request):
    """A new inquiry, straight to Dolibarr on the customer's own customer (#123)."""
    session = await _session(request)
    data = payload.model_dump(exclude={"customer_id"})
    try:
        return await portal_inquiries.create(session, data, payload.customer_id)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.post("/inquiries/{ticket_id}/files")
async def add_file(request: Request, file: UploadFile = File(...), ticket_id: int = TicketId):
    """A photo or PDF onto the customer's open ticket; nothing stays here (#123)."""
    session = await _session(request)
    raw = await file.read(portal_files.IMAGE_MAX + 1)
    try:
        return await portal_files.upload(session, ticket_id, raw, file.filename or "")
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/inquiries/{ticket_id}/files/{name}")
async def get_file(name: str, request: Request, ticket_id: int = TicketId):
    try:
        name, media_type, content = await portal_files.download(await _session(request), ticket_id, name)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None
    # Photos and PDFs open in the browser; anything else only as a download.
    shown = media_type.startswith("image/") or media_type == "application/pdf"
    return Response(content=content, media_type=media_type, headers={
        "Content-Disposition": f"{'inline' if shown else 'attachment'}; filename*=UTF-8''{quote(name, safe='')}",
        "Cache-Control": "private, max-age=300", "X-Content-Type-Options": "nosniff"})


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
        return await portal_inquiries.reply(session, ticket_id, payload.message, payload.files)
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


@router.get("/unterlagen")
async def documents_list(request: Request):
    """AGB, AVV and the other documents of the customer, with their state (#187)."""
    session = await _session(request)
    try:
        return await portal_docs.documents(session)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.get("/unterlagen/{slug}/pdf")
async def documents_pdf(request: Request, slug: str = DocumentSlug, kunde: Optional[int] = None):
    session = await _session(request)
    try:
        name, media_type, content = await portal_docs.pdf(session, slug, kunde)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None
    return Response(content=content, media_type=media_type, headers={
        "Content-Disposition": f'inline; filename="{name}"', "Cache-Control": "private, no-store",
        "X-Content-Type-Options": "nosniff"})


@router.post("/unterlagen/{slug}/bestaetigen")
async def documents_confirm(payload: ConfirmInput, request: Request, slug: str = DocumentSlug):
    session = await _session(request)
    try:
        return await portal_docs.confirm(session, slug, name=payload.name, version=payload.version,
                                         ip=request.client.host if request.client else "", customer_id=payload.customer_id)
    except (portal.PortalError, httpx.HTTPError) as exc:
        raise _problem(exc) from None


@router.post("/unterlagen/{slug}/unterschreiben")
async def documents_sign(payload: SignInput, request: Request, slug: str = DocumentSlug):
    session = await _session(request)
    try:
        return await portal_docs.sign(session, slug, name=payload.name, png=payload.png, version=payload.version,
                                      ip=request.client.host if request.client else "", customer_id=payload.customer_id)
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

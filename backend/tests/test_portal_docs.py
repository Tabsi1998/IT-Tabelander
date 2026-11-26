"""Documents in the customer area (#187): list, PDF, confirm and sign, through the module's REST API."""
import asyncio
import base64
import json

import httpx
import pytest
from pydantic import ValidationError

import server
from app import dolibarr, mailer, portal, portal_docs
from app.routers.portal import ConfirmInput, SignInput

REAL_CLIENT = httpx.AsyncClient
CFG = {"enabled": True, "base": "https://erp.example.com", "api_key": "key", "timeout": 5,
       "site_base": "https://it.example.com"}
PNG = base64.b64encode(b"\x89PNG\r\n\x1a\n" + b"\x00" * 300).decode().rstrip("=")
ROW = {"slug": "agb-unternehmer", "title": "AGB für Unternehmer", "label": "AGB akzeptiert", "stand": "2026-11-20",
       "state": "sent", "action": "accept", "required": True, "can_confirm": True, "can_sign": False,
       "waiting_countersign": False, "done": None, "pdf": "general", "files": {"generated": False, "signed": False},
       "note": "intern", "fk_user": 3}


def _session(*ids):
    return {"email": "max@example.com", "customers": [{"id": value, "name": f"Kunde {value}"} for value in ids]}


def _with_dolibarr(monkeypatch, handler):
    calls = []

    def recording(request):
        calls.append(request)
        return handler(request)

    async def config():
        return CFG

    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(portal_docs.httpx, "AsyncClient",
                        lambda **kwargs: REAL_CLIENT(transport=httpx.MockTransport(recording), **kwargs))
    return calls


def _quiet_owner(monkeypatch):
    told = []

    async def notify(session, item, **kwargs):
        told.append((item, kwargs))

    monkeypatch.setattr(portal_docs, "_notify_owner", notify)
    return told


# ------------------------------------------------------------------ ownership

def test_the_list_asks_only_for_the_customers_of_the_session_and_keeps_internals_out(monkeypatch):
    def handler(request):
        if request.url.path.endswith("/kunden/7/unterlagen"):
            return httpx.Response(200, json=[ROW, {**ROW, "slug": "../conf"}])
        if request.url.path.endswith("/kunden/9/unterlagen"):
            return httpx.Response(403, json={})  # no right for this one: the rest still shows
        return httpx.Response(500, json={})

    calls = _with_dolibarr(monkeypatch, handler)
    items = asyncio.run(portal_docs.documents(_session(7, 9)))
    assert [call.url.path for call in calls] == ["/api/index.php/ittabelanderpdf/kunden/7/unterlagen",
                                                 "/api/index.php/ittabelanderpdf/kunden/9/unterlagen"]
    [item] = items
    assert item["slug"] == "agb-unternehmer" and item["customer_id"] == 7 and item["customer"] == "Kunde 7"
    assert item["can_confirm"] is True and item["has_pdf"] is True and item["signed_pdf"] is False
    assert "note" not in item and "fk_user" not in item and "label" not in item


def test_a_customer_not_of_the_session_is_not_asked_for(monkeypatch):
    calls = _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={}))
    for kwargs in ({"customer_id": 8}, {}):
        with pytest.raises(portal.NotFound):
            asyncio.run(portal_docs.pdf(_session(7, 9), "agb-unternehmer", **kwargs))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal_docs.confirm(_session(7), "agb-unternehmer", name="Max", version="", customer_id=8))
    assert calls == []


@pytest.mark.parametrize("slug", ["../../conf", "AGB", "agb unternehmer", "a" * 65, ""])
def test_a_slug_that_is_no_document_name_never_reaches_dolibarr(monkeypatch, slug):
    calls = _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={}))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal_docs.pdf(_session(7), slug))
    assert calls == []


def test_the_pdf_comes_with_its_type_and_a_clean_name(monkeypatch):
    content = b"%PDF-1.4\n%%EOF\n"
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={
        "filename": 'avv "x".pdf', "content-type": "application/pdf", "encoding": "base64", "content": base64.b64encode(content).decode()}))
    name, media, body = asyncio.run(portal_docs.pdf(_session(7), "auftragsverarbeitung"))
    assert (name, media, body) == ("avv__x_.pdf", "application/pdf", content)


def test_a_file_of_another_type_is_not_handed_out(monkeypatch):
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={
        "filename": "x.html", "content-type": "text/html", "content": base64.b64encode(b"<script>").decode()}))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal_docs.pdf(_session(7), "agb-unternehmer"))


def test_a_document_dolibarr_does_not_have_for_this_customer_is_not_there(monkeypatch):
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(404, json={"error": {"code": 404, "message": "Not found"}}))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal_docs.pdf(_session(7), "nutzungsbedingungen"))


# ------------------------------------------------------------------ payload

def test_confirming_sends_the_proof_and_tells_the_owner(monkeypatch):
    sent = []

    def handler(request):
        sent.append((request.url.path, json.loads(request.content)))
        return httpx.Response(200, json={**ROW, "state": "done", "can_confirm": False, "done": {"date": "2026-11-26", "source": "portal"}})

    _with_dolibarr(monkeypatch, handler)
    told = _quiet_owner(monkeypatch)
    item = asyncio.run(portal_docs.confirm(_session(7), "agb-unternehmer", name="Max Muster", version="2026-11-20", ip="203.0.113.7"))
    [(path, body)] = sent
    assert path == "/api/index.php/ittabelanderpdf/kunden/7/unterlagen/agb-unternehmer/bestaetigen"
    # The e-mail is the session's, never one the page sends.
    assert body == {"name": "Max Muster", "email": "max@example.com", "ip": "203.0.113.7", "version": "2026-11-20"}
    assert item["state"] == "done" and item["done_at"] == "2026-11-26"
    assert told and told[0][1]["what"] == "confirm"


def test_signing_sends_the_image_as_it_came(monkeypatch):
    sent = []

    def handler(request):
        sent.append(json.loads(request.content))
        return httpx.Response(200, json={**ROW, "slug": "auftragsverarbeitung", "action": "sign", "state": "signing",
                                         "can_confirm": False, "waiting_countersign": True, "pdf": "generated"})

    _with_dolibarr(monkeypatch, handler)
    told = _quiet_owner(monkeypatch)
    item = asyncio.run(portal_docs.sign(_session(7), "auftragsverarbeitung", name="Max Muster", png=PNG, version="2026-11-20"))
    assert sent[0]["png"] == PNG and sent[0]["email"] == "max@example.com"
    assert item["waiting_countersign"] is True and told[0][1]["what"] == "sign"


@pytest.mark.parametrize("message, text", [("Conflict: Document changed", "neuen Stand"), ("Conflict: Not to confirm", "schon erledigt")])
def test_a_refusal_of_dolibarr_says_what_to_do(monkeypatch, message, text):
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(409, json={"error": {"code": 409, "message": message}}))
    told = _quiet_owner(monkeypatch)
    with pytest.raises(portal.PortalError, match=text):
        asyncio.run(portal_docs.confirm(_session(7), "agb-unternehmer", name="Max Muster", version="2026-11-20"))
    assert told == []


def test_the_tick_and_a_real_name_are_needed():
    assert ConfirmInput(name="  Max   Muster ", accepted=True, version="2026-11-20").name == "Max Muster"
    for bad in ({"name": "Max", "accepted": False}, {"name": "M", "accepted": True}, {"name": "<b>Max</b>", "accepted": True},
                {"name": "Max", "accepted": True, "version": "20.11.2026"}, {"name": "x" * 129, "accepted": True}):
        with pytest.raises(ValidationError):
            ConfirmInput(**bad)


@pytest.mark.parametrize("png", ["data:image/png;base64," + PNG, PNG + "==", PNG[:100], "A" * (portal_docs.PNG_MAX + 1), PNG[:300] + "<svg"],
                         ids=["data-url", "padding", "too-short", "too-long", "markup"])
def test_a_signature_goes_only_as_plain_base64(png):
    with pytest.raises(ValidationError):
        SignInput(name="Max Muster", png=png)
    assert SignInput(name="Max Muster", png=PNG).png == PNG


# ------------------------------------------------------------------ rate limit

def _guarded(path: str, times: int, body: bytes = b"", length: int | None = None) -> list[int]:
    async def run():
        async def application(_scope, receive, send):
            await receive()
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send({"type": "http.response.body", "body": b""})

        guard = server.PublicRequestGuardMiddleware(application)
        statuses = []
        for _ in range(times):
            sent = []
            headers = [(b"content-length", str(length if length is not None else len(body)).encode())]
            scope = {"type": "http", "method": "POST", "path": path, "headers": headers, "client": ("192.0.2.30", 1)}

            async def receive():
                return {"type": "http.request", "body": body, "more_body": False}

            async def send(message, sent=sent):
                sent.append(message)

            await guard(scope, receive, send)
            statuses.append(sent[0]["status"])
        return statuses
    return asyncio.run(run())


def test_confirming_and_signing_are_limited_like_the_other_answers():
    assert _guarded("/api/portal/unterlagen/agb-unternehmer/bestaetigen", 12) == [200] * 10 + [429, 429]
    # One counter for every document: another name opens no new allowance.
    statuses = _guarded("/api/portal/unterlagen/avv/unterschreiben", 11)
    assert statuses[:10] == [200] * 10 and statuses[10] == 429


def test_a_signature_larger_than_the_module_takes_is_refused_before_it_is_read():
    assert _guarded("/api/portal/unterlagen/avv/unterschreiben", 1, length=2 * 1024 * 1024) == [413]
    assert _guarded("/api/portal/unterlagen/agb/bestaetigen", 1, length=8 * 1024) == [413]


# ------------------------------------------------------------------ the owner

def test_the_owner_mail_looks_like_the_others_and_leads_to_the_customer():
    item = portal_docs._item({**ROW, "state": "signing", "action": "sign", "title": "AVV"}, {"id": 7, "name": "Musterfirma"})
    subject, text, html = portal_docs.owner_mail(what="sign", item=item, name="Maria <Muster>", email="office@example.com",
                                                 ip="198.51.100.24", when="26.11.2026 10:00")
    assert subject == "Kundenbereich: AVV unterschrieben (Musterfirma) – Gegenzeichnung offen"
    assert "IP 198.51.100.24" in text and "Stand 20.11.2026" in text and "Weiter unterschreiben" in text
    assert "/custom/ittabelanderpdf/docs.php?socid=7" in html
    # What the customer typed shows as text, never as markup.
    assert "Maria <Muster>" not in html and "Maria &lt;Muster&gt;" in html
    # No logo on top: the first thing in the sheet is the text; the signature is at the end.
    sheet = html.split('class="wb-bg wb-tx"', 1)[1]
    assert sheet.index("Musterfirma hat") < sheet.index("<img") and "Fabian Tabelander" in sheet and "Liebe Grüße" not in text


def test_the_owner_mail_failing_never_undoes_the_proof(monkeypatch):
    async def recipients():
        return ["owner@example.com"]

    async def broken(*args, **kwargs):
        raise OSError("smtp down")

    import app.handover as handover
    monkeypatch.setattr(handover, "warning_recipients", recipients)
    monkeypatch.setattr(mailer, "send_mail", broken)
    item = portal_docs._item(ROW, {"id": 7, "name": "Kunde 7"})
    asyncio.run(portal_docs._notify_owner(_session(7), item, what="confirm", name="Max", ip=""))

"""The customer area as a ticket portal (#122): history, answers, closing;
files and new inquiries (#123)."""
import asyncio
import base64
import io
import json
import re
from urllib.parse import parse_qs, urlparse

import httpx
import pytest
from PIL import Image

import server
from app import dolibarr, mailer, portal, portal_files, portal_inquiries as inquiries
from app.routers.portal import LoginInput, MessageInput, NewInquiryInput

REAL_CLIENT = httpx.AsyncClient
CFG = {"enabled": True, "base": "https://erp.example.com", "api_key": "key", "timeout": 5,
       "site_base": "https://it.example.com"}
SESSION = {"email": "max@example.com", "customers": [{"id": 7, "name": "Max Muster"}]}


def _ticket(ticket_id=11, socid="7", status="3", **extra):
    return {"id": str(ticket_id), "ref": f"TS{ticket_id}", "track_id": f"ITTRACK{ticket_id:09d}", "socid": socid,
            "fk_soc": socid, "fk_statut": status, "status": status, "subject": "Reparatur: Notebook (ANF-7K3M9Q2X)",
            "message": "Startet nicht.", "origin_email": "max@example.com", "datec": 1790000000, **extra}


EVENTS = [
    {"id": "1", "code": "AC_TICKET_CREATE", "note_private": "Ticket TS11 erstellt", "datep": 1790000001, "authorid": "2"},
    {"id": "2", "code": "TICKET_MSG_PRIVATE", "note_private": "Interne Notiz: Kunde zahlt schlecht", "datep": 1790000002,
     "authorid": "1"},
    {"id": "3", "code": "TICKET_MSG_SENTBYMAIL", "note_private": "<p>Welches Modell genau?</p>", "datep": 1790000003,
     "authorid": "1"},
    {"id": "4", "code": "AC_EMAIL_IN", "datep": 1790000004, "email_from": '"Max Muster" <max@example.com>',
     "note_private": "Ticket found by trackid\n-----\nEin T14.\n\nAm 1.1.2026 schrieb IT-Tabelander:\n> Welches?\n"
                     "----- Header\nReceived: from mail"},
    {"id": "5", "code": "TICKET_MSG_PRIVATE_SENTBYMAIL", "note_private": "Auch intern", "datep": 1790000005},
    # Mails about the ticket with somebody else: a supplier's answer, a mail to the supplier.
    {"id": "7", "code": "AC_EMAIL_IN", "datep": 1790000007, "email_from": "Lieferant <verkauf@lieferant.example>",
     "note_private": "-----\nAngebot Ersatzteil 80 Euro netto"},
    {"id": "8", "code": "AC_EMAIL", "datep": 1790000008, "email_to": "verkauf@lieferant.example",
     "note_private": "-----\nBitte Teil bestellen"},
    {"id": "6", "code": "AC_TEL", "note_private": "Telefon: +43 1", "datep": 1790000006},
]


class Dolibarr:
    """A small Dolibarr: tickets, their agenda events, messages and changes."""

    def __init__(self, tickets=(), events=EVENTS, files=()):
        self.tickets = {str(item["id"]): item for item in tickets}
        self.events = list(events)
        self.files = list(files)
        self.posted, self.put, self.uploaded, self.created, self.downloads = [], [], [], [], []

    def __call__(self, request: httpx.Request):
        path = request.url.path.removeprefix("/api/index.php")
        if request.method == "GET" and path.startswith("/tickets/track_id/"):
            wanted = path.rsplit("/", 1)[-1]
            found = next((item for item in self.tickets.values() if item["track_id"] == wanted), None)
            return httpx.Response(200, json=found) if found else httpx.Response(404, json={})
        if request.method == "GET" and re.fullmatch(r"/tickets/\d+", path):
            found = self.tickets.get(path.rsplit("/", 1)[-1])
            return httpx.Response(200, json=found) if found else httpx.Response(404, json={})
        if request.method == "PUT" and re.fullmatch(r"/tickets/\d+", path):
            body = json.loads(request.content)
            self.put.append(body)
            self.tickets[path.rsplit("/", 1)[-1]].update({"fk_statut": str(body["status"]), "status": str(body["status"])})
            return httpx.Response(200, json={})
        if path == "/tickets/newmessage":
            self.posted.append(json.loads(request.content))
            return httpx.Response(200, json="1")
        if path == "/agendaevents":
            return httpx.Response(200, json=self.events)
        if request.method == "GET" and path == "/documents":
            return httpx.Response(200, json=self.files) if self.files else httpx.Response(404, json={})
        if path == "/documents/upload":
            body = json.loads(request.content)
            self.uploaded.append(body)
            self.files.append({"name": body["filename"], "size": len(base64.b64decode(body["filecontent"])),
                               "date": 1790000100})
            return httpx.Response(200, json=body["filename"])
        if path == "/documents/download":
            self.downloads.append(request.url.params["original_file"])
            return httpx.Response(200, json={"content": base64.b64encode(b"%PDF-1.4 test").decode()})
        if request.method == "POST" and path == "/tickets":
            body = json.loads(request.content)
            self.created.append(body)
            self.tickets["77"] = _ticket(77, socid=str(body["socid"]), subject=body["subject"], message=body["message"],
                                         track_id=body["track_id"], status="0")
            return httpx.Response(200, json=77)
        if path in ("/proposals", "/contacts"):
            return httpx.Response(404, json={})
        raise AssertionError(f"not expected: {request.method} {path}")


@pytest.fixture
def world(monkeypatch):
    calls, mails, db_updates = [], [], []

    def use(dolibarr_fake):
        def recording(request):
            calls.append(request)
            return dolibarr_fake(request)

        monkeypatch.setattr(portal.httpx, "AsyncClient",
                            lambda **kwargs: REAL_CLIENT(transport=httpx.MockTransport(recording), **kwargs))
        return dolibarr_fake

    async def config():
        return CFG

    async def send(to, subject, text, html=None):
        mails.append({"to": to, "subject": subject, "text": text, "html": html})

    async def recipients():
        return ["werkstatt@example.com"]

    class Requests:
        async def update_many(self, query, update):
            db_updates.append((query, update))

    class Db:
        repair_requests = Requests()

    from app import handover
    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(mailer, "send_mail", send)
    monkeypatch.setattr(handover, "warning_recipients", recipients)
    monkeypatch.setattr(inquiries, "get_db", lambda: Db())
    return {"use": use, "calls": calls, "mails": mails, "db": db_updates}


# ------------------------------------------------------------------ reading

def test_the_history_shows_public_messages_only_and_who_wrote_them(world):
    world["use"](Dolibarr([_ticket()]))
    shown = asyncio.run(inquiries.detail(SESSION, 11))
    assert shown["title"] == "Reparatur: Notebook" and shown["inquiry_ref"] == "ANF-7K3M9Q2X" and shown["can_reply"] is True
    assert [(item["from"], item["text"]) for item in shown["messages"]] == [
        ("customer", "Startet nicht."), ("team", "Welches Modell genau?"), ("customer", "Ein T14.")]
    text = json.dumps(shown, ensure_ascii=False)
    # Never a private message, never Dolibarr's log, never the mail headers or the quoted mail.
    for hidden in ("Interne Notiz", "Auch intern", "erstellt", "Telefon", "Received", "Welches?", "trackid",
                   "Ersatzteil", "Teil bestellen"):
        assert hidden not in text
    assert shown["messages"][2]["via"] == "email" and shown["messages"][2]["author"] == "Max Muster"
    # The agenda is asked for this ticket only.
    agenda = next(call for call in world["calls"] if call.url.path.endswith("/agendaevents"))
    assert parse_qs(urlparse(str(agenda.url)).query)["sqlfilters"][0] == "(t.fk_element:=:11) and (t.elementtype:=:'ticket')"


def test_an_answer_from_the_customer_area_shows_as_the_customers(world):
    signed = dolibarr.as_note(f"Passt <so>\nDanke\n\n{inquiries.SIGNATURE}Eva Muster (eva@example.com)")
    world["use"](Dolibarr([_ticket()], events=[{"id": "9", "code": "TICKET_MSG", "note_private": signed,
                                                "datep": 1790000009, "authorid": "2"}]))
    [first, answer] = asyncio.run(inquiries.detail(SESSION, 11))["messages"]
    assert answer == {**answer, "from": "customer", "author": "Eva Muster", "via": "portal", "text": "Passt <so>\nDanke"}


def test_the_first_message_of_an_older_ticket_leaves_out_what_was_for_the_workshop():
    old = ("Hinweis: Der Kunde war in Dolibarr schon vorhanden und wurde nicht geändert. Die Kontaktdaten unten "
           "stammen aus dem Formular.\n\nAnfrage-Referenz: ANF-ABCD1234\nAnfrageart: Reparatur\nQuelle: website\n"
           "Hersteller: Lenovo\nModell: –\n\nBeschreibung:\nStartet nicht.\n\nFotos:\n- 2 Foto(s), am Ticket\n\n"
           "Kontakt:\nName: Max\nE-Mail: max@example.com\nTelefon: +43 1\n\nRequest-ID: abc")
    assert inquiries.first_message_text(old) == "Anfrageart: Reparatur\nHersteller: Lenovo\n\nBeschreibung:\nStartet nicht."
    contact = "Kontaktnachricht über die Website (ANF-X)\n\nHabt ihr offen?\n\nKontakt:\nName: A\nE-Mail: a@b.at"
    assert inquiries.first_message_text(contact) == "Habt ihr offen?"
    # A text of today stays as it is.
    assert inquiries.first_message_text("Anfrageart: Reparatur\n\nBeschreibung:\nGeht: –") == \
        "Anfrageart: Reparatur\n\nBeschreibung:\nGeht: –"


def test_someone_elses_or_a_missing_ticket_is_not_there(world):
    world["use"](Dolibarr([_ticket(), _ticket(12, socid="8")]))
    for ticket_id in (12, 99):
        with pytest.raises(portal.NotFound):
            asyncio.run(inquiries.detail(SESSION, ticket_id))
    with pytest.raises(portal.NotFound):
        asyncio.run(inquiries.reply(SESSION, 12, "Hallo"))
    with pytest.raises(portal.NotFound):
        asyncio.run(inquiries.close(SESSION, 12))
    assert not any(call.method in ("POST", "PUT") for call in world["calls"])


def test_the_link_from_the_mails_finds_only_the_own_inquiry(world):
    world["use"](Dolibarr([_ticket(), _ticket(12, socid="8")]))
    assert asyncio.run(inquiries.find_by_track(SESSION, "ITTRACK000000011")) == {"id": 11}
    for track in ("ITTRACK000000012", "ITUNKNOWN0000000", "../tickets/11", ""):
        with pytest.raises(portal.NotFound):
            asyncio.run(inquiries.find_by_track(SESSION, track))


# ------------------------------------------------------------------ writing

def test_an_answer_is_a_signed_ticket_message_and_the_owner_hears_of_it(world):
    fake = world["use"](Dolibarr([_ticket(status="5")]))
    shown = asyncio.run(inquiries.reply(SESSION, 11, "Die Seriennummer ist <123> & fertig.\nLG"))
    [message] = fake.posted
    assert message["track_id"] == "ITTRACK000000011" and "private" not in message
    assert message["message"].startswith("Die Seriennummer ist &lt;123&gt; &amp; fertig.<br>\nLG")
    assert message["message"].endswith(f"{inquiries.SIGNATURE}Max Muster (max@example.com)")
    # It waited for the customer: back to the workshop, as with an answer by mail.
    assert fake.put == [{"status": 0}]
    assert shown["can_reply"] is True
    [mail] = world["mails"]
    assert mail["to"] == ["werkstatt@example.com"] and mail["subject"] == "Kundenbereich: Antwort zu TS11"
    assert "Die Seriennummer ist <123> & fertig." in mail["text"]
    assert "https://erp.tabelander.co.at/ticket/card.php?track_id=ITTRACK000000011" in mail["html"]
    assert "Ticket in Dolibarr öffnen" in mail["html"] and "&lt;123&gt;" in mail["html"]
    assert "Liebe Grüße" not in mail["text"]


def test_a_closed_ticket_takes_no_answer(world):
    fake = world["use"](Dolibarr([_ticket(status="8")]))
    with pytest.raises(portal.PortalError, match="abgeschlossen"):
        asyncio.run(inquiries.reply(SESSION, 11, "Noch was"))
    assert fake.posted == [] and world["mails"] == []
    assert asyncio.run(inquiries.detail(SESSION, 11))["can_reply"] is False


def test_closing_closes_once_notes_it_privately_and_stops_the_review_request(world):
    fake = world["use"](Dolibarr([_ticket()]))
    shown = asyncio.run(inquiries.close(SESSION, 11))
    assert shown["step"] == "abgeschlossen" and shown["can_reply"] is False
    [change] = fake.put
    assert change["status"] == 8 and change["progress"] == 100 and change["date_close"] > 0
    [note] = fake.posted
    assert note["private"] == 1 and "als erledigt geschlossen von Max Muster" in note["message"]
    [(query, update)] = world["db"]
    assert query["dolibarr.ticket_id"] == "11" and "review.stopped" in update["$set"]
    assert world["mails"][0]["subject"] == "Kundenbereich: TS11 als erledigt geschlossen"
    # A second click changes nothing and mails nobody.
    asyncio.run(inquiries.close(SESSION, 11))
    assert len(fake.put) == 1 and len(world["mails"]) == 1


# ------------------------------------------------------------------ the edges

def test_an_answer_needs_words_and_has_a_limit():
    assert MessageInput(message="  Danke!  ").message == "Danke!"
    for bad in ("", " x ", "x" * 5001):
        with pytest.raises(ValueError):
            MessageInput(message=bad)


def test_after_sign_in_only_pages_of_the_customer_area():
    good = "/kundenbereich/anfragen?track_id=ITTRACK000000011"
    assert LoginInput(email="max@example.com", next=good).next == good
    assert LoginInput(email="max@example.com", next="/kundenbereich/anfragen/11").next == "/kundenbereich/anfragen/11"
    for bad in ("https://evil.example/", "//evil.example", "/kundenbereich/../admin", "/admin",
                "/kundenbereich/anfragen?track_id=x&weiter=https://evil.example"):
        assert LoginInput(email="max@example.com", next=bad).next is None
    link = portal.sign_in_link("https://it.example.com", "TOKEN", good)
    assert link == "https://it.example.com/kundenbereich/anmelden?ziel=%2Fkundenbereich%2Fanfragen%3Ftrack_id%3DITTRACK000000011#TOKEN"
    assert portal.sign_in_link("https://it.example.com", "TOKEN", "https://evil.example") == \
        "https://it.example.com/kundenbereich/anmelden#TOKEN"


def test_answers_and_closing_are_limited_per_pattern_not_per_ticket():
    async def run():
        accepted = []

        async def application(_scope, receive, send):
            await receive()
            accepted.append(True)
            await send({"type": "http.response.start", "status": 204, "headers": []})
            await send({"type": "http.response.body", "body": b""})

        guard = server.PublicRequestGuardMiddleware(application)
        statuses = []
        for number in range(12):
            sent = []
            scope = {"type": "http", "method": "POST", "path": f"/api/portal/inquiries/{number + 1}/close",
                     "headers": [], "client": ("192.0.2.20", 1)}

            async def receive():
                return {"type": "http.request", "body": b"", "more_body": False}

            async def send(message, sent=sent):
                sent.append(message)

            await guard(scope, receive, send)
            statuses.append(sent[0]["status"])
        return statuses

    statuses = asyncio.run(run())
    assert statuses[:10] == [204] * 10 and statuses[10:] == [429, 429]


# ------------------------------------------------------------------ files (#123)

FILES = [
    {"name": "kunde-20260926-101500-a1b2c3-defekt.webp", "size": 2048, "date": 1790000010},
    {"name": "anfrage-foto-6523f0.webp", "size": 1024, "date": 1790000000},
    {"relativename": "rechnung-lieferant.pdf", "size": 5000, "date": 1790000011},
    {"name": "angebot-geteilt.pdf", "size": 3000, "date": 1790000012, "share": "abc123"},
    {"name": "antwort-anhang.pdf", "size": 3000, "date": 1790000013, "agenda_id": "3"},
    {"name": "intern-anhang.pdf", "size": 3000, "date": 1790000014, "agenda_id": "2"},
    {"name": "boese.php.noexe", "size": 10, "date": 1790000015, "share": "x"},
]


def _photo(fmt="JPEG", size=(3000, 2000)) -> bytes:
    buffer = io.BytesIO()
    image = Image.new("RGB", size, "orange")
    if fmt == "JPEG":
        exif = Image.Exif()
        exif[0x0112] = 6  # the phone was held upright
        exif[0x010F] = "Testphone"
        image.save(buffer, fmt, exif=exif)
    else:
        image.save(buffer, fmt)
    return buffer.getvalue()


def test_the_customer_sees_his_own_shared_and_answered_files_only(world):
    world["use"](Dolibarr([_ticket()], files=FILES))
    shown = asyncio.run(inquiries.detail(SESSION, 11))
    assert [(item["name"], item["from"], item["kind"]) for item in shown["files"]] == [
        ("anfrage-foto-6523f0.webp", "customer", "image"),
        ("kunde-20260926-101500-a1b2c3-defekt.webp", "customer", "image"),
        ("angebot-geteilt.pdf", "team", "pdf"),
        ("antwort-anhang.pdf", "team", "pdf"),
    ]


def test_a_file_comes_only_from_the_own_ticket_and_only_if_visible(world):
    fake = world["use"](Dolibarr([_ticket(), _ticket(12, socid="8")], files=FILES))
    name, media_type, content = asyncio.run(portal_files.download(SESSION, 11, "antwort-anhang.pdf"))
    assert media_type == "application/pdf" and content.startswith(b"%PDF") and fake.downloads == ["TS11/antwort-anhang.pdf"]
    for ticket_id, wanted in ((11, "rechnung-lieferant.pdf"), (11, "intern-anhang.pdf"), (11, "../TS12/x.pdf"),
                              (11, "boese.php.noexe"), (12, "antwort-anhang.pdf")):
        with pytest.raises(portal.NotFound):
            asyncio.run(portal_files.download(SESSION, ticket_id, wanted))
    assert len(fake.downloads) == 1


def test_a_photo_is_made_anew_without_its_location_and_smaller(world):
    fake = world["use"](Dolibarr([_ticket()]))
    stored = asyncio.run(portal_files.upload(SESSION, 11, _photo(), "Mein Foto (1).jpg"))
    assert re.fullmatch(r"kunde-\d{8}-\d{6}-[a-f0-9]{6}-Mein-Foto-1\.webp", stored["name"]), stored
    [sent] = fake.uploaded
    assert sent["modulepart"] == "ticket" and sent["ref"] == "11" and sent["overwriteifexists"] == 0
    image = Image.open(io.BytesIO(base64.b64decode(sent["filecontent"])))
    # Upright as taken, at most 2000 px, and nothing of the phone's data comes along.
    assert image.format == "WEBP" and image.size == (1333, 2000)
    assert not dict(image.getexif()), "EXIF left with the photo"
    assert portal_files.UPLOADED.fullmatch(stored["name"])


def test_a_pdf_stays_as_it_is_anything_else_is_refused(world):
    fake = world["use"](Dolibarr([_ticket()]))
    pdf = asyncio.run(portal_files.upload(SESSION, 11, b"%PDF-1.7\n...", "Rechnung.PDF"))
    assert pdf["name"].endswith("-Rechnung.pdf") and base64.b64decode(fake.uploaded[0]["filecontent"]) == b"%PDF-1.7\n..."
    svg = b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
    for raw, name in ((svg, "bild.jpg"), (b"MZ\x90\x00", "programm.pdf"), (b"", "leer.png"), (_photo("BMP", (10, 10)), "x.bmp"),
                      (b"%PDF-" + b"x" * portal_files.PDF_MAX, "gross.pdf")):
        with pytest.raises(portal.PortalError):
            asyncio.run(portal_files.upload(SESSION, 11, raw, name))
    assert len(fake.uploaded) == 1


def test_no_upload_to_a_closed_or_someone_elses_ticket(world):
    fake = world["use"](Dolibarr([_ticket(status="8"), _ticket(12, socid="8")]))
    with pytest.raises(portal.PortalError, match="abgeschlossen"):
        asyncio.run(portal_files.upload(SESSION, 11, b"%PDF-1.7", "a.pdf"))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal_files.upload(SESSION, 12, b"%PDF-1.7", "a.pdf"))
    assert fake.uploaded == []


def test_an_answer_names_the_files_that_are_really_on_the_ticket(world):
    fake = world["use"](Dolibarr([_ticket()], files=FILES))
    own = "kunde-20260926-101500-a1b2c3-defekt.webp"
    asyncio.run(inquiries.reply(SESSION, 11, "", [own, "kunde-20260926-101500-ffffff.webp"]))
    [message] = fake.posted
    assert message["message"].startswith(f"Angehängt: {own}") and "ffffff" not in message["message"]
    assert own in world["mails"][0]["text"]
    with pytest.raises(ValueError):
        MessageInput(message="", files=[])
    with pytest.raises(ValueError):
        MessageInput(message="Hallo", files=["../../etc/passwd"])
    assert MessageInput(files=[own]).files == [own]


# ------------------------------------------------------------------ a new inquiry (#123)

def test_a_new_inquiry_goes_onto_the_own_customer_with_a_note(world):
    fake = world["use"](Dolibarr([]))
    payload = NewInquiryInput(request_type="repair", device_type="notebook", manufacturer="Lenovo",
                              description="Startet seit gestern nicht mehr.", customer_id=999)
    created = asyncio.run(inquiries.create(SESSION, payload.model_dump(exclude={"customer_id"}), payload.customer_id))
    assert created["id"] == 77 and created["ref"].startswith("ANF-")
    [ticket] = fake.created
    # Someone else's customer number is ignored: the ticket lands on the session's own customer.
    assert ticket["socid"] == "7" and ticket["origin_email"] == "max@example.com"
    assert ticket["subject"] == f"Reparatur: Notebook Lenovo ({created['ref']})"
    assert ticket["message"].endswith("Beschreibung:\nStartet seit gestern nicht mehr.")
    assert ticket["type_code"] == "ISSUE" and ticket["notify_tiers_at_create"] == 1 and ticket["track_id"].startswith("IT")
    [note] = fake.posted
    assert note["private"] == 1 and "Im Kundenbereich angelegt von Max Muster (max@example.com)" in note["message"]


def test_a_new_inquiry_needs_what_the_form_needs():
    with pytest.raises(ValueError):
        NewInquiryInput(request_type="repair", description="Startet nicht mehr.")
    with pytest.raises(ValueError):
        NewInquiryInput(request_type="controller_custom", description="Bitte Hall-Sticks.")
    with pytest.raises(ValueError):
        NewInquiryInput(request_type="contact", description="Hallo, eine Frage.")
    assert NewInquiryInput(request_type="consulting", description="Welcher Laptop fürs Studium?").topic == ""

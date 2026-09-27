"""Milestone 2, part B: mail, queue, forgetting, contact form, callback."""
import asyncio
import json
import smtplib
from datetime import datetime, timedelta, timezone

import httpx
import pytest
from bson import ObjectId
from fastapi import HTTPException
from pydantic import ValidationError

from app import dolibarr, handover, mailer
from app.models import ContactInput, InquiryInput, SettingsInput
from app.routers import settings as settings_router

CFG = {"base": "https://erp.example.test", "api_key": "top-secret-key", "country_code": "AT", "timeout": 8}


class Collection:
    """Just enough of a Mongo collection to see what the code writes."""

    def __init__(self, doc=None):
        self.doc = doc or {}
        self.updates = []

    async def find_one(self, *_args, **_kwargs):
        return self.doc

    async def update_one(self, query, update, **_kwargs):
        self.updates.append((query, update))


class Database:
    def __init__(self, settings=None):
        self.repair_requests = Collection()
        self.settings = Collection(settings)


# ------------------------------------------------------------------ queue

def test_pauses_grow_from_five_minutes_to_an_hour():
    assert [handover.pause_after(n).total_seconds() / 60 for n in (1, 2, 3, 4, 5, 9)] == [5, 10, 20, 40, 60, 60]


def _failure(stage="ticket_create"):
    return {"synced": False, "stage": stage, "error": {"message": "Dolibarr ist nicht erreichbar"}}


def test_a_failed_attempt_plans_the_next_one():
    db = Database()
    doc = {"_id": 1, "auto_handover": True, "queue": handover.new_queue(datetime.now(timezone.utc))}
    asyncio.run(handover.store_sync_result(db, doc, _failure()))
    queue = db.repair_requests.updates[0][1]["$set"]["queue"]
    assert queue["attempts"] == 1 and queue["gave_up"] is False
    pause = queue["next_attempt_at"] - datetime.now(timezone.utc)
    assert timedelta(minutes=4) < pause <= timedelta(minutes=5)

    doc["queue"] = {**queue, "attempts": handover.QUEUE_MAX_ATTEMPTS - 1}
    asyncio.run(handover.store_sync_result(db, doc, _failure()))
    assert db.repair_requests.updates[1][1]["$set"]["queue"]["gave_up"] is True


def test_dolibarr_switched_off_costs_no_attempt():
    db = Database()
    doc = {"_id": 1, "auto_handover": True, "queue": {"attempts": 3, "gave_up": False}}
    asyncio.run(handover.store_sync_result(db, doc, {"synced": False, "stage": "disabled"}))
    assert db.repair_requests.updates[0][1]["$set"]["queue"]["attempts"] == 3


def test_old_records_are_not_queued():
    db = Database()
    asyncio.run(handover.store_sync_result(db, {"_id": 1}, _failure()))
    assert "queue" not in db.repair_requests.updates[0][1]["$set"]


def test_after_the_hand_over_only_number_ticket_and_times_remain(monkeypatch):
    from app.routers import media
    deleted = []

    async def delete(items):
        deleted.extend(items)
        return len(items)

    monkeypatch.setattr(media, "delete_repair_attachments", delete)
    db = Database()
    photo = {"id": str(ObjectId()), "url": "/api/media/a.webp"}
    doc = {"_id": 1, "auto_handover": True, "attachments": [photo], "contact": {"name": "Max"}}
    synced = {"synced": True, "stage": "complete", "ticket_id": "501", "documents_uploaded": [photo["id"]]}
    asyncio.run(handover.store_sync_result(db, doc, synced))
    update = db.repair_requests.updates[0][1]
    assert deleted == [photo]
    assert set(handover.PERSONAL_FIELDS) <= set(update["$unset"]) and "queue" in update["$unset"]
    assert update["$set"]["personal_data_removed_at"] and update["$set"]["dolibarr"]["ticket_id"] == "501"
    assert not set(update["$set"]) & set(handover.PERSONAL_FIELDS)


def test_personal_data_stays_while_a_photo_copy_could_not_be_removed(monkeypatch):
    from app.routers import media

    async def broken(_items):
        raise RuntimeError("disk")

    monkeypatch.setattr(media, "delete_repair_attachments", broken)
    db = Database()
    photo = {"id": str(ObjectId()), "url": "/api/media/a.webp"}
    doc = {"_id": 1, "auto_handover": True, "attachments": [photo], "contact": {"name": "Max"}}
    synced = {"synced": True, "ticket_id": "501", "documents_uploaded": [photo["id"]]}
    asyncio.run(handover.store_sync_result(db, doc, synced))
    update = db.repair_requests.updates[0][1]
    assert "contact" not in update.get("$unset", {}) and "personal_data_removed_at" not in update["$set"]


def test_warning_names_the_reason():
    assert "ausgeschaltet" in handover._reason({"dolibarr": {"stage": "disabled"}})
    assert handover._reason({"dolibarr": {"error": {"message": "HTTP 403"}}}) == "HTTP 403"


# -------------------------------------------------------- contact, silent

def _sync(handler, **kwargs):
    async def run():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            return await dolibarr._sync_ticket_with_client(
                client, CFG, subject="Kontaktnachricht (ANF-12345678)", message="Hallo",
                contact={"name": "Eva", "email": "eva@example.com", "phone": "+43 1"}, **kwargs,
            )
    return asyncio.run(run())


def test_a_contact_message_creates_no_third_party():
    calls, tickets = [], []

    def handler(request: httpx.Request):
        calls.append((request.method, request.url.path))
        if request.method == "GET":
            return httpx.Response(404, json={"error": {"message": "Not found"}})
        tickets.append(json.loads(request.content))
        return httpx.Response(200, json=601)

    result = _sync(handler, create_thirdparty=False)
    assert result["synced"] is True and result["thirdparty_id"] is None
    assert not any(path.endswith("/thirdparties") for method, path in calls if method == "POST")
    assert "fk_soc" not in tickets[0] and tickets[0]["origin_email"] == "eva@example.com"
    assert tickets[0]["notify_tiers_at_create"] == 1


def test_a_known_sender_is_linked_but_not_changed():
    tickets = []

    def handler(request: httpx.Request):
        if request.method == "GET":
            return httpx.Response(200, json={"id": "42"})
        tickets.append(json.loads(request.content))
        return httpx.Response(200, json=601)

    result = _sync(handler, create_thirdparty=False)
    assert result["thirdparty_id"] == "42" and tickets[0]["fk_soc"] == "42"


def test_old_records_go_over_without_a_confirmation_mail():
    tickets = []

    def handler(request: httpx.Request):
        if request.method == "GET":
            return httpx.Response(200, json={"id": "42"})
        tickets.append(json.loads(request.content))
        return httpx.Response(200, json=601)

    _sync(handler, notify=False)
    assert tickets[0]["notify_tiers_at_create"] == 0


# ---------------------------------------------------------------- callback

def test_the_callback_wish_becomes_one_phone_call_in_the_agenda():
    events, lookups = [], []
    wish = "2026-10-02T10:30:00+02:00"
    found = {"event": None}

    def handler(request: httpx.Request):
        path = request.url.path
        if path.endswith("/agendaevents") and request.method == "GET":
            lookups.append(request.url.params["sqlfilters"])
            return httpx.Response(200, json=[found["event"]]) if found["event"] else httpx.Response(404, json={})
        if path.endswith("/tickets/601"):
            return httpx.Response(200, json={"id": "601", "fk_user_create": "7"})
        if path.endswith("/agendaevents"):
            events.append(json.loads(request.content))
            return httpx.Response(200, json=901)
        if request.method == "GET":
            return httpx.Response(200, json={"id": "42"})
        return httpx.Response(200, json=601)

    callback = {"at": wish, "label": "Rückruf: Eva (ANF-12345678)", "note": "Telefon: +43 1"}
    first = _sync(handler, callback=callback)
    assert first["synced"] is True and first["callback_event_id"] == "901"
    event = events[0]
    assert event["type_code"] == "AC_TEL" and event["userownerid"] == "7"
    assert event["datep"] == int(datetime.fromisoformat(wish).timestamp())
    assert event["datef"] - event["datep"] == 15 * 60
    assert event["elementtype"] == "ticket" and event["elementid"] == 601 and event["socid"] == 42
    assert "t.fk_element:=:601" in lookups[0]

    # A retry after a lost answer finds the event instead of making a second one.
    found["event"] = {"id": "901"}
    again = _sync(handler, callback=callback, previous={"thirdparty_id": "42", "ticket_id": "601"})
    assert again["callback_event_id"] == "901" and len(events) == 1


def _inquiry(**changes):
    data = {
        "request_id": "browser-12345678", "request_type": "repair", "device_type": "pc",
        "description": "Startet nicht mehr.", "consent": True,
        "contact": {"name": "Eva Muster", "email": "eva@example.com", "phone": "+43 660 1"},
    }
    data.update(changes)
    return data


def test_callback_needs_a_phone_and_a_time_ahead():
    later = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
    assert InquiryInput.model_validate(_inquiry(callback_at=later)).callback_at
    without_phone = _inquiry(callback_at=later)
    without_phone["contact"] = {**without_phone["contact"], "phone": ""}
    past = (datetime.now(timezone.utc) - timedelta(hours=1)).isoformat()
    naive = (datetime.now() + timedelta(days=1)).replace(microsecond=0).isoformat()
    far = (datetime.now(timezone.utc) + timedelta(days=90)).isoformat()
    for broken in (without_phone, _inquiry(callback_at=past), _inquiry(callback_at=naive),
                   _inquiry(callback_at=far)):
        with pytest.raises(ValidationError):
            InquiryInput.model_validate(broken)


def test_contact_form_fields():
    ok = ContactInput.model_validate({"request_id": "browser-12345678", "name": "Eva", "email": "eva@example.com",
                                      "message": "Habt ihr am Samstag offen?", "consent": True})
    assert ok.phone == "" and ok.callback_at is None
    with pytest.raises(ValidationError):
        ContactInput.model_validate({"request_id": "browser-12345678", "name": "Eva", "email": "eva@example.com",
                                     "message": "kurz", "consent": True})


def test_contact_ticket_text_names_the_callback():
    text = dolibarr.format_inquiry_message({
        "request_type": "contact", "ref": "ANF-12345678", "description": "Bitte zurückrufen.",
        "contact": {"name": "Eva", "email": "eva@example.com", "phone": "+43 1"},
        "callback_at": "2026-10-02T10:30:00+02:00",
    })
    assert "Bitte zurückrufen." in text and "02.10.2026 um 10:30 Uhr" in text
    assert dolibarr.inquiry_subject({"request_type": "contact", "ref": "ANF-12345678"}) == \
        "Kontaktnachricht (ANF-12345678)"


# -------------------------------------------------------------------- mail

def test_mail_without_settings_says_what_is_missing(monkeypatch):
    monkeypatch.setattr(mailer, "get_db", lambda: Database({}))
    with pytest.raises(mailer.MailNotConfigured):
        asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))


class FakeSMTP:
    sent = []
    refuse_login = False

    def __init__(self, host, port, timeout):
        self.address = (host, port)

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def starttls(self, context):
        self.tls = True

    def login(self, user, password):
        if FakeSMTP.refuse_login:
            raise smtplib.SMTPAuthenticationError(535, b"bad credentials for secret-password")

    def send_message(self, message):
        FakeSMTP.sent.append((self.address, message))


def test_mail_goes_out_with_sender_and_subject(monkeypatch):
    monkeypatch.setattr(mailer, "get_db", lambda: Database({
        "smtp_host": "smtp.example.at", "smtp_port": 587, "smtp_security": "starttls",
        "smtp_username": "office", "smtp_password": "secret-password",
        "smtp_from": "office@example.at", "smtp_from_name": "IT-Tabelander",
    }))
    monkeypatch.setattr(mailer.smtplib, "SMTP", FakeSMTP)
    FakeSMTP.sent.clear()
    asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))
    address, message = FakeSMTP.sent[0]
    assert address == ("smtp.example.at", 587)
    assert message["To"] == "eva@example.com" and message["Subject"] == "Hallo"
    assert message["From"] == "IT-Tabelander <office@example.at>"

    FakeSMTP.refuse_login = True
    try:
        with pytest.raises(mailer.MailError) as refused:
            asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))
    finally:
        FakeSMTP.refuse_login = False
    assert "Benutzername oder Passwort" in str(refused.value) and "secret" not in str(refused.value)


def test_smtp_password_is_never_sent_back_and_only_super_admins_change_access(monkeypatch):
    stored = {"_id": "site", "smtp_host": "smtp.example.at", "smtp_password": "secret-password"}
    db = Database(stored)
    monkeypatch.setattr(settings_router, "get_db", lambda: db)
    shown = settings_router._admin_response(stored)
    assert "smtp_password" not in shown and shown["smtp_password_configured"] is True

    for change in ({"smtp_host": "evil.example"}, {"smtp_password": "new"}, {"clear_smtp_password": True}):
        with pytest.raises(HTTPException) as denied:
            asyncio.run(settings_router.update_settings(SettingsInput(**change), {"role": "admin"}))
        assert denied.value.status_code == 403
    # An unchanged host from a full form is fine for a normal admin.
    asyncio.run(settings_router.update_settings(SettingsInput(smtp_host="smtp.example.at", warning_email="a@b.at"),
                                                {"role": "admin"}))
    assert db.settings.updates[-1][1]["$set"]["warning_email"] == "a@b.at"
    assert "smtp_host" not in db.settings.updates[-1][1]["$set"]


def test_smtp_host_and_addresses_are_checked():
    with pytest.raises(ValidationError):
        SettingsInput(smtp_host="smtp.example.at/evil")
    with pytest.raises(ValidationError):
        SettingsInput(warning_email="kein-mail")
    with pytest.raises(ValidationError):
        SettingsInput(smtp_from_name="Name\r\nBcc: x@y.at")

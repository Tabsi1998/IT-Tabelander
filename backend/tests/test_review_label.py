"""Milestone 4, part B: review request after a closed ticket (#71), device label (#72)."""
import asyncio
import re
from datetime import datetime, timedelta, timezone

import pytest
from pydantic import ValidationError

from app import dolibarr, mailer, review_invites
from app.models import InquiryInput, ReviewInviteCheck, ReviewInviteSubmit

NOW = datetime(2026, 9, 27, 12, 0, tzinfo=timezone.utc)
LINK_CODE = "Beispiel-Link-nur-fuer-Tests-000000000000"


class Collection:
    """Just enough of a Mongo collection to see what the code writes."""

    def __init__(self, claim=True):
        self.claim = claim
        self.calls = []

    async def find_one_and_update(self, query, update, **_kwargs):
        self.calls.append(("claim", query, update))
        return {"_id": "claimed"} if self.claim else None

    async def insert_one(self, doc):
        doc["_id"] = "invite-1"
        self.calls.append(("insert", doc))

    async def delete_one(self, query):
        self.calls.append(("delete", query))

    async def update_one(self, query, update, **_kwargs):
        self.calls.append(("update", query, update))


class Database:
    def __init__(self, claim=True):
        self.repair_requests = Collection(claim)
        self.review_invites = Collection()


def _inquiry(**extra):
    return {"_id": "inq-1", "ref": "ANF-7K3M9Q2X", "request_type": "repair", "review_ok": True,
            "created_at": NOW - timedelta(days=3), "dolibarr": {"synced": True, "ticket_id": "501"}, **extra}


def _check(monkeypatch, ticket, *, db=None, mail_error=None, inquiry=None):
    db = db or Database()
    sent = []

    async def fetch(ticket_id):
        assert ticket_id == "501"
        if isinstance(ticket, Exception):
            raise ticket
        return ticket

    async def send(to, subject, text):
        if mail_error:
            raise mail_error
        sent.append((to, subject, text))

    monkeypatch.setattr(dolibarr, "fetch_ticket_status", fetch)
    monkeypatch.setattr(mailer, "send_mail", send)
    outcome = asyncio.run(review_invites._check_one(
        db, inquiry or _inquiry(), NOW, site_base="https://it.example.at", sender_name="IT-Tabelander"))
    return outcome, db, sent


def _closed(email="kunde@example.com"):
    return {"step": "abgeschlossen", "origin_email": email, "updated": None, "closed": None}


# -------------------------------------------------------------- the request

def test_a_closed_ticket_gets_one_mail_to_the_address_in_the_ticket(monkeypatch):
    outcome, db, sent = _check(monkeypatch, _closed())
    assert outcome == "sent"
    [(to, subject, text)] = sent
    assert to == "kunde@example.com"
    assert subject == "Kurze Bitte zu deiner Anfrage ANF-7K3M9Q2X"
    assert "Reparatur" in text and "einzige Mail" in text and "60 Tage" in text
    token = re.search(r"https://it\.example\.at/bewertung#([A-Za-z0-9_-]{40,})", text).group(1)
    # Only the hash is stored; the link exists in the mail alone.
    [(_, invite)] = [call for call in db.review_invites.calls if call[0] == "insert"]
    assert invite["token_hash"] == review_invites.token_hash(token) and token not in str(invite)
    assert invite["ref"] == "ANF-7K3M9Q2X" and invite["expires_at"] == NOW + timedelta(days=60)
    # Marked as invited before the mail leaves: never a second mail.
    claim = db.repair_requests.calls[0]
    assert claim[1] == {"_id": "inq-1", "review.invited_at": {"$exists": False}}
    assert claim[2] == {"$set": {"review.invited_at": NOW}}


def test_an_open_ticket_waits_for_the_next_round(monkeypatch):
    outcome, db, sent = _check(monkeypatch, {**_closed(), "step": "in_arbeit"})
    assert outcome == "later" and not sent and not db.review_invites.calls and not db.repair_requests.calls


def test_a_cancelled_ticket_or_one_without_address_gets_no_mail(monkeypatch):
    for ticket, reason in (({**_closed(), "step": "abgebrochen"}, "abgebrochen"),
                           (_closed(email=""), "keine E-Mail-Adresse"),
                           (None, "nicht mehr")):
        outcome, db, sent = _check(monkeypatch, ticket)
        assert outcome == "stopped" and not sent and not db.review_invites.calls
        assert reason in db.repair_requests.calls[0][2]["$set"]["review.stopped"]
        assert db.repair_requests.calls[0][2]["$unset"] == {"review.last_error": ""}


def test_a_ticket_open_for_half_a_year_is_given_up(monkeypatch):
    old = _inquiry(created_at=NOW - timedelta(days=200))
    outcome, db, _sent = _check(monkeypatch, {**_closed(), "step": "wartet_auf_dich"}, inquiry=old)
    assert outcome == "stopped" and "halben Jahr" in db.repair_requests.calls[0][2]["$set"]["review.stopped"]


def test_dolibarr_away_or_mail_failing_tries_again(monkeypatch):
    import httpx

    outcome, db, sent = _check(monkeypatch, httpx.ConnectError("weg"))
    assert outcome == "later" and not sent and not db.repair_requests.calls

    outcome, db, sent = _check(monkeypatch, _closed(), mail_error=mailer.MailError("Mailserver ist nicht erreichbar."))
    assert outcome == "failed" and not sent
    assert ("delete", {"_id": "invite-1"}) in db.review_invites.calls
    undo = db.repair_requests.calls[-1][2]
    assert undo["$unset"] == {"review.invited_at": ""} and "nicht erreichbar" in undo["$set"]["review.last_error"]


def test_a_record_someone_else_just_invited_gets_nothing(monkeypatch):
    outcome, db, sent = _check(monkeypatch, _closed(), db=Database(claim=False))
    assert outcome == "later" and not sent and not db.review_invites.calls


def test_due_records_and_the_manual_check():
    regular = review_invites._due(NOW, force=False)
    assert regular["review_ok"] is True and regular["dolibarr.synced"] is True
    assert {"review.next_check_at": {"$lte": NOW}} in regular["$or"]
    # "Jetzt prüfen" takes every record not claimed in this very round.
    manual = review_invites._due(NOW, force=True)
    assert {"review.next_check_at": {"$lt": NOW + review_invites.CHECK_EVERY}} in manual["$or"]


# -------------------------------------------------------------- the inputs

def test_the_yes_is_optional_and_off_by_default():
    base = {"request_id": "anfrage-12345678", "request_type": "consulting", "description": "Bitte um Beratung zum PC.",
            "contact": {"name": "Eva", "email": "eva@example.com"}, "consent": True}
    assert InquiryInput(**base).review_ok is False
    assert InquiryInput(**base, review_ok=True).review_ok is True


def test_the_review_form_checks_its_fields():
    good = {"token": LINK_CODE, "rating": 5, "text": "  Top!  ", "author": " Max M. ", "publish_ok": True}
    review = ReviewInviteSubmit(**good)
    assert review.text == "Top!" and review.author == "Max M."
    for bad in ({"rating": 0}, {"rating": 6}, {"token": "kurz"}, {"token": LINK_CODE + "/../x"}, {"author": "M"},
                {"text": "x" * 2001}):
        with pytest.raises(ValidationError):
            ReviewInviteSubmit(**{**good, **bad})
    with pytest.raises(ValidationError):
        ReviewInviteCheck(token="<script>")


def test_the_ticket_says_whether_a_review_may_be_asked_for():
    inquiry = {"request_type": "repair", "ref": "ANF-7K3M9Q2X", "device_type": "notebook", "description": "Startet nicht.",
               "contact": {"name": "Eva", "email": "eva@example.com"}}
    assert "Bewertungsbitte nach Abschluss: nein" in dolibarr.format_inquiry_message(inquiry)
    assert "Bewertungsbitte nach Abschluss: ja" in dolibarr.format_inquiry_message({**inquiry, "review_ok": True})
    assert "Gerät / Bereich: Notebook" in dolibarr.format_inquiry_message(inquiry)


# ------------------------------------------------------------------ label

def test_the_ticket_subject_names_the_device_in_words():
    repair = {"request_type": "repair", "ref": "ANF-7K3M9Q2X", "device_type": "notebook",
              "manufacturer": "Lenovo", "model": "ThinkPad T14"}
    assert dolibarr.inquiry_subject(repair) == "Reparatur: Notebook Lenovo ThinkPad T14 (ANF-7K3M9Q2X)"
    other = {**repair, "device_type": "other", "manufacturer": "Samsung", "model": ""}
    assert dolibarr.inquiry_subject(other) == "Reparatur: Samsung (ANF-7K3M9Q2X)"
    assert dolibarr.inquiry_subject({"request_type": "pc_build", "ref": "ANF-7K3M9Q2X"}) == "PC-Neubau (ANF-7K3M9Q2X)"
    assert dolibarr.subject_without_ref("Reparatur: Notebook (ANF-7K3M9Q2X)", "ANF-7K3M9Q2X") == "Reparatur: Notebook"
    # A subject the owner rewrote in Dolibarr stays as it is.
    assert dolibarr.subject_without_ref("Akku tauschen", "ANF-7K3M9Q2X") == "Akku tauschen"


def test_the_review_page_is_served_without_being_indexed(monkeypatch, tmp_path):
    from app import website

    async def seo():
        return "https://it.example.at", {}, {}

    page = tmp_path / "bewertung"
    page.mkdir()
    (page / "index.html").write_text('<html><head><meta name="robots" content="noindex" /></head>'
                                     "<body>BEWERTUNG</body></html>", encoding="utf-8")
    (tmp_path / "index.html").write_text("<html><body>START</body></html>", encoding="utf-8")
    monkeypatch.setattr(website, "seo_data", seo)
    response = asyncio.run(website.respond("bewertung", tmp_path))
    assert response.status_code == 200 and b"BEWERTUNG" in response.body and b"noindex" in response.body

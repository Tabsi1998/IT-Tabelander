"""The online withdrawal function (§ 13a FAGG, #142)."""
import asyncio
import json

import httpx
import pytest
from pydantic import ValidationError

from app import dolibarr, handover, mailer, portal, withdrawals
from app.models import WithdrawalInput

REAL_CLIENT = httpx.AsyncClient
CFG = {"enabled": True, "base": "https://erp.example.com", "api_key": "key", "timeout": 5,
       "site_base": "https://it.example.com"}
COMPANY = {"name": "IT-Tabelander", "address": "Werkstattweg 1", "zip": "6410", "town": "Telfs",
           "email": "office@example.com"}


class Collection:
    def __init__(self):
        self.docs = []

    async def find_one(self, query):
        return next((doc for doc in self.docs if all(doc.get(k) == v for k, v in query.items())), None)

    async def insert_one(self, doc):
        doc["_id"] = len(self.docs) + 1
        self.docs.append(doc)

    async def update_one(self, query, update):
        doc = await self.find_one(query)
        doc.update(update.get("$set", {}))
        for key in update.get("$unset", {}):
            doc.pop(key, None)


class Database:
    def __init__(self):
        self.withdrawals = Collection()


def payload(**changes):
    data = {"request_id": "widerruf-test-0001", "name": "Max Muster", "email": "Max@Example.com",
            "contract": "AN202611-0001", "scope": "all", "parts": "", "honeypot": ""}
    return WithdrawalInput(**{**data, **changes})


def setup(monkeypatch, *, proposals=None, enabled=True, mail_fails=False, owner=("werkstatt@example.com",)):
    db, sent, calls = Database(), [], []

    async def config():
        return {**CFG, "enabled": enabled}

    async def send(to, subject, text, html=None):
        if mail_fails and to == "max@example.com":
            raise OSError("smtp down")
        sent.append({"to": to, "subject": subject, "text": text, "html": html})

    async def recipients():
        return list(owner)

    async def company():
        return COMPANY

    def handler(request):
        calls.append(request)
        if request.method == "GET" and request.url.path.endswith("/proposals"):
            return httpx.Response(200, json=proposals) if proposals else httpx.Response(404, json={})
        if request.method == "GET":
            return httpx.Response(200, json=proposals[0])
        return httpx.Response(200, json={"id": 5})

    monkeypatch.setattr(withdrawals, "get_db", lambda: db)
    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(mailer, "send_mail", send)
    monkeypatch.setattr(handover, "warning_recipients", recipients)
    monkeypatch.setattr(portal, "_company", company)
    monkeypatch.setattr(withdrawals.httpx, "AsyncClient",
                        lambda **kwargs: REAL_CLIENT(transport=httpx.MockTransport(handler), **kwargs))
    return db, sent, calls


def test_the_declaration_is_confirmed_noted_at_the_offer_and_told_to_the_owner(monkeypatch):
    db, sent, calls = setup(monkeypatch, proposals=[{"id": "5", "ref": "AN202611-0001", "status": "2", "note_private": "Angenommen."}])
    answer = asyncio.run(withdrawals.submit(payload()))
    assert answer["mail_sent"] is True and answer["receipt"].startswith("WR-")
    customer, owner = sent
    assert customer["to"] == "max@example.com"
    assert customer["subject"] == "Eingangsbestätigung: Widerruf AN202611-0001"
    # Content, date and time of arrival on a durable medium (§ 13a FAGG).
    assert answer["received_local"] in customer["text"] and "Uhr" in customer["text"]
    assert "Ich widerrufe den ganzen Vertrag mit der Nummer AN202611-0001." in customer["text"]
    assert "Max Muster" in customer["text"] and customer["html"]
    assert owner["to"] == ["werkstatt@example.com"] and "Am Angebot in Dolibarr vermerkt" in owner["text"]
    assert "nicht angemeldet" in owner["text"]
    search, full, put = calls
    assert full.url.path.endswith("/proposals/5")
    assert "(t.ref:=:'AN202611-0001')" in search.url.params["sqlfilters"]
    note = json.loads(put.content)["note_private"]
    assert put.method == "PUT" and put.url.path.endswith("/proposals/5")
    assert note.startswith("Angenommen.") and "Widerruf über die Website eingegangen" in note
    stored = db.withdrawals.docs[0]
    # Once the owner has the notice, the website keeps no personal data.
    assert stored["owner_told"] and stored["dolibarr_note"] == "added"
    assert not any(key in stored for key in withdrawals.PERSONAL)


def test_a_part_of_the_contract_is_named(monkeypatch):
    _db, sent, _calls = setup(monkeypatch, proposals=[{"id": "5", "status": "2", "note_private": ""}])
    asyncio.run(withdrawals.submit(payload(scope="part", parts="Position 2, die Wartung")))
    assert "Ich widerrufe einen Teil des Vertrags" in sent[0]["text"]
    assert "Widerrufener Teil: Position 2, die Wartung" in sent[0]["text"]


def test_an_unknown_number_still_arrives_and_the_owner_searches(monkeypatch):
    db, sent, calls = setup(monkeypatch, proposals=None)
    answer = asyncio.run(withdrawals.submit(payload(contract="RE2026-00017")))
    assert answer["mail_sent"] and len(calls) == 1
    assert "kein Angebot mit dieser Nummer" in sent[1]["text"]
    assert db.withdrawals.docs[0]["dolibarr_note"] == "not_found"


def test_an_offer_that_is_not_accepted_gets_no_note(monkeypatch):
    db, sent, calls = setup(monkeypatch, proposals=[{"id": "5", "status": "1", "note_private": ""}])
    asyncio.run(withdrawals.submit(payload()))
    assert [request.method for request in calls] == ["GET", "GET"]
    assert db.withdrawals.docs[0]["dolibarr_note"] == "not_signed" and "nicht angenommen" in sent[1]["text"]


def test_without_dolibarr_the_withdrawal_works_all_the_same(monkeypatch):
    db, sent, calls = setup(monkeypatch, enabled=False)
    assert asyncio.run(withdrawals.submit(payload()))["mail_sent"]
    assert calls == [] and "nicht verbunden" in sent[1]["text"]


def test_a_failed_confirmation_is_shown_and_the_owner_must_confirm(monkeypatch):
    _db, sent, _calls = setup(monkeypatch, proposals=None, mail_fails=True)
    answer = asyncio.run(withdrawals.submit(payload()))
    assert answer["mail_sent"] is False
    assert len(sent) == 1 and "NICHT verschickt" in sent[0]["text"]


def test_without_the_owner_notice_the_record_stays(monkeypatch):
    db, _sent, _calls = setup(monkeypatch, proposals=None, owner=())
    asyncio.run(withdrawals.submit(payload()))
    stored = db.withdrawals.docs[0]
    assert stored["owner_told"] is False and stored["name"] == "Max Muster"


def test_the_same_click_twice_is_one_declaration(monkeypatch):
    db, sent, _calls = setup(monkeypatch, proposals=None)
    first = asyncio.run(withdrawals.submit(payload()))
    second = asyncio.run(withdrawals.submit(payload()))
    assert first == second and len(db.withdrawals.docs) == 1 and len(sent) == 2


def test_what_the_sender_typed_never_becomes_markup_in_dolibarr(monkeypatch):
    _db, _sent, calls = setup(monkeypatch, proposals=[{"id": "5", "status": "2", "note_private": ""}])
    asyncio.run(withdrawals.submit(payload(name="<b>Max</b>", scope="part", parts="<script>x</script>")))
    note = json.loads(calls[2].content)["note_private"]
    assert "<script>" not in note and "&lt;script&gt;" in note and "&lt;b&gt;Max" in note


@pytest.mark.parametrize("contract", ["AN' OR 1", "AN)(x", "", "A", "a" * 41, "AN 2026"])
def test_the_number_cannot_reach_the_filter_as_anything_else(contract):
    with pytest.raises(ValidationError):
        payload(contract=contract)


def test_a_partial_withdrawal_needs_the_part():
    with pytest.raises(ValidationError):
        payload(scope="part", parts="")


def test_the_form_is_limited_per_address():
    from server import PublicRequestGuardMiddleware

    limit, window, size = PublicRequestGuardMiddleware.RULES[("POST", "/api/withdrawals")]
    assert limit <= 10 and window >= 3600 and size <= 16 * 1024


def test_the_acceptance_mail_names_the_online_withdrawal():
    _subject, text, html = portal.acceptance_mail(ref="AN202611-0001", total="100", name="Max", when="26.11.2026 10:00",
                                                  start_now=False, company=COMPANY, site_base="https://it.example.com")
    link = "https://it.example.com/rechtliches/widerruf?vertrag=AN202611-0001#vertrag-widerrufen"
    assert "Vertrag widerrufen" in text and link in text and link in html

import asyncio
import json

import httpx
import pytest
from bson import ObjectId
from fastapi import HTTPException
from pydantic import ValidationError

from app import dolibarr
from app.models import InquiryInput
from app.routers import repairs


CFG = {
    "base": "https://erp.example.test",
    "api_key": "top-secret-key",
    "country_code": "AT",
    "timeout": 8,
}
CONTACT = {
    "name": "Max Muster",
    "email": "max@example.com",
    "phone": "+43 1 234",
    "preferred_contact": "email",
}


def _run_sync(handler, previous=None, track_id=None):
    async def run():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            return await dolibarr._sync_ticket_with_client(
                client,
                CFG,
                subject="Reparatur: PC (ANF-12345678)",
                message="Vollständige Anfrage",
                contact=CONTACT,
                previous=previous,
                track_id=track_id,
            )

    return asyncio.run(run())


def test_dolibarr_reuses_prospect_found_by_official_email_endpoint():
    requests = []

    def handler(request: httpx.Request):
        requests.append(request)
        if request.method == "GET":
            assert "/thirdparties/email/max" in request.url.path
            return httpx.Response(200, json={"id": "42"})
        assert request.url.path.endswith("/tickets")
        return httpx.Response(200, json=501)

    result = _run_sync(handler)

    assert result["synced"] is True
    assert result["thirdparty_id"] == "42"
    assert result["ticket_id"] == "501"
    assert not any(r.method == "POST" and r.url.path.endswith("/thirdparties") for r in requests)
    ticket = json.loads(requests[-1].content)
    assert ticket == {
        "subject": "Reparatur: PC (ANF-12345678)",
        "message": "Vollständige Anfrage",
        "fk_soc": "42",
        "origin_email": "max@example.com",
    }


def test_dolibarr_creates_prospect_after_404_then_creates_ticket():
    posted = []

    def handler(request: httpx.Request):
        if request.method == "GET":
            return httpx.Response(404, json={"error": {"message": "not found"}})
        posted.append((request.url.path, json.loads(request.content)))
        if request.url.path.endswith("/thirdparties"):
            return httpx.Response(200, json={"rowid": 77})
        return httpx.Response(200, json="T-88")

    result = _run_sync(handler)

    assert result["synced"] is True
    assert result["thirdparty_id"] == "77"
    thirdparty = posted[0][1]
    assert thirdparty["client"] == 2
    assert thirdparty["code_client"] == "-1"
    assert thirdparty["email"] == CONTACT["email"]
    assert posted[1][1]["fk_soc"] == "77"


def test_business_details_fill_new_dolibarr_prospect():
    contact = {
        **CONTACT,
        "contact_type": "business",
        "company_name": "Mustertechnik GmbH",
        "address": "Testgasse 12",
        "postal_code": "1010",
        "city": "Wien",
        "country_code": "AT",
        "website": "https://muster.example",
        "vat_id": "ATU12345678",
        "company_registration": "FN 123456a",
        "tax_number": "12-345/6789",
        "court": "Handelsgericht Wien",
        "eori": "ATEOS1234567890",
    }
    posted = {}

    def handler(request: httpx.Request):
        posted.update(json.loads(request.content))
        return httpx.Response(200, json=77)

    async def run():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            return await dolibarr._create_thirdparty(client, CFG, contact)

    assert asyncio.run(run()) == "77"
    assert posted["name"] == "Mustertechnik GmbH"
    assert posted["address"] == "Testgasse 12"
    assert posted["zip"] == "1010"
    assert posted["town"] == "Wien"
    assert posted["phone_mobile"] == CONTACT["phone"]
    assert posted["tva_intra"] == "ATU12345678"
    assert posted["idprof1"] == "12-345/6789"
    assert posted["idprof2"] == "Handelsgericht Wien"
    assert posted["idprof3"] == "FN 123456a"
    assert posted["idprof5"] == "ATEOS1234567890"
    assert posted["client"] == 2


def test_ticket_classification_and_public_url_are_safe_and_configurable():
    cfg = {
        **CFG,
        "public_ticket_enabled": True,
        "ticket_categories": {"controller_custom": "controller"},
    }
    assert dolibarr._ticket_classification({"request_type": "controller_custom"}, cfg) == {
        "type_code": "REQUEST",
        "severity_code": "NORMAL",
        "category_code": "CONTROLLER",
    }
    url = dolibarr._public_ticket_url(cfg, "ITANF123", "max+portal@example.com")
    assert url == (
        "https://erp.example.test/public/ticket/view.php?"
        "track_id=ITANF123&email=max%2Bportal%40example.com"
    )
    assert dolibarr._public_ticket_url({**cfg, "public_ticket_enabled": False}, "x", "a@b.at") is None


def test_dolibarr_error_is_structured_and_never_leaks_api_key():
    def handler(_request: httpx.Request):
        return httpx.Response(
            403,
            json={"error": {"message": f"Forbidden for {CFG['api_key']}"}},
        )

    result = _run_sync(handler)

    assert result["synced"] is False
    assert result["stage"] == "thirdparty_lookup"
    assert result["http_status"] == 403
    assert result["error"]["type"] == "HTTPStatusError"
    assert CFG["api_key"] not in str(result)


def test_connection_accepts_dolibarr_empty_thirdparties_404(monkeypatch):
    async def config():
        return {**CFG, "enabled": True}

    def handler(request: httpx.Request):
        if request.url.path.endswith("/thirdparties"):
            return httpx.Response(
                404,
                json={"error": {"message": "No third parties found"}},
            )
        return httpx.Response(200, json=[])

    real_async_client = httpx.AsyncClient
    transport = httpx.MockTransport(handler)

    def client_factory(*_args, **kwargs):
        return real_async_client(transport=transport, timeout=kwargs.get("timeout"))

    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(dolibarr.httpx, "AsyncClient", client_factory)

    result = asyncio.run(dolibarr.test_connection())

    assert result["connected"] is True
    assert result["checks"] == {"api": True, "thirdparties": True, "tickets": True}


def test_connection_does_not_treat_arbitrary_thirdparties_404_as_empty(monkeypatch):
    async def config():
        return {**CFG, "enabled": True}

    def handler(request: httpx.Request):
        if request.url.path.endswith("/thirdparties"):
            return httpx.Response(404, json={"error": {"message": "Route not found"}})
        return httpx.Response(200, json=[])

    real_async_client = httpx.AsyncClient
    transport = httpx.MockTransport(handler)

    def client_factory(*_args, **kwargs):
        return real_async_client(transport=transport, timeout=kwargs.get("timeout"))

    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(dolibarr.httpx, "AsyncClient", client_factory)

    result = asyncio.run(dolibarr.test_connection())

    assert result["connected"] is False
    assert result["http_status"] == 404


def test_inquiry_sync_handles_config_read_failure(monkeypatch):
    async def broken_config():
        raise RuntimeError("settings database unavailable")

    monkeypatch.setattr(dolibarr, "get_config", broken_config)

    inquiry_result = asyncio.run(dolibarr.create_ticket_for_inquiry({
        "ref": "ANF-CONFIG01",
        "contact": CONTACT,
    }))
    assert inquiry_result["synced"] is False
    assert inquiry_result["stage"] == "configuration"
    assert inquiry_result["error"]["type"] == "RuntimeError"


def test_dolibarr_retry_reuses_locally_persisted_thirdparty():
    requests = []

    def handler(request: httpx.Request):
        requests.append(request)
        assert request.method == "POST"
        assert request.url.path.endswith("/tickets")
        return httpx.Response(200, json=99)

    result = _run_sync(handler, previous={
        "synced": False,
        "stage": "ticket_create",
        "thirdparty_id": "12",
    })

    assert result["synced"] is True
    assert result["thirdparty_id"] == "12"
    assert len(requests) == 1


def test_retry_recovers_ticket_after_lost_post_response_without_duplicate():
    phase = {"value": "create"}
    posts = []

    def handler(request: httpx.Request):
        if request.url.path.endswith("/tickets/track_id/ITANF12345678"):
            if phase["value"] == "create":
                return httpx.Response(404, json={"error": {"message": "not found"}})
            return httpx.Response(200, json={
                "id": 501,
                "ref": "TCK-501",
                "track_id": "ITANF12345678",
                "fk_soc": 42,
            })
        if request.method == "GET":
            return httpx.Response(200, json={"id": 42})
        posts.append(json.loads(request.content))
        raise httpx.ReadTimeout("response lost after remote commit", request=request)

    first = _run_sync(handler, track_id="ITANF12345678")
    assert first["synced"] is False
    assert first["stage"] == "ticket_create"
    assert first["thirdparty_id"] == "42"
    assert posts[0]["track_id"] == "ITANF12345678"

    phase["value"] = "retry"
    second = _run_sync(
        handler,
        previous=first,
        track_id="ITANF12345678",
    )
    assert second["synced"] is True
    assert second["recovered"] is True
    assert second["ticket_id"] == "501"
    assert second["ticket_ref"] == "TCK-501"
    assert len(posts) == 1


def test_already_synced_dolibarr_request_is_a_noop():
    def handler(_request: httpx.Request):
        raise AssertionError("No remote request expected")

    result = _run_sync(handler, previous={
        "synced": True,
        "thirdparty_id": "12",
        "ticket_id": "99",
    })

    assert result["synced"] is True
    assert result["ticket_id"] == "99"


def test_inquiry_ticket_message_contains_all_customer_choices():
    message = dolibarr.format_inquiry_message({
        "ref": "ANF-ABCDEFGH",
        "request_id": "browser:12345678",
        "request_type": "controller_custom",
        "source": "website",
        "device_type": "PS5 DualSense Edge",
        "manufacturer": "Sony",
        "model": "CFI-ZCP1",
        "issues": ["Stick-Drift"],
        "desired_services": ["Hall-Effect-Sticks", "Neue Tasten"],
        "budget": "bis 200 €",
        "timeframe": "2–3 Wochen",
        "description": "Bitte vorher anrufen.",
        "attachment_ids": ["media-1"],
        "contact": CONTACT,
    })

    for expected in (
        "ANF-ABCDEFGH", "Controller-Umbau", "PS5 DualSense Edge", "Sony",
        "CFI-ZCP1", "Stick-Drift", "Hall-Effect-Sticks", "Neue Tasten",
        "bis 200 €", "2–3 Wochen", "Bitte vorher anrufen.", "media-1",
        "Max Muster", "browser:12345678",
    ):
        assert expected in message


def test_inquiry_model_limits_attachments_and_request_types():
    base = {
        "contact": CONTACT,
        "consent": True,
        "description": "Ausführliche Testbeschreibung",
        "device_type": "pc",
        "request_id": "request-12345678",
    }
    valid = InquiryInput(
        **base,
        request_type="pc_build",
        attachment_ids=[" 1 ", "1", "2"],
    )
    assert valid.attachment_ids == ["1", "2"]

    with pytest.raises(ValidationError):
        InquiryInput(**base, request_type="unsupported")
    with pytest.raises(ValidationError):
        InquiryInput(**base, attachment_ids=[str(index) for index in range(6)])


def test_business_contact_requires_company_and_normalizes_optional_data():
    base = {
        "consent": True,
        "description": "Ausführliche geschäftliche Anfrage",
        "request_type": "consulting",
        "request_id": "business-request-1234",
    }
    with pytest.raises(ValidationError):
        InquiryInput(**base, contact={**CONTACT, "contact_type": "business"})
    inquiry = InquiryInput(**base, contact={
        **CONTACT,
        "contact_type": "business",
        "company_name": " Muster GmbH ",
        "country_code": "at",
        "website": "https://muster.example",
    })
    assert inquiry.contact.company_name == "Muster GmbH"
    assert inquiry.contact.country_code == "AT"


def test_repeated_request_id_returns_same_local_inquiry_without_second_sync(monkeypatch):
    class InsertResult:
        def __init__(self, inserted_id):
            self.inserted_id = inserted_id

    class RepairRequests:
        def __init__(self):
            self.docs = []

        async def find_one(self, query):
            return next(
                (doc for doc in self.docs if all(doc.get(key) == value for key, value in query.items())),
                None,
            )

        async def insert_one(self, data):
            doc = dict(data)
            doc["_id"] = ObjectId()
            self.docs.append(doc)
            return InsertResult(doc["_id"])

        async def update_one(self, query, update):
            doc = await self.find_one(query)
            if doc:
                doc.update(update["$set"])

    class Database:
        repair_requests = RepairRequests()

    sync_calls = []

    async def sync(inquiry):
        sync_calls.append(inquiry["ref"])
        return {"synced": True, "stage": "complete", "ticket_id": "91"}

    database = Database()
    monkeypatch.setattr(repairs, "get_db", lambda: database)
    monkeypatch.setattr(repairs.dolibarr, "create_ticket_for_inquiry", sync)
    payload = InquiryInput(
        contact=CONTACT,
        consent=True,
        request_id="browser-request-12345678",
        request_type="consulting",
        description="Ich benötige eine ausführliche Beratung.",
    )

    first = asyncio.run(repairs.create_inquiry(payload))
    second = asyncio.run(repairs.create_inquiry(payload))
    changed = payload.model_copy(update={"description": "Eine andere Anfragebeschreibung"})
    with pytest.raises(HTTPException) as conflict:
        asyncio.run(repairs.create_inquiry(changed))

    assert first["id"] == second["id"]
    assert first["ref"] == second["ref"]
    assert first["duplicate"] is False
    assert second["duplicate"] is True
    assert len(database.repair_requests.docs) == 1
    assert sync_calls == [first["ref"]]
    assert conflict.value.status_code == 409

"""Scenarios against a real Dolibarr 24.0.1 (scripts/local_check.py, group "dolibarr").

The local check starts Dolibarr with MariaDB and Mailpit, prepares it with
tests/dolibarr_fixtures/fixtures.php and runs a website server of its own. These
tests connect that website to Dolibarr through the admin settings and check the
results in Dolibarr itself, through an administrator's API key.
"""
import io
import os
import re
import time
import uuid
from datetime import datetime, timedelta, timezone

import pytest
import requests
from PIL import Image

from conftest import BASE_URL

DOLIBARR = os.environ.get("DOLIBARR_TEST_URL", "").rstrip("/")
WEB_KEY = os.environ.get("DOLIBARR_TEST_WEB_KEY", "")
ADMIN_KEY = os.environ.get("DOLIBARR_TEST_ADMIN_KEY", "")
CUSTOMER_ID = os.environ.get("DOLIBARR_TEST_CUSTOMER_ID", "")
CUSTOMER_EMAIL = os.environ.get("DOLIBARR_TEST_CUSTOMER_EMAIL", "")
WORKSHOP_EMAIL = os.environ.get("DOLIBARR_TEST_NOTIFICATION_TO", "")
PUBLIC_URL = os.environ.get("DOLIBARR_TEST_PUBLIC_URL", "")
MAILPIT = os.environ.get("MAILPIT_URL", "").rstrip("/")


def dolibarr_get(path: str, **params):
    response = requests.get(
        f"{DOLIBARR}/api/index.php/{path}",
        headers={"DOLAPIKEY": ADMIN_KEY, "Accept": "application/json"},
        params=params or None, timeout=30,
    )
    response.raise_for_status()
    return response.json()


def dolibarr_put(path: str, body: dict):
    response = requests.put(
        f"{DOLIBARR}/api/index.php/{path}",
        headers={"DOLAPIKEY": ADMIN_KEY, "Accept": "application/json"},
        json=body, timeout=30,
    )
    response.raise_for_status()
    return response.json()


def mails_to(address: str, seconds: int = 30) -> list:
    """Messages Mailpit caught for one recipient, waiting for the first."""
    deadline = time.time() + seconds
    while True:
        found = requests.get(f"{MAILPIT}/api/v1/search", params={"query": f'to:"{address}"'},
                             timeout=30).json().get("messages") or []
        if found or time.time() > deadline:
            return found
        time.sleep(1)


def mail_text(message: dict) -> str:
    """Subject and body; Dolibarr puts the ticket number into the subject only."""
    detail = requests.get(f"{MAILPIT}/api/v1/message/{message['ID']}", timeout=30).json()
    return "\n".join(detail.get(part) or "" for part in ("Subject", "Text", "HTML"))


def png_bytes() -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", (320, 240), "orange").save(buffer, "PNG")
    return buffer.getvalue()


def upload_photo(request_id: str) -> dict:
    response = requests.post(
        f"{BASE_URL}/api/uploads/repair-attachment",
        files={"file": ("defekt.png", png_bytes(), "image/png")},
        data={"request_id": request_id}, timeout=60,
    )
    assert response.status_code == 200, response.text
    return response.json()


def send_inquiry(email: str, *, request_id: str | None = None, attachments=(), **contact) -> dict:
    payload = {
        "request_id": request_id or f"rt-{uuid.uuid4().hex}",
        "request_type": "repair",
        "device_type": "notebook",
        "manufacturer": "Lenovo",
        "model": "ThinkPad T14",
        "description": "Startet seit gestern nicht mehr, Lüfter läuft kurz an.",
        "attachment_ids": list(attachments),
        "consent": True,
        "contact": {"name": "Runtime Kunde", "email": email, "phone": "+43 660 0000000", **contact},
    }
    response = requests.post(f"{BASE_URL}/api/inquiries", json=payload, timeout=120)
    assert response.status_code == 200, response.text
    data = response.json()
    assert data["dolibarr_synced"] is True, data
    return data


def ticket_for(ref: str, thirdparty_id) -> dict:
    tickets = dolibarr_get("tickets", sqlfilters=f"(t.fk_soc:=:{int(thirdparty_id)})", limit=50)
    return next(ticket for ticket in tickets if ref in str(ticket.get("subject")))


@pytest.fixture(scope="module", autouse=True)
def connected(admin_client):
    """Connect the website to the test Dolibarr the way the owner does."""
    response = admin_client.put(f"{BASE_URL}/api/admin/settings", json={
        "dolibarr_enabled": True,
        "dolibarr_base_url": DOLIBARR,
        "dolibarr_api_key": WEB_KEY,
        "dolibarr_timeout_seconds": 20,
    }, timeout=30)
    assert response.status_code == 200, response.text
    status = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/status", timeout=60).json()
    assert status["connection"]["connected"] is True, status["connection"]
    yield


class TestNewCustomer:
    def test_inquiry_becomes_prospect_ticket_with_photo_and_confirmation(self):
        email = f"neukunde-{uuid.uuid4().hex[:10]}@example.com"
        request_id = f"rt-{uuid.uuid4().hex}"
        photo = upload_photo(request_id)
        # The draft preview works for its own form only (#37).
        assert requests.get(f"{BASE_URL}{photo['url']}", params={"request_id": request_id},
                            timeout=30).status_code == 200
        assert requests.get(f"{BASE_URL}{photo['url']}", timeout=30).status_code == 404

        created = send_inquiry(email, request_id=request_id, attachments=[photo["id"]])

        party = dolibarr_get(f"thirdparties/email/{email}")
        assert int(party["client"]) == 2, "a new sender becomes a prospect"
        ticket = ticket_for(created["ref"], party["id"])
        documents = dolibarr_get("documents", modulepart="ticket", id=ticket["id"])
        assert len(documents) == 1 and documents[0]["name"].endswith(".webp"), documents
        # The photo left the website with the hand-over (#37).
        assert requests.get(f"{BASE_URL}{photo['url']}", params={"request_id": request_id},
                            timeout=30).status_code == 404

        # Dolibarr's own confirmation, linking to the website's status view (#40).
        confirmations = mails_to(email)
        assert confirmations, "Dolibarr sent no confirmation to the customer"
        text = mail_text(confirmations[0])
        assert PUBLIC_URL + "view.php?track_id=" in text, text[:800]
        # The website's own random tracking id (IT + 14 characters), not Dolibarr's.
        track_id = re.search(r"track_id=(IT[A-Z0-9]{14})", text).group(1)
        workshop = [mail_text(item) for item in mails_to(WORKSHOP_EMAIL)]
        assert any(ticket["ref"] in text for text in workshop), "the workshop got no notice of this ticket"

        # The status view: by number and e-mail, and by the link in the mail (#43).
        by_number = requests.post(f"{BASE_URL}/api/inquiries/status",
                                  json={"ref": created["ref"], "email": email.upper()}, timeout=30)
        assert by_number.status_code == 200, by_number.text
        assert by_number.json()["step"] == "eingegangen"
        assert email not in by_number.text
        by_link = requests.get(f"{BASE_URL}/api/inquiries/status/track/{track_id}", timeout=30)
        assert by_link.status_code == 200 and by_link.json()["ref"] == created["ref"]
        stranger = requests.post(f"{BASE_URL}/api/inquiries/status",
                                 json={"ref": created["ref"], "email": "fremd@example.com"}, timeout=30)
        assert stranger.status_code == 404


class TestExistingCustomer:
    def test_form_never_changes_an_existing_customer(self):
        """Analysis S2, proven against Dolibarr (#36)."""
        fields = ("name", "address", "zip", "town", "phone", "email", "tva_intra", "idprof1")
        before = dolibarr_get(f"thirdparties/{CUSTOMER_ID}")

        created = send_inquiry(
            CUSTOMER_EMAIL, contact_type="business", company_name="Irgendwas Anderes GmbH",
            address="Falsche Gasse 99", postal_code="1010", city="Wien", vat_id="ATU99999999",
            tax_number="99-999/9999",
        )

        after = dolibarr_get(f"thirdparties/{CUSTOMER_ID}")
        assert {field: after.get(field) for field in fields} == {field: before.get(field) for field in fields}
        ticket = ticket_for(created["ref"], CUSTOMER_ID)
        message = str(ticket.get("message"))
        assert "schon vorhanden und wurde nicht geändert" in message
        assert "Irgendwas Anderes GmbH" in message and "ATU99999999" in message


class TestStatusFollowsDolibarr:
    def test_status_changes_when_the_ticket_moves_on(self):
        email = f"status-{uuid.uuid4().hex[:10]}@example.com"
        created = send_inquiry(email)
        party = dolibarr_get(f"thirdparties/email/{email}")
        ticket = ticket_for(created["ref"], party["id"])

        # Dolibarr 24 writes the state from "status"; "fk_statut" is only read.
        dolibarr_put(f"tickets/{ticket['id']}", {"status": 3})

        status = requests.post(f"{BASE_URL}/api/inquiries/status",
                               json={"ref": created["ref"], "email": email}, timeout=30)
        assert status.status_code == 200 and status.json()["step"] == "in_arbeit", status.text
        # Dolibarr's Unix seconds arrive as a real point in time, not hours off.
        updated = datetime.fromisoformat(status.json()["updated_at"])
        assert abs(datetime.now(timezone.utc) - updated) < timedelta(minutes=10), status.text

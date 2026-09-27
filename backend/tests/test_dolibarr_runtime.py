"""Scenarios against a real Dolibarr 24.0.1 (scripts/local_check.py, group "dolibarr").

The local check starts Dolibarr with MariaDB and Mailpit, prepares it with
tests/dolibarr_fixtures/fixtures.php and runs a website server of its own. These
tests connect that website to Dolibarr through the admin settings and check the
results in Dolibarr itself, through an administrator's API key.
"""
import hashlib
import io
import json
import os
import re
import secrets
import string
import subprocess
import time
import uuid
from datetime import datetime, timedelta, timezone

import pytest
import requests
from PIL import Image
from pymongo import MongoClient

from conftest import BASE_URL

DOLIBARR = os.environ.get("DOLIBARR_TEST_URL", "").rstrip("/")
WEB_KEY = os.environ.get("DOLIBARR_TEST_WEB_KEY", "")
ADMIN_KEY = os.environ.get("DOLIBARR_TEST_ADMIN_KEY", "")
CUSTOMER_ID = os.environ.get("DOLIBARR_TEST_CUSTOMER_ID", "")
CUSTOMER_EMAIL = os.environ.get("DOLIBARR_TEST_CUSTOMER_EMAIL", "")
WORKSHOP_EMAIL = os.environ.get("DOLIBARR_TEST_NOTIFICATION_TO", "")
PUBLIC_URL = os.environ.get("DOLIBARR_TEST_PUBLIC_URL", "")
MAILPIT = os.environ.get("MAILPIT_URL", "").rstrip("/")
MAILPIT_SMTP_PORT = int(os.environ.get("MAILPIT_SMTP_PORT") or 0)
WARNINGS_TO = "werkstatt-warnung@example.com"
WEB_USER = os.environ.get("DOLIBARR_TEST_WEB_USER", "")
CONTENT = json.loads(os.environ.get("DOLIBARR_TEST_CONTENT") or "{}")
FIXTURE_COMMAND = json.loads(os.environ.get("DOLIBARR_TEST_FIXTURE_COMMAND") or "[]")


def website_db():
    """The website's own test database, to age records and plant old ones."""
    return MongoClient(os.environ["MONGO_URL"], tz_aware=True)[os.environ["DB_NAME"]]


def dolibarr_get(path: str, **params):
    response = requests.get(
        f"{DOLIBARR}/api/index.php/{path}",
        headers={"DOLAPIKEY": ADMIN_KEY, "Accept": "application/json"},
        params=params or None, timeout=30,
    )
    response.raise_for_status()
    return response.json()


def dolibarr_post(path: str, body: dict):
    response = requests.post(
        f"{DOLIBARR}/api/index.php/{path}",
        headers={"DOLAPIKEY": ADMIN_KEY, "Accept": "application/json"},
        json=body, timeout=30,
    )
    assert response.status_code == 200, response.text
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


def send_inquiry(email: str, *, request_id: str | None = None, attachments=(), expect_synced=True,
                 extra=None, **contact) -> dict:
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
        **(extra or {}),
    }
    response = requests.post(f"{BASE_URL}/api/inquiries", json=payload, timeout=120)
    assert response.status_code == 200, response.text
    data = response.json()
    assert data["dolibarr_synced"] is expect_synced, data
    return data


def ticket_for(ref: str, thirdparty_id) -> dict:
    tickets = dolibarr_get("tickets", sqlfilters=f"(t.fk_soc:=:{int(thirdparty_id)})", limit=50)
    return next(ticket for ticket in tickets if ref in str(ticket.get("subject")))


def ticket_with(ref: str) -> dict:
    """The newest ticket naming a reference, with or without third party."""
    tickets = dolibarr_get("tickets", sortfield="t.rowid", sortorder="DESC", limit=100)
    return next(ticket for ticket in tickets if ref in str(ticket.get("subject")))


def no_third_party(ticket: dict) -> bool:
    return str(ticket.get("fk_soc") or "0") in ("0", "", "-1")


def third_party_exists(email: str) -> bool:
    response = requests.get(f"{DOLIBARR}/api/index.php/thirdparties/email/{email}",
                            headers={"DOLAPIKEY": ADMIN_KEY, "Accept": "application/json"}, timeout=30)
    return response.status_code == 200


def use_mailpit_for_website_mail(admin_client):
    response = admin_client.put(f"{BASE_URL}/api/admin/settings", json={
        "smtp_host": "127.0.0.1", "smtp_port": MAILPIT_SMTP_PORT, "smtp_security": "none",
        "smtp_username": "", "smtp_password": "geheim-nie-zeigen",
        "smtp_from": "website@it-tabelander.example.com", "smtp_from_name": "IT-Tabelander Website",
        "warning_email": WARNINGS_TO,
    }, timeout=30)
    assert response.status_code == 200, response.text
    return response.json()


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


class TestWebsiteMail:
    def test_test_mail_arrives_and_the_password_stays_secret(self, admin_client):
        """#38: the button proves the SMTP settings; the password never comes back."""
        shown = use_mailpit_for_website_mail(admin_client)
        assert "smtp_password" not in shown and shown["smtp_password_configured"] is True
        assert "geheim-nie-zeigen" not in admin_client.get(f"{BASE_URL}/api/admin/settings", timeout=30).text

        sent = admin_client.post(f"{BASE_URL}/api/admin/settings/test-mail",
                                 json={"to": "test-empfang@example.com"}, timeout=60)
        assert sent.status_code == 200, sent.text
        received = mails_to("test-empfang@example.com")
        assert len(received) == 1 and received[0]["Subject"] == "Test-Mail der Website"
        assert "IT-Tabelander-Website" in mail_text(received[0])


class TestQueue:
    def test_an_inquiry_waits_warns_once_and_arrives_when_dolibarr_is_back(self, admin_client):
        """#39 and #41: Dolibarr unreachable (refused connection, as with a
        stopped server), the queue retries, one warning, then hand-over and
        the personal data is gone from the website."""
        use_mailpit_for_website_mail(admin_client)
        db = website_db()
        email = f"warten-{uuid.uuid4().hex[:10]}@example.com"
        down = admin_client.put(f"{BASE_URL}/api/admin/settings",
                                json={"dolibarr_base_url": "http://127.0.0.1:9"}, timeout=30)
        assert down.status_code == 200, down.text
        try:
            created = send_inquiry(email, expect_synced=False)
            # Half an hour later, as far as the website knows.
            db.repair_requests.update_one(
                {"ref": created["ref"]},
                {"$set": {"created_at": datetime.now(timezone.utc) - timedelta(minutes=31)}},
            )
            first = admin_client.post(f"{BASE_URL}/api/admin/dolibarr/queue/run", timeout=120).json()
            assert first["tried"] >= 1 and first["synced"] == 0 and first["warned"] >= 1, first
            second = admin_client.post(f"{BASE_URL}/api/admin/dolibarr/queue/run", timeout=120).json()
            assert second["warned"] == 0, second

            overview = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/queue", timeout=30).json()
            item = next(entry for entry in overview["items"] if entry["ref"] == created["ref"])
            assert item["attempts"] >= 3 and item["warned_at"], item
            assert "nicht erreichbar" in item["reason"], item
            warnings = mails_to(WARNINGS_TO)
            assert len(warnings) == 1, [message["Subject"] for message in warnings]
            assert created["ref"] in mail_text(warnings[0])
        finally:
            back = admin_client.put(f"{BASE_URL}/api/admin/settings",
                                    json={"dolibarr_base_url": DOLIBARR}, timeout=30)
            assert back.status_code == 200, back.text

        done = admin_client.post(f"{BASE_URL}/api/admin/dolibarr/queue/run", timeout=120).json()
        assert done["synced"] >= 1, done
        party = dolibarr_get(f"thirdparties/email/{email}")
        ticket_for(created["ref"], party["id"])
        assert len(mails_to(WARNINGS_TO, seconds=2)) == 1, "the warning came more than once"

        stored = db.repair_requests.find_one({"ref": created["ref"]})
        for field in ("contact", "description", "manufacturer", "model", "attachments", "device_type"):
            assert field not in stored, field
        assert stored["dolibarr"]["ticket_id"] and stored["personal_data_removed_at"]
        # The customer still finds the status: Dolibarr knows the e-mail.
        status = requests.post(f"{BASE_URL}/api/inquiries/status",
                               json={"ref": created["ref"], "email": email}, timeout=30)
        assert status.status_code == 200, status.text


class TestMigration:
    def test_old_records_move_after_a_dry_run_and_without_mails(self, admin_client):
        """#41: old inquiries and old contact messages go to Dolibarr once,
        without a confirmation to customers who wrote weeks ago."""
        db = website_db()
        old_email = f"altkunde-{uuid.uuid4().hex[:10]}@example.com"
        old_sender = f"altnachricht-{uuid.uuid4().hex[:10]}@example.com"
        ref = "ANF-" + "".join(secrets.choice(string.ascii_uppercase + string.digits) for _ in range(8))
        summer = datetime(2026, 7, 1, 10, 0, tzinfo=timezone.utc)
        db.repair_requests.insert_one({
            "ref": ref, "request_id": f"alt-{uuid.uuid4().hex}", "request_type": "repair",
            "device_type": "notebook", "description": "Alte Anfrage aus dem Sommer.",
            "contact": {"name": "Alt Kunde", "email": old_email, "phone": "+43 1 000"},
            "consent": True, "status": "eingegangen", "attachments": [],
            "dolibarr": {"synced": False, "stage": "ticket_create", "error": {"message": "HTTP 500"}},
            "created_at": summer, "updated_at": summer,
        })
        message_id = db.contact_messages.insert_one({
            "name": "Alte Nachricht", "email": old_sender, "phone": "", "subject": "Öffnungszeiten",
            "message": "Habt ihr im August offen?", "consent": True, "status": "neu", "created_at": summer,
        }).inserted_id
        message_ref = "ALT-" + hashlib.sha256(str(message_id).encode()).hexdigest()[:8].upper()

        plan = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/migration", timeout=30).json()
        refs = {item["ref"] for item in plan["items"]}
        assert ref in refs and message_ref in refs, plan
        assert db.repair_requests.find_one({"ref": ref})["contact"]["email"] == old_email, "the dry run changed data"

        assert plan["faqs"] >= 1 and any(item["request_type"] == "faq" for item in plan["items"]), plan

        result = admin_client.post(f"{BASE_URL}/api/admin/dolibarr/migration", timeout=300).json()
        assert result["sent"] >= 1 and result["contact_messages"] >= 1 and not result["failed"], result
        # The website's own FAQ arrived as drafts in the knowledge base (#81).
        assert result["faqs"] >= 1, result
        records = dolibarr_get("knowledgemanagement/knowledgerecords", limit=200)
        copied = [record for record in records if record.get("question") == "Wie lange dauert eine Reparatur?"]
        assert copied and all(int(record["status"]) == 0 for record in copied), records

        party = dolibarr_get(f"thirdparties/email/{old_email}")
        ticket_for(ref, party["id"])
        old_message = ticket_with(message_ref)
        assert no_third_party(old_message) and old_message["origin_email"] == old_sender
        assert "Habt ihr im August offen?" in str(old_message["message"])
        time.sleep(3)
        assert mails_to(old_email, seconds=0) == [] and mails_to(old_sender, seconds=0) == []

        assert "contact" not in db.repair_requests.find_one({"ref": ref})
        assert db.contact_messages.count_documents({"_id": message_id}) == 0
        again = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/migration", timeout=30).json()
        assert not {ref, message_ref} & {item["ref"] for item in again["items"]}


def send_contact(email: str, **extra) -> requests.Response:
    return requests.post(f"{BASE_URL}/api/contact", json={
        "request_id": f"rt-{uuid.uuid4().hex}", "name": "Kontakt Person", "email": email,
        "message": "Habt ihr am Samstag geöffnet?", "consent": True, **extra,
    }, timeout=120)


class TestContactForm:
    def test_a_message_becomes_a_ticket_without_a_new_third_party(self):
        """#42."""
        email = f"kontakt-{uuid.uuid4().hex[:10]}@example.com"
        response = send_contact(email)
        assert response.status_code == 200, response.text
        created = response.json()
        assert created["dolibarr_synced"] is True, created
        ticket = ticket_with(created["ref"])
        assert no_third_party(ticket) and ticket["origin_email"] == email
        assert "Habt ihr am Samstag geöffnet?" in str(ticket["message"])
        assert not third_party_exists(email)
        assert mails_to(email), "Dolibarr sent the sender no confirmation"

    def test_a_known_customer_is_linked_and_left_unchanged(self):
        before = dolibarr_get(f"thirdparties/{CUSTOMER_ID}")
        response = send_contact(CUSTOMER_EMAIL)
        assert response.status_code == 200, response.text
        ticket = ticket_for(response.json()["ref"], CUSTOMER_ID)
        assert "Habt ihr am Samstag geöffnet?" in str(ticket["message"])
        after = dolibarr_get(f"thirdparties/{CUSTOMER_ID}")
        assert {key: after.get(key) for key in ("name", "email", "phone", "address")} == \
            {key: before.get(key) for key in ("name", "email", "phone", "address")}


class TestCallback:
    def test_the_wished_time_is_a_phone_call_in_the_agenda(self):
        """#73."""
        vienna = timezone(timedelta(hours=2))
        wish = (datetime.now(vienna) + timedelta(days=2)).replace(hour=10, minute=30, second=0, microsecond=0)
        email = f"rueckruf-{uuid.uuid4().hex[:10]}@example.com"
        created = send_inquiry(email, extra={"callback_at": wish.isoformat()})
        party = dolibarr_get(f"thirdparties/email/{email}")
        ticket = ticket_for(created["ref"], party["id"])
        assert "Rückruf gewünscht" in str(ticket["message"])
        events = dolibarr_get("agendaevents", sqlfilters=f"(t.fk_element:=:{int(ticket['id'])}) "
                                                         "and (t.elementtype:=:'ticket')")
        calls = [event for event in events if event.get("type_code") == "AC_TEL"]
        assert len(calls) == 1, events
        assert int(calls[0]["datep"]) == int(wish.timestamp())
        assert str(calls[0]["socid"]) == str(party["id"]) and created["ref"] in calls[0]["label"]

    def test_a_callback_without_phone_is_refused(self):
        wish = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
        response = send_contact(f"ohne-telefon-{uuid.uuid4().hex[:8]}@example.com", callback_at=wish)
        assert response.status_code == 422, response.text


def run_fixture(stage: str) -> dict:
    completed = subprocess.run([*FIXTURE_COMMAND, stage], capture_output=True, text=True, timeout=120)
    assert completed.returncode == 0, completed.stderr
    return json.loads(completed.stdout[completed.stdout.index("{"):])


@pytest.fixture(scope="module")
def website_content(admin_client):
    """The owner picks the website category and the legal articles (#74, #81)."""
    response = admin_client.put(f"{BASE_URL}/api/admin/settings", json={
        "dolibarr_content_category_id": CONTENT["category"],
        "dolibarr_imprint_article_id": CONTENT["imprint"],
        "dolibarr_privacy_article_id": CONTENT["privacy"],
        "dolibarr_terms_article_id": CONTENT["terms"],
    }, timeout=30)
    assert response.status_code == 200, response.text
    return CONTENT


class TestSiteDataFromDolibarr:
    def test_company_data_and_opening_hours(self, website_content):
        info = requests.get(f"{BASE_URL}/api/site-info", timeout=60).json()
        company = info["company"]
        assert company["name"] == "IT-Tabelander Test" and company["managers"] == "Test Inhaber"
        assert company["zip"] == "6020" and company["tva_intra"] == "ATU22222222"
        assert "note_private" not in company and "Interne Firmennotiz" not in json.dumps(info)
        assert {"day": "Montag", "hours": "09:00–17:00"} in info["opening_hours"]
        assert {"day": "Freitag", "hours": "09:00–12:00"} in info["opening_hours"]
        # The current website reads the same through /api/settings.
        settings = requests.get(f"{BASE_URL}/api/settings", timeout=60).json()
        assert settings["company_name"] == "IT-Tabelander Test" and settings["postal_code"] == "6020"

    def test_a_new_address_in_dolibarr_shows_in_the_imprint(self, admin_client, website_content):
        before = requests.get(f"{BASE_URL}/api/legal/impressum", timeout=60).json()
        assert "Werkstattweg 1" in before["html"] and "Aufsichtsbehörde: Test-BH" in before["html"]
        assert "Firmenbuchnummer: FN 222222a" in before["html"] and before["draft"] is False
        run_fixture("move")
        overview = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/content", timeout=60).json()
        assert overview["errors"] == {}, overview["errors"]
        after = requests.get(f"{BASE_URL}/api/legal/impressum", timeout=60).json()
        assert "Neue Gasse 7" in after["html"] and "Werkstattweg 1" not in after["html"]
        assert "Neue Gasse 7" in requests.get(f"{BASE_URL}/api/settings", timeout=60).json()["impressum_html"]

    def test_a_draft_says_so_a_released_text_does_not(self, website_content):
        privacy = requests.get(f"{BASE_URL}/api/legal/datenschutz", timeout=60).json()
        assert privacy["draft"] is False and "Test-Datenschutztext" in privacy["html"]
        terms = requests.get(f"{BASE_URL}/api/legal/nutzungsbedingungen", timeout=60).json()
        assert terms["draft"] is True and "Test-Nutzungsbedingungen" in terms["html"]

    def test_faq_shows_only_released_website_articles(self, website_content):
        faqs = requests.get(f"{BASE_URL}/api/faqs", timeout=60).json()
        assert [faq["question"] for faq in faqs] == ["Holt ihr Geräte auch ab?"], faqs
        assert "<script" not in faqs[0]["answer_html"] and faqs[0]["answer"] == "Ja, im Raum Innsbruck."

    def test_the_website_user_has_no_admin_rights(self, admin_client, website_content):
        assert int(dolibarr_get(f"users/{WEB_USER}")["admin"]) == 0
        overview = admin_client.get(f"{BASE_URL}/api/admin/dolibarr/content", timeout=60).json()
        assert {"id": CONTENT["category"], "label": "Website"} in overview["categories"]
        by_id = {article["id"]: article for article in overview["articles"]}
        assert by_id[CONTENT["terms"]]["status"] == 0 and CONTENT["internal"] not in by_id
        assert overview["faq_count"] == 1


class TestStatusSteps:
    def test_an_offer_waits_then_the_device_is_ready_for_pickup(self):
        """#78."""
        email = f"abholen-{uuid.uuid4().hex[:10]}@example.com"
        created = send_inquiry(email)
        party = dolibarr_get(f"thirdparties/email/{email}")
        ticket = ticket_for(created["ref"], party["id"])

        def step():
            response = requests.post(f"{BASE_URL}/api/inquiries/status",
                                     json={"ref": created["ref"], "email": email}, timeout=30)
            assert response.status_code == 200, response.text
            return response.json()["step"]

        proposal = dolibarr_post("proposals", {
            "socid": int(party["id"]), "date": int(time.time()),
            "linkedObjectsIds": {"ticket": [int(ticket["id"])]},
        })
        dolibarr_post(f"proposals/{proposal}/lines", {"desc": "Reparatur laut Diagnose", "qty": 1,
                                                      "subprice": 80, "tva_tx": 20, "product_type": 1})
        assert step() == "eingegangen", "a draft offer is not ready yet"
        dolibarr_post(f"proposals/{proposal}/validate", {"notrigger": 0})
        assert step() == "angebot_bereit"

        dolibarr_put(f"tickets/{ticket['id']}", {"array_options": {"options_abholbereit": 1}})
        assert step() == "abholbereit"

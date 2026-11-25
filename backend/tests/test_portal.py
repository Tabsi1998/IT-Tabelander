"""Milestone 5: the customer area (#63-#66)."""
import asyncio
import json
from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qs, urlparse

import httpx
import pytest
from pydantic import ValidationError

from app import dolibarr, mailer, portal
from app.models import SettingsInput, check_iban

REAL_CLIENT = httpx.AsyncClient
CFG = {"enabled": True, "base": "https://erp.example.com", "api_key": "key", "timeout": 5,
       "site_base": "https://it.example.com"}


def _with_dolibarr(monkeypatch, handler):
    calls = []

    def recording(request):
        calls.append(request)
        return handler(request)

    async def config():
        return CFG

    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(portal.httpx, "AsyncClient",
                        lambda **kwargs: REAL_CLIENT(transport=httpx.MockTransport(recording), **kwargs))
    return calls


def _party(party_id, *, status=1, client=1, blocked=False, name="Max Muster"):
    return {"id": str(party_id), "name": name, "status": str(status), "client": str(client),
            "array_options": {"options_kundenbereich_gesperrt": "1" if blocked else None}}


def _directory(parties=(), contacts=(), by_id=None):
    by_id = by_id or {}

    def handler(request):
        path = request.url.path
        if path.endswith("/thirdparties"):
            return httpx.Response(200, json=list(parties)) if parties else httpx.Response(404, json={})
        if path.endswith("/contacts"):
            return httpx.Response(200, json=list(contacts)) if contacts else httpx.Response(404, json={})
        party_id = path.rsplit("/", 1)[-1]
        if party_id in by_id:
            return httpx.Response(200, json=by_id[party_id])
        return httpx.Response(404, json={})
    return handler


# ------------------------------------------------------------ who may enter

def test_active_customers_and_prospects_may_enter_blocked_closed_and_suppliers_not():
    assert portal.may_enter(_party(1)) and portal.may_enter(_party(1, client=2)) and portal.may_enter(_party(1, client=3))
    assert not portal.may_enter(_party(1, status=0))
    assert not portal.may_enter(_party(1, client=0))
    assert not portal.may_enter(_party(1, blocked=True))


def test_an_address_leads_to_its_customers_and_the_customers_of_its_contacts(monkeypatch):
    calls = _with_dolibarr(monkeypatch, _directory(
        parties=[_party(7, name="Max Muster"), _party(8, blocked=True, name="Gesperrt")],
        contacts=[{"id": "3", "socid": "9", "statut": "1"}, {"id": "4", "socid": "10", "statut": "0"}],
        by_id={"9": _party(9, name="Stammkunde GmbH"), "10": _party(10, name="Nie")},
    ))
    customers = asyncio.run(portal.customers_for(" Kunde@Example.com "))
    assert customers == [{"id": 7, "name": "Max Muster"}, {"id": 9, "name": "Stammkunde GmbH"}]
    # The address goes to Dolibarr lower-case, inside quotes it cannot leave.
    wanted = parse_qs(urlparse(str(calls[0].url)).query)["sqlfilters"][0]
    assert wanted == "(t.email:=:'kunde@example.com')"
    # A deactivated contact is not even looked up.
    assert not any(call.url.path.endswith("/thirdparties/10") for call in calls)


@pytest.mark.parametrize("bad", ["o'brien@example.com", "a)@example.com", "x@example", "a b@example.com", ""])
def test_an_address_that_could_break_the_filter_is_refused(bad):
    with pytest.raises(ValueError):
        portal.clean_email(bad)


# ------------------------------------------------------------ link and session

class Collection:
    def __init__(self, docs=None):
        self.docs = list(docs or [])

    def _match(self, doc, query):
        for key, wanted in query.items():
            value = doc.get(key)
            if isinstance(wanted, dict):
                for op, operand in wanted.items():
                    if op == "$exists" and (key in doc) != operand:
                        return False
                    if op == "$gt" and not (value is not None and value > operand):
                        return False
            elif value != wanted:
                return False
        return True

    async def count_documents(self, query):
        return sum(1 for doc in self.docs if self._match(doc, query))

    async def insert_one(self, doc):
        doc.setdefault("_id", f"id-{len(self.docs)}")  # as Mongo does
        self.docs.append(doc)

    async def find_one(self, query, *_args, **_kwargs):
        return next((doc for doc in self.docs if self._match(doc, query)), None)

    async def find_one_and_update(self, query, update, **_kwargs):
        doc = await self.find_one(query)
        if doc:
            doc.update(update.get("$set", {}))
        return doc

    async def update_one(self, query, update, **_kwargs):
        doc = await self.find_one(query)
        if doc:
            doc.update(update.get("$set", {}))
            for key in update.get("$unset", {}):
                doc.pop(key, None)

    async def delete_one(self, query):
        self.docs = [doc for doc in self.docs if not self._match(doc, query)]


class Database:
    def __init__(self):
        self.portal_links = Collection()
        self.portal_sessions = Collection()


def _setup(monkeypatch, customers):
    db = Database()
    sent = []

    async def customers_for(email):
        return customers(email) if callable(customers) else customers

    async def config():
        return CFG

    async def mail_config():
        return {"sender_name": "IT-Tabelander"}

    async def send(to, subject, text):
        sent.append((to, subject, text))

    monkeypatch.setattr(portal, "get_db", lambda: db)
    monkeypatch.setattr(portal, "customers_for", customers_for)
    monkeypatch.setattr(dolibarr, "get_config", config)
    monkeypatch.setattr(mailer, "get_mail_config", mail_config)
    monkeypatch.setattr(mailer, "send_mail", send)
    return db, sent


def test_the_link_goes_only_to_the_typed_address_of_a_customer(monkeypatch):
    db, sent = _setup(monkeypatch, lambda email: [{"id": 7, "name": "Max"}] if email == "max@example.com" else [])
    assert asyncio.run(portal.send_link("unbekannt@example.com")) is False
    assert sent == [] and db.portal_links.docs == []

    assert asyncio.run(portal.send_link("Max@Example.com")) is True
    [(to, subject, text)] = sent
    assert to == "max@example.com" and subject == "Dein Link zum Kundenbereich" and "15 Minuten" in text
    token = text.split("/kundenbereich/anmelden#", 1)[1].split()[0]
    [link] = db.portal_links.docs
    assert link["token_hash"] == portal.token_hash(token) and token not in json.dumps(link, default=str)


def test_three_links_per_quarter_hour_are_enough(monkeypatch):
    _db, sent = _setup(monkeypatch, [{"id": 7, "name": "Max"}])
    results = [asyncio.run(portal.send_link("max@example.com")) for _ in range(4)]
    assert results == [True, True, True, False] and len(sent) == 3


def test_a_link_opens_once_and_only_in_time(monkeypatch):
    db, sent = _setup(monkeypatch, [{"id": 7, "name": "Max"}])
    asyncio.run(portal.send_link("max@example.com"))
    token = sent[0][2].split("#", 1)[1].split()[0]
    session_token, session = asyncio.run(portal.redeem(token))
    assert session["customers"] == [{"id": 7, "name": "Max"}] and session["_id"] == portal.token_hash(session_token)
    with pytest.raises(portal.PortalError, match="abgelaufen oder wurde schon verwendet"):
        asyncio.run(portal.redeem(token))

    asyncio.run(portal.send_link("max@example.com"))
    late = sent[1][2].split("#", 1)[1].split()[0]
    db.portal_links.docs[-1]["expires_at"] = datetime.now(timezone.utc) - timedelta(seconds=1)
    with pytest.raises(portal.PortalError):
        asyncio.run(portal.redeem(late))


def test_dolibarr_away_at_sign_in_leaves_the_link_good(monkeypatch):
    state = {"away": True}

    def customers(email):
        if state["away"]:
            raise httpx.ConnectError("weg")
        return [{"id": 7, "name": "Max"}]

    db, sent = _setup(monkeypatch, [{"id": 7, "name": "Max"}])
    asyncio.run(portal.send_link("max@example.com"))
    token = sent[0][2].split("#", 1)[1].split()[0]

    async def flaky(email):
        return customers(email)

    monkeypatch.setattr(portal, "customers_for", flaky)
    with pytest.raises(portal.PortalError, match="gerade nicht erreichbar"):
        asyncio.run(portal.redeem(token))
    assert "used_at" not in db.portal_links.docs[0]
    state["away"] = False
    _session_token, session = asyncio.run(portal.redeem(token))
    assert session["customers"] == [{"id": 7, "name": "Max"}]


def test_a_block_in_dolibarr_ends_a_session_at_the_next_check(monkeypatch):
    allowed = {"customers": [{"id": 7, "name": "Max"}]}
    db, sent = _setup(monkeypatch, lambda email: allowed["customers"])
    asyncio.run(portal.send_link("max@example.com"))
    session_token, _session = asyncio.run(portal.redeem(sent[0][2].split("#", 1)[1].split()[0]))
    assert asyncio.run(portal.current(session_token))

    allowed["customers"] = []
    assert asyncio.run(portal.current(session_token)), "within the check interval the session holds"
    db.portal_sessions.docs[0]["checked_at"] -= portal.RECHECK_EVERY
    assert asyncio.run(portal.current(session_token)) is None
    assert db.portal_sessions.docs == []


# ------------------------------------------------------------ what a customer sees

def _session(*ids):
    return {"email": "max@example.com", "customers": [{"id": value, "name": f"Kunde {value}"} for value in ids]}


def test_the_overview_keeps_other_customers_and_drafts_out(monkeypatch):
    def handler(request):
        path = request.url.path
        if path.endswith("/tickets"):
            return httpx.Response(200, json=[
                {"id": "5", "ref": "TS1", "fk_soc": "7", "fk_statut": "3", "subject": "Reparatur: Notebook (ANF-7K3M9Q2X)",
                 "datec": 1790000000, "array_options": {}},
                {"id": "6", "ref": "TS2", "fk_soc": "8", "fk_statut": "3", "subject": "Fremd (ANF-FREMD001)", "datec": 1790000000},
                {"id": "9", "ref": "TS3", "fk_soc": "7", "fk_statut": "8", "subject": "Alt", "datec": 1780000000},
            ])
        if path.endswith("/proposals"):
            return httpx.Response(200, json=[
                {"id": "31", "ref": "PR1", "socid": "7", "status": "1", "total_ttc": "189", "linkedObjectsIds": {"ticket": {"1": 5}}},
                {"id": "32", "ref": "PR-ENTWURF", "socid": "7", "status": "0", "total_ttc": "1"},
                {"id": "33", "ref": "PR-FREMD", "socid": "8", "status": "1", "total_ttc": "2"},
            ])
        if path.endswith("/invoices"):
            return httpx.Response(200, json=[
                {"id": "41", "ref": "FA1", "socid": "7", "status": "1", "paye": "0", "total_ttc": "89", "remaintopay": "89"},
                {"id": "42", "ref": "FA2", "socid": "7", "status": "2", "paye": "1", "total_ttc": "10", "remaintopay": "0"},
            ])
        return httpx.Response(404, json={})

    calls = _with_dolibarr(monkeypatch, handler)
    data = asyncio.run(portal.overview(_session(7)))
    assert [item["ref"] for item in data["tickets"]] == ["TS1", "TS3"]
    assert data["tickets"][0] == {**data["tickets"][0], "title": "Reparatur: Notebook", "inquiry_ref": "ANF-7K3M9Q2X",
                                  "step": "angebot_bereit", "open": True}
    assert data["tickets"][1]["step"] == "abgeschlossen" and data["tickets"][1]["open"] is False
    assert [item["ref"] for item in data["offers"]] == ["PR1"]
    assert [(item["ref"], item["state"]) for item in data["invoices"]] == [("FA1", "offen"), ("FA2", "bezahlt")]
    assert data["counts"] == {"offers_open": 1, "invoices_open": 1, "repairs_running": 1}
    # Every question to Dolibarr names this customer only.
    for call in calls:
        query = parse_qs(urlparse(str(call.url)).query)
        assert query.get("socid", query.get("thirdparty_ids")) == ["7"], call.url


def test_an_offer_or_invoice_of_someone_else_is_not_there(monkeypatch):
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={"id": "33", "socid": "8", "status": "1"}))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal.offer(_session(7), 33))
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={"id": "34", "socid": "7", "status": "0"}))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal.invoice(_session(7), 34))


def test_a_pdf_path_from_dolibarr_is_checked(monkeypatch):
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={
        "id": "31", "socid": "7", "status": "1", "last_main_doc": "propale/../../conf/conf.php"}))
    with pytest.raises(portal.NotFound):
        asyncio.run(portal.document(_session(7), "proposals", 31))


# ------------------------------------------------------------ answer (#65)

def test_a_yes_closes_the_offer_as_signed_with_the_proof(monkeypatch):
    closed = []

    def handler(request):
        if request.method == "POST":
            closed.append(json.loads(request.content))
            return httpx.Response(200, json={})
        status = "2" if closed else "1"
        return httpx.Response(200, json={"id": "31", "ref": "PR2609-0007", "socid": "7", "status": status, "total_ttc": "189.00"})

    _with_dolibarr(monkeypatch, handler)
    mails, owner = [], []

    async def send(to, subject, text):
        mails.append((to, subject, text))

    async def notify(subject, text):
        owner.append((subject, text))

    async def company():
        return {"name": "IT-Tabelander", "address": "Gasse 1", "zip": "6410", "town": "Telfs", "email": "office@example.com"}

    monkeypatch.setattr(mailer, "send_mail", send)
    monkeypatch.setattr(portal, "_notify_owner", notify)
    monkeypatch.setattr(portal, "_company", company)
    result = asyncio.run(portal.answer(_session(7), 31, accept=True, name="Max Muster", start_now=True, ip="203.0.113.7"))

    assert result["state"] == "angenommen"
    [close] = closed
    assert close["status"] == 2 and "„Max Muster“" in close["note_private"] and "IP 203.0.113.7" in close["note_private"]
    assert "max@example.com" in close["note_private"] and "Sofort beginnen verlangt: ja" in close["note_private"]
    [(to, subject, text)] = mails
    assert to == "max@example.com" and subject == "Bestätigung: Angebot PR2609-0007 angenommen"
    assert "Muster-Widerrufsformular" in text and "innerhalb von 14 Tagen" in text and "Gasse 1, 6410 Telfs" in text
    assert "189,00 €" in text
    assert owner and owner[0][0] == "Angebot PR2609-0007 angenommen"


def test_without_the_request_to_start_the_owner_is_told_to_wait(monkeypatch):
    closed = []

    def handler(request):
        if request.method == "POST":
            closed.append(json.loads(request.content))
        return httpx.Response(200, json={"id": "31", "ref": "PR1", "socid": "7", "status": "1", "total_ttc": "1"})

    _with_dolibarr(monkeypatch, handler)

    async def quiet(*_args, **_kwargs):
        return None

    monkeypatch.setattr(mailer, "send_mail", quiet)
    monkeypatch.setattr(portal, "_notify_owner", quiet)

    async def company():
        return {}

    monkeypatch.setattr(portal, "_company", company)
    asyncio.run(portal.answer(_session(7), 31, accept=True, name="Max", start_now=False))
    assert "Sofort beginnen verlangt: NEIN" in closed[0]["note_private"]
    asyncio.run(portal.answer(_session(7), 31, accept=False, reason="Zu teuer <script>alert(1)</script>"))
    assert closed[1]["status"] == 3 and "Grund: Zu teuer &lt;script&gt;" in closed[1]["note_private"]
    assert "<script>" not in closed[1]["note_private"]


def test_a_second_click_in_the_same_moment_sends_no_second_mail(monkeypatch):
    """Both clicks passed the check; Dolibarr closes once and answers the second with 304."""
    def handler(request):
        if request.method == "POST":
            return httpx.Response(304)
        return httpx.Response(200, json={"id": "31", "ref": "PR1", "socid": "7", "status": "1", "total_ttc": "1"})

    _with_dolibarr(monkeypatch, handler)
    told = []

    async def send(*args, **_kwargs):
        told.append(args)

    monkeypatch.setattr(mailer, "send_mail", send)
    monkeypatch.setattr(portal, "_notify_owner", send)
    asyncio.run(portal.answer(_session(7), 31, accept=True, name="Max", start_now=True))
    assert told == []


def test_an_invoice_with_nothing_left_is_paid_whatever_type_the_zero_has():
    for left in (0, 0.0, "0", "0.00000000"):
        item = portal._invoice({"id": "41", "ref": "FA1", "status": "1", "paye": "0", "total_ttc": "89", "remaintopay": left})
        assert item["open"] is False and item["state"] == "bezahlt", left
    assert portal._invoice({"id": "41", "status": "1", "paye": "0", "total_ttc": "89"})["open"] is True


def test_an_answered_or_expired_offer_cannot_be_answered_again(monkeypatch):
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={"id": "31", "socid": "7", "status": "2"}))
    with pytest.raises(portal.PortalError, match="nicht mehr beantwortet"):
        asyncio.run(portal.answer(_session(7), 31, accept=True, name="Max"))
    yesterday = int((datetime.now(timezone.utc) - timedelta(days=2)).timestamp())
    _with_dolibarr(monkeypatch, lambda request: httpx.Response(200, json={"id": "31", "socid": "7", "status": "1",
                                                                          "fin_validite": yesterday}))
    with pytest.raises(portal.PortalError):
        asyncio.run(portal.answer(_session(7), 31, accept=True, name="Max"))


# ------------------------------------------------------------ payment (#66)

def test_the_transfer_code_follows_the_epc_standard():
    text = portal.epc_payload(holder="Jürgen Müller", iban="AT611904300234573201", bic="BKAUATWW",
                              amount="89.005", reference="FA2609-0012")
    assert text.split("\n") == ["BCD", "002", "1", "SCT", "BKAUATWW", "Jürgen Müller", "AT611904300234573201",
                                "EUR89.01", "", "", "FA2609-0012"]
    for bad in ("0", "-5", "1000000000"):
        with pytest.raises(ValueError):
            portal.epc_payload(holder="A", iban="AT611904300234573201", bic="", amount=bad, reference="X")


def test_the_bank_account_is_checked_before_it_is_saved():
    assert check_iban("at61 1904 3002 3457 3201") == "AT611904300234573201"
    assert check_iban("") == ""
    for bad in ("AT61 1904 3002 3457 3202", "AT61", "DE00 1234"):
        with pytest.raises(ValueError):
            check_iban(bad)
    assert SettingsInput(portal_bank_bic="bkau atww").portal_bank_bic == "BKAUATWW"
    with pytest.raises(ValidationError):
        SettingsInput(portal_bank_bic="KURZ")


# ------------------------------------------------------------ all documents (#100)

def _documents_handler(lists=None, objects=None, files=None, refused=()):
    """Dolibarr with lists per kind, single objects by path and stored files."""
    lists, objects, files = lists or {}, objects or {}, files or {}

    def handler(request):
        path = request.url.path.replace("/api/index.php/", "")
        if path in refused:
            return httpx.Response(403, json={"error": {"code": 403}})
        if path == "documents/download":
            if (request.url.params["modulepart"], request.url.params["original_file"]) in files:
                return httpx.Response(200, json={"content": "JVBERi0xLjQ="})
            return httpx.Response(404, json={})
        if path in lists:
            return httpx.Response(200, json=lists[path])
        if path in objects:
            return httpx.Response(200, json=objects[path])
        return httpx.Response(404, json={})
    return handler


def test_the_overview_lists_released_documents_of_this_customer_only(monkeypatch):
    calls = _with_dolibarr(monkeypatch, _documents_handler(lists={
        "orders": [
            {"id": "51", "ref": "CO1", "socid": "7", "status": "1", "date": 1790000000, "total_ttc": "120",
             "last_main_doc": "commande/CO1/CO1.pdf", "note_private": "intern"},
            {"id": "52", "ref": "CO-ENTWURF", "socid": "7", "status": "0"},
            {"id": "53", "ref": "CO-FREMD", "socid": "8", "status": "1"},
            {"id": "54", "ref": "CO3", "socid": "7", "status": "3"},
        ],
        "shipments": [{"id": "61", "ref": "SH1", "socid": "7", "status": "1", "date_shipping": 1790000000,
                       "tracking_number": "TRACK-1"},
                      {"id": "62", "ref": "SH-ENTWURF", "socid": "7", "status": "0"}],
        "interventions": [{"id": "71", "ref": "FI1", "socid": "7", "status": "1", "datec": 1790000000},
                          {"id": "72", "ref": "FI-FREMD", "socid": "9", "status": "1"}],
        "contracts": [{"id": "81", "ref": "CT1", "socid": "7", "status": "1", "date_contrat": 1790000000}],
    }))
    data = asyncio.run(portal.overview(_session(7)))
    assert [(item["ref"], item["state"]) for item in data["orders"]] == [("CO1", "bestätigt"), ("CO3", "geliefert")]
    assert data["orders"][0] == {"kind": "orders", "id": 51, "ref": "CO1", "status": 1, "state": "bestätigt",
                                 "date": dolibarr._iso_time(1790000000), "total": "120", "has_pdf": True}
    assert [item["ref"] for item in data["shipments"]] == ["SH1"]
    assert [item["ref"] for item in data["interventions"]] == ["FI1"]
    assert [item["ref"] for item in data["contracts"]] == ["CT1"]
    assert "intern" not in json.dumps(data) and "TRACK-1" not in json.dumps(data)
    for call in calls:
        query = parse_qs(urlparse(str(call.url)).query)
        assert query.get("socid", query.get("thirdparty_ids")) == ["7"], call.url


def test_a_kind_dolibarr_refuses_stays_empty_and_the_rest_shows(monkeypatch):
    """A right not yet given or a module switched off must not take the whole customer area down."""
    _with_dolibarr(monkeypatch, _documents_handler(
        lists={"invoices": [{"id": "41", "ref": "FA1", "socid": "7", "status": "1", "paye": "0", "total_ttc": "9"}]},
        refused=("orders", "contracts")))
    data = asyncio.run(portal.overview(_session(7)))
    assert data["orders"] == [] and data["contracts"] == [] and [item["ref"] for item in data["invoices"]] == ["FA1"]


@pytest.mark.parametrize("kind", ["orders", "shipments", "interventions", "contracts"])
def test_a_document_of_someone_else_or_a_draft_is_not_there(monkeypatch, kind):
    _with_dolibarr(monkeypatch, _documents_handler(objects={
        f"{kind}/90": {"id": "90", "ref": "X1", "socid": "8", "status": "1", "last_main_doc": "x/X1/X1.pdf"},
        f"{kind}/91": {"id": "91", "ref": "X2", "socid": "7", "status": "0", "last_main_doc": "x/X2/X2.pdf"},
    }, files={(module, "X1/X1.pdf"): 1 for module in ("commande", "expedition", "ficheinter", "contract")}))
    for object_id in (90, 91, 92):
        with pytest.raises(portal.NotFound):
            asyncio.run(portal.document_detail(_session(7), kind, object_id))
        with pytest.raises(portal.NotFound):
            asyncio.run(portal.document(_session(7), kind, object_id))


def test_an_unknown_kind_is_not_there(monkeypatch):
    calls = _with_dolibarr(monkeypatch, _documents_handler())
    for kind in ("users", "thirdparties", "../setup"):
        with pytest.raises(portal.NotFound):
            asyncio.run(portal.document_detail(_session(7), kind, 1))
        with pytest.raises(portal.NotFound):
            asyncio.run(portal.document(_session(7), kind, 1))
    assert calls == []


def test_each_kind_shows_its_own_details_and_nothing_internal(monkeypatch):
    _with_dolibarr(monkeypatch, _documents_handler(objects={
        "orders/51": {"id": "51", "ref": "CO1", "socid": "7", "status": "2", "total_ttc": "120", "total_ht": "100",
                      "total_tva": "20", "delivery_date": 1791000000, "note_private": "intern",
                      "last_main_doc": "commande/CO1/CO1.pdf",
                      "lines": [{"product_label": "SSD 1 TB", "desc": "<p>eingebaut</p>", "qty": "1", "total_ttc": "120"}]},
        "shipments/61": {"id": "61", "ref": "SH1", "socid": "7", "status": "2", "tracking_number": "TRACK-1",
                         "date_shipping": 1790000000, "note_private": "intern",
                         "lines": [{"product_label": "SSD 1 TB", "description": "", "qty_shipped": "1", "total_ttc": "120"}]},
        "interventions/71": {"id": "71", "ref": "FI1", "socid": "7", "status": "3", "datec": 1790000000,
                             "description": "<p>Notebook gereinigt</p>", "duration": "5400", "note_private": "intern",
                             "lines": [{"desc": "Lüfter gereinigt", "date": 1790000000, "duration": "3600"},
                                       {"desc": "Test", "date": 1790003600, "duration": "1800"}]},
        "contracts/81": {"id": "81", "ref": "CT1", "socid": "7", "status": "1", "date_contrat": 1790000000,
                         "note_private": "intern",
                         "lines": [{"product_label": "Wartung", "desc": "monatlich", "qty": "1", "total_ttc": "30",
                                    "status": "4", "date_start": 1790000000, "date_end": 1821536000, "pa_ht": "1"}]},
    }))
    order = asyncio.run(portal.document_detail(_session(7), "orders", 51))
    assert order["state"] == "in Lieferung"
    assert order["lines"] == [{"text": "SSD 1 TB", "details": "eingebaut", "qty": "1", "total": "120"}]
    assert order["net"] == "100" and order["vat"] == "20" and order["delivery"] and order["has_pdf"] is True
    shipment = asyncio.run(portal.document_detail(_session(7), "shipments", 61))
    assert shipment["state"] == "zugestellt" and shipment["tracking"] == "TRACK-1"
    assert shipment["lines"] == [{"text": "SSD 1 TB", "details": "", "qty": "1"}], "a delivery note shows no prices"
    work = asyncio.run(portal.document_detail(_session(7), "interventions", 71))
    assert work["state"] == "erledigt" and work["description"] == "Notebook gereinigt" and work["hours"] == 1.5
    assert [(line["text"], line["hours"]) for line in work["lines"]] == [("Lüfter gereinigt", 1.0), ("Test", 0.5)]
    contract = asyncio.run(portal.document_detail(_session(7), "contracts", 81))
    assert contract["state"] == "aktiv" and contract["lines"][0]["state"] == "läuft"
    assert contract["lines"][0]["text"] == "Wartung" and contract["lines"][0]["total"] == "30"
    for item in (order, shipment, work, contract):
        assert "intern" not in json.dumps(item) and "pa_ht" not in json.dumps(item)


def test_a_pdf_comes_only_from_the_documents_own_folder(monkeypatch):
    _with_dolibarr(monkeypatch, _documents_handler(objects={
        "orders/51": {"id": "51", "ref": "CO1", "socid": "7", "status": "1", "last_main_doc": "commande/CO1/CO1.pdf"},
        "orders/52": {"id": "52", "ref": "CO2", "socid": "7", "status": "1", "last_main_doc": "commande/CO9/CO9.pdf"},
        "orders/53": {"id": "53", "ref": "CO/3", "socid": "7", "status": "1", "last_main_doc": "commande/CO_3/CO_3.pdf"},
        "contracts/81": {"id": "81", "ref": "CT1", "socid": "7", "status": "1",
                         "last_main_doc": "contract/../x/CT1/CT1.pdf"},
    }, files={("commande", "CO1/CO1.pdf"): 1, ("commande", "CO9/CO9.pdf"): 1, ("commande", "CO_3/CO_3.pdf"): 1,
              ("contract", "CT1/CT1.pdf"): 1}))
    assert asyncio.run(portal.document(_session(7), "orders", 51)) == ("CO1.pdf", b"%PDF-1.4")
    # Dolibarr writes a reference with a slash as a folder with "_".
    assert asyncio.run(portal.document(_session(7), "orders", 53)) == ("CO_3.pdf", b"%PDF-1.4")
    for kind, object_id in (("orders", 52), ("contracts", 81)):
        with pytest.raises(portal.NotFound):
            asyncio.run(portal.document(_session(7), kind, object_id))


def test_a_delivery_note_pdf_is_found_under_its_standard_name(monkeypatch):
    """Dolibarr keeps no last_main_doc for shipments: the website asks for "<ref>/<ref>.pdf"."""
    calls = _with_dolibarr(monkeypatch, _documents_handler(
        objects={"shipments/61": {"id": "61", "ref": "SH1", "socid": "7", "status": "1"},
                 "shipments/62": {"id": "62", "ref": "SH2", "socid": "7", "status": "1"}},
        files={("expedition", "SH1/SH1.pdf"): 1}))
    assert asyncio.run(portal.document_detail(_session(7), "shipments", 61))["has_pdf"] is True
    assert asyncio.run(portal.document(_session(7), "shipments", 61)) == ("SH1.pdf", b"%PDF-1.4")
    assert asyncio.run(portal.document_detail(_session(7), "shipments", 62))["has_pdf"] is False
    with pytest.raises(portal.NotFound):
        asyncio.run(portal.document(_session(7), "shipments", 62))
    asked = [call.url.params["original_file"] for call in calls if call.url.path.endswith("/documents/download")]
    assert set(asked) == {"SH1/SH1.pdf", "SH2/SH2.pdf"}


def test_a_credit_note_is_marked_in_the_invoices():
    item = portal._invoice({"id": "45", "ref": "AV1", "type": "2", "status": "1", "paye": "0", "total_ttc": "-20"})
    assert item["credit_note"] is True and item["open"] is False


# ------------------------------------------------------------ running services (#101)

def _contract(contract_id, socid, *, status="1", lines=(), options=None, last_main_doc=""):
    return {"id": str(contract_id), "ref": f"CT{contract_id}", "socid": str(socid), "status": status,
            "date_contrat": 1790000000, "last_main_doc": last_main_doc, "array_options": options or {},
            "note_private": "intern", "lines": list(lines)}


def _service_line(label, *, status="4", product="5", total="30", **extra):
    return {"product_label": label, "desc": "", "qty": "1", "total_ttc": total, "status": status, "fk_product": product,
            "date_start": 1790000000, "date_start_real": 1790100000, "date_end": 1821536000, "pa_ht": "1", **extra}


def test_running_services_come_from_released_contracts_of_this_customer(monkeypatch):
    calls = _with_dolibarr(monkeypatch, _documents_handler(
        lists={"contracts": [
            _contract(81, 7, options={"options_ittab_mindestlaufzeit": "12", "options_ittab_kuendigungsfrist": "3 Monate zum Quartalsende"},
                      last_main_doc="contract/CT81/CT81.pdf",
                      lines=[_service_line("Webhosting"), _service_line("Altes Postfach", status="5"),
                             _service_line("Noch nicht aktiv", status="0"), _service_line("Wartung", product="6", total="120")]),
            _contract(82, 7, status="0", lines=[_service_line("Entwurf")]),
            _contract(83, 8, lines=[_service_line("Fremd")]),
            _contract(84, 7, status="2", lines=[_service_line("Beendeter Vertrag")]),
        ]},
        objects={"products/5": {"id": "5", "duration_value": "1", "duration_unit": "m", "cost_price": "2"},
                 "products/6": {"id": "6", "duration_value": "12", "duration_unit": "m"}}))
    data = asyncio.run(portal.overview(_session(7)))
    services = data["services"]
    assert [item["text"] for item in services] == ["Webhosting", "Wartung"]
    assert services[0] == {"contract_id": 81, "contract_ref": "CT81", "text": "Webhosting", "details": "", "qty": "1",
                           "total": "30", "interval": "monatlich", "start": dolibarr._iso_time(1790100000),
                           "end": dolibarr._iso_time(1821536000), "minimum_months": 12,
                           "notice": "3 Monate zum Quartalsende", "has_pdf": True}
    assert services[1]["interval"] == "jährlich"
    assert "intern" not in json.dumps(data) and "cost_price" not in json.dumps(data)
    # Each product is asked for once, and every list names this customer only.
    assert sum(1 for call in calls if call.url.path.endswith("/products/5")) == 1
    for call in calls:
        query = parse_qs(urlparse(str(call.url)).query)
        if "/products/" not in call.url.path:
            assert query.get("socid", query.get("thirdparty_ids")) == ["7"], call.url


def test_without_the_product_right_the_interval_is_left_out_not_guessed(monkeypatch):
    _with_dolibarr(monkeypatch, _documents_handler(
        lists={"contracts": [_contract(81, 7, lines=[_service_line("Webhosting")])]}, refused=("products/5",)))
    [service] = asyncio.run(portal.overview(_session(7)))["services"]
    assert service["interval"] is None and service["minimum_months"] is None and service["notice"] is None


@pytest.mark.parametrize(("value", "unit", "text"), [
    ("1", "m", "monatlich"), ("3", "m", "vierteljährlich"), ("6", "m", "halbjährlich"), ("12", "m", "jährlich"),
    ("1", "y", "jährlich"), ("2", "y", "alle 2 Jahre"), ("2", "w", "alle 2 Wochen"), ("1", "d", "täglich"),
    ("", "m", None), ("1", "", None), ("0", "m", None), ("1", "x", None),
])
def test_the_billing_interval_in_words(value, unit, text):
    assert portal.interval_text(value, unit) == text


def test_the_contract_page_shows_terms_and_intervals(monkeypatch):
    _with_dolibarr(monkeypatch, _documents_handler(objects={
        "contracts/81": _contract(81, 7, options={"options_ittab_mindestlaufzeit": "24"},
                                  lines=[_service_line("Webhosting")]),
        "products/5": {"id": "5", "duration_value": "1", "duration_unit": "y"},
    }))
    contract = asyncio.run(portal.document_detail(_session(7), "contracts", 81))
    assert contract["minimum_months"] == 24 and contract["notice"] is None
    assert contract["lines"][0]["interval"] == "jährlich"

"""Milestone 2, part C: company data, legal texts, FAQ and status steps from Dolibarr."""
import asyncio
import unittest.mock

import httpx

from app import dolibarr, site_data

CFG = {"base": "https://erp.example.test", "api_key": "top-secret-key", "country_code": "AT",
       "timeout": 8, "enabled": True}


def _run(coroutine_factory, handler):
    async def run():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            return await coroutine_factory(client)
    return asyncio.run(run())


def test_company_data_is_a_whitelist():
    def handler(request):
        return httpx.Response(200, json={"name": "IT-Tabelander", "zip": "6020", "note_private": "intern!",
                                         "managers": "Fabian Tabelander", "socialobject": "IT-Dienstleistungen",
                                         "idprof3": "FN 1a", "lines": []})
    company = _run(lambda client: site_data.fetch_company(client, CFG), handler)
    assert company == {"name": "IT-Tabelander", "zip": "6020", "managers": "Fabian Tabelander",
                       "socialobject": "IT-Dienstleistungen", "idprof3": "FN 1a"}


def test_opening_hours_skip_days_without_a_value():
    def handler(request):
        if request.url.path.endswith("MONDAY"):
            return httpx.Response(200, json="09:00–17:00")
        return httpx.Response(400, json={"error": {"message": "Bad or unknown value"}})
    hours = _run(lambda client: site_data.fetch_opening_hours(client, CFG), handler)
    assert hours == [{"day": "Montag", "hours": "09:00–17:00"}]


def test_articles_are_sanitized_and_obsolete_ones_dropped():
    def handler(request):
        assert request.url.params["category"] == "5"
        return httpx.Response(200, json=[
            {"id": "1", "question": "Wie lange?", "answer": "<p>Kurz</p><script>alert(1)</script>", "status": "1"},
            {"id": "2", "question": "Alt", "answer": "x", "status": "9"},
            {"id": "3", "question": "Entwurf", "answer": "<p onclick='x()'>y</p>", "status": "0"},
        ])
    articles = _run(lambda client: site_data.fetch_articles(client, CFG, 5), handler)
    assert [item["id"] for item in articles] == [1, 3]
    assert "<script" not in articles[0]["answer_html"] and "onclick" not in articles[1]["answer_html"]


def test_faq_shows_only_released_articles_that_are_no_legal_page():
    data = {"articles": [
        {"id": 1, "question": "Wie lange dauert es?", "answer_html": "<p>Ein <b>paar</b> Tage.</p>", "status": 1},
        {"id": 2, "question": "Entwurf", "answer_html": "<p>x</p>", "status": 0},
        {"id": 3, "question": "Datenschutz", "answer_html": "<p>...</p>", "status": 1},
    ]}
    entries = site_data.faq_entries(data, {"dolibarr_privacy_article_id": 3})
    assert [entry["question"] for entry in entries] == ["Wie lange dauert es?"]
    assert entries[0]["answer"] == "Ein paar Tage." and entries[0]["answer_html"] == "<p>Ein <b>paar</b> Tage.</p>"


def test_imprint_comes_from_company_data_and_escapes_it():
    body = site_data.imprint_html({
        "name": "IT-Tabelander <GmbH>", "managers": "Fabian Tabelander", "address": "Gasse 1", "zip": "6020",
        "town": "Innsbruck", "country_code": "AT", "email": "office@example.at", "tva_intra": "ATU12345678",
        "idprof3": "FN 123456a", "idprof2": "LG Innsbruck", "socialobject": "IT-Service",
    }, "<p>Aufsichtsbehörde: BH</p>")
    assert "IT-Tabelander &lt;GmbH&gt;" in body and "6020 Innsbruck" in body
    assert "UID-Nummer: ATU12345678" in body and "Firmenbuchnummer: FN 123456a" in body
    assert "Firmenbuchgericht: LG Innsbruck" in body and "Unternehmensgegenstand: IT-Service" in body
    assert body.endswith("<p>Aufsichtsbehörde: BH</p>")


def test_hints_name_the_dolibarr_setting():
    forbidden = httpx.HTTPStatusError("x", request=httpx.Request("GET", "https://x"),
                                      response=httpx.Response(403))
    assert "API_LOGINS_ALLOWED_FOR_GET_COMPANY" in site_data._hint("company", forbidden)
    assert "API_LOGINS_ALLOWED_FOR_CONST_READ" in site_data._hint("opening_hours", forbidden)
    assert "Wissensdatenbank" in site_data._hint("articles", forbidden)


def _status(ticket, proposals=None, proposal_status=200):
    def handler(request):
        if request.url.path.endswith("/proposals"):
            return httpx.Response(proposal_status, json=proposals or [])
        return httpx.Response(200, json=ticket)

    real_client = httpx.AsyncClient

    def client(**kwargs):
        return real_client(transport=httpx.MockTransport(handler), **kwargs)

    async def config():
        return CFG

    with unittest.mock.patch.object(dolibarr, "get_config", config), \
            unittest.mock.patch.object(dolibarr.httpx, "AsyncClient", client):
        return asyncio.run(dolibarr.fetch_ticket_status("501"))["step"]


def test_ready_for_pickup_and_offer_waiting_are_status_steps():
    base = {"id": "501", "status": "3", "fk_soc": "42", "origin_email": "a@b.at"}
    assert _status(base) == "in_arbeit"
    assert _status({**base, "array_options": {"options_abholbereit": "1"}}) == "abholbereit"
    offer = [{"id": "9", "linkedObjectsIds": {"ticket": {"12": "501"}}}]
    assert _status(base, offer) == "angebot_bereit"
    assert _status(base, [{"id": "9", "linkedObjectsIds": {"ticket": {"12": "777"}}}]) == "in_arbeit"
    # Without the right to read proposals the status still works.
    assert _status(base, proposal_status=403) == "in_arbeit"
    # A closed ticket stays closed, whatever the extra field says.
    assert _status({**base, "status": "8", "array_options": {"options_abholbereit": "1"}}) == "abgeschlossen"

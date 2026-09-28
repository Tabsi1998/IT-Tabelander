"""The customer area on the website (#63, #64).

Sign-in without a password: a customer types the e-mail address, and if it
belongs to a customer or contact in Dolibarr, a link valid for 15 minutes
goes to exactly that address. Following it opens a session of seven days.

Who may enter is decided in Dolibarr, never here:
- the e-mail of an active customer or prospect, or of an active contact of one;
- not a closed customer, not a deactivated contact;
- not a customer with the extra field "Kundenbereich gesperrt" ticked.
Dolibarr's own "Webzugriffskonten" cannot serve for this: its API does not
give out their status (checked in the 24.0.1 source). A session asks again
every few minutes, so a block takes effect without a new sign-in.

Every reading goes to Dolibarr with the customer ids of the session and
keeps only what belongs to them; nothing from Dolibarr is stored here.
"""
import hashlib
import html
import logging
import re
import secrets
from datetime import datetime, timedelta

import httpx
from pymongo import ReturnDocument

from . import dolibarr, mailer
from .db import get_db, now_utc

logger = logging.getLogger("it-tabelander.portal")

LINK_VALID = timedelta(minutes=15)
LINKS_PER_ADDRESS = 3  # per LINK_VALID: a typing mistake is fine, a mail bomb is not
SESSION_VALID = timedelta(days=7)
RECHECK_EVERY = timedelta(minutes=5)
COOKIE = "portal_session"
BLOCK_FIELD = "options_kundenbereich_gesperrt"
# Customer roles in Dolibarr: 1 customer, 2 prospect, 3 both. A supplier only is no customer.
CUSTOMER_ROLES = {1, 2, 3}
EMAIL = re.compile(r"^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$")
INQUIRY_REF = re.compile(r"\((ANF-[A-Z0-9]{8})\)\s*$")


class PortalError(Exception):
    """A sign-in that does not work; the message is safe to show."""


def token_hash(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def clean_email(value: str) -> str:
    email = str(value or "").strip().lower()
    if len(email) > 254 or not EMAIL.fullmatch(email):
        raise ValueError("Bitte eine gültige E-Mail-Adresse eingeben.")
    return email


def _is_yes(value) -> bool:
    return str(value or "").strip().lower() in ("1", "true", "yes", "ja", "on")


def _int(value, default=0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def may_enter(party: dict) -> bool:
    """An active customer or prospect without the block."""
    if _int(party.get("status"), 1) != 1:
        return False
    if _int(party.get("client")) not in CUSTOMER_ROLES:
        return False
    return not _is_yes((party.get("array_options") or {}).get(BLOCK_FIELD))


async def _list(client: httpx.AsyncClient, cfg: dict, path: str, params: dict) -> list:
    response = await client.get(f"{cfg['base']}/api/index.php/{path}", headers=dolibarr._headers(cfg), params=params)
    if response.status_code == 404:
        return []  # Dolibarr answers an empty list with 404
    response.raise_for_status()
    return response.json() or []


async def customers_for(email: str) -> list[dict]:
    """The customers an e-mail address may see, straight from Dolibarr."""
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        return []
    email = clean_email(email)
    wanted = f"(t.email:=:'{email}')"  # the address passed clean_email: no quote can get in
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        parties = await _list(client, cfg, "thirdparties", {"sqlfilters": wanted, "limit": 20})
        contacts = await _list(client, cfg, "contacts", {"sqlfilters": wanted, "limit": 20})
        found = {_int(party.get("id")): party for party in parties}
        for contact in contacts:
            if _int(contact.get("statut", contact.get("status")), 1) != 1:
                continue  # a deactivated contact
            socid = _int(contact.get("socid") or contact.get("fk_soc"))
            if socid and socid not in found:
                response = await client.get(f"{cfg['base']}/api/index.php/thirdparties/{socid}",
                                            headers=dolibarr._headers(cfg))
                if response.status_code == 404:
                    continue
                response.raise_for_status()
                found[socid] = response.json() or {}
    return [{"id": socid, "name": str(party.get("name") or "").strip()}
            for socid, party in sorted(found.items()) if socid and may_enter(party)]


def link_mail(link: str, *, sender_name: str, site_base: str) -> tuple[str, str]:
    subject = "Dein Link zum Kundenbereich"
    text = f"""Hallo,

hier ist dein Link zum Kundenbereich von IT-Tabelander:

{link}

Er gilt 15 Minuten und funktioniert einmal. Hast du ihn nicht angefordert,
kannst du diese Mail einfach löschen - ohne den Link kommt niemand hinein.

Viele Grüße
{sender_name}
{site_base}
"""
    return subject, text


async def send_link(email: str) -> bool:
    """Runs after the answer went out: the answer never tells whether the
    address is known. The link goes to this address only, and only if it
    belongs to a customer who may enter."""
    try:
        email = clean_email(email)
        db = get_db()
        now = now_utc()
        recent = await db.portal_links.count_documents({"email": email, "created_at": {"$gt": now - LINK_VALID}})
        if recent >= LINKS_PER_ADDRESS:
            return False
        if not await customers_for(email):
            return False
        token = secrets.token_urlsafe(32)
        await db.portal_links.insert_one({"token_hash": token_hash(token), "email": email,
                                          "created_at": now, "expires_at": now + LINK_VALID})
        cfg = await dolibarr.get_config()
        mail = await mailer.get_mail_config()
        subject, text = link_mail(f"{cfg['site_base']}/kundenbereich/anmelden#{token}",
                                  sender_name=mail.get("sender_name") or "IT-Tabelander", site_base=cfg["site_base"])
        await mailer.send_mail(email, subject, text)
        return True
    except Exception as exc:  # noqa: BLE001 - nobody waits for this answer
        logger.warning("Sign-in link not sent: %s", type(exc).__name__)
        return False


async def redeem(token: str) -> tuple[str, dict]:
    """The link from the mail becomes a session, once."""
    db = get_db()
    now = now_utc()
    link = await db.portal_links.find_one_and_update(
        {"token_hash": token_hash(token), "used_at": {"$exists": False}, "expires_at": {"$gt": now}},
        {"$set": {"used_at": now}}, return_document=ReturnDocument.AFTER,
    )
    if not link:
        raise PortalError("Dieser Link ist abgelaufen oder wurde schon verwendet. Fordere einfach einen neuen an.")
    try:
        customers = await customers_for(link["email"])
    except Exception as exc:  # noqa: BLE001 - Dolibarr away for a moment: the link stays good
        logger.warning("Portal access not checked at sign-in: %s", type(exc).__name__)
        await db.portal_links.update_one({"_id": link["_id"]}, {"$unset": {"used_at": ""}})
        raise PortalError("Der Kundenbereich ist gerade nicht erreichbar. Öffne den Link in ein paar Minuten "
                          "einfach noch einmal.") from None
    if not customers:
        raise PortalError("Für diese E-Mail-Adresse ist der Kundenbereich nicht freigeschaltet.")
    session_token = secrets.token_urlsafe(32)
    session = {"_id": token_hash(session_token), "email": link["email"], "customers": customers,
               "created_at": now, "checked_at": now, "expires_at": now + SESSION_VALID}
    await db.portal_sessions.insert_one(session)
    return session_token, session


async def current(session_token: str | None) -> dict | None:
    """The session behind the cookie, with access asked again in Dolibarr
    every few minutes; a block or a closed customer ends it."""
    if not session_token:
        return None
    db = get_db()
    now = now_utc()
    session = await db.portal_sessions.find_one({"_id": token_hash(session_token), "expires_at": {"$gt": now}})
    if not session:
        return None
    checked = session.get("checked_at")
    if isinstance(checked, datetime) and now - checked < RECHECK_EVERY:
        return session
    try:
        customers = await customers_for(session["email"])
    except Exception as exc:  # noqa: BLE001 - Dolibarr away: keep the last answer a little longer
        logger.warning("Portal access not rechecked: %s", type(exc).__name__)
        return session
    if not customers:
        await db.portal_sessions.delete_one({"_id": session["_id"]})
        return None
    await db.portal_sessions.update_one({"_id": session["_id"]}, {"$set": {"customers": customers, "checked_at": now}})
    return {**session, "customers": customers, "checked_at": now}


async def end(session_token: str | None) -> None:
    if session_token:
        await get_db().portal_sessions.delete_one({"_id": token_hash(session_token)})


def public(session: dict) -> dict:
    return {"email": session["email"], "customers": [customer["name"] for customer in session["customers"]]}


# ------------------------------------------------------------------ data

TICKET_OPEN_CODES = {0, 1, 2, 3, 5, 7}
PROPOSAL_STATES = {1: "offen", 2: "angenommen", 3: "abgelehnt", 4: "abgerechnet"}
INVOICE_VALIDATED, INVOICE_PAID, INVOICE_ABANDONED = 1, 2, 3


def _ids(session: dict) -> list[int]:
    return [customer["id"] for customer in session["customers"]]


def _linked_tickets(proposal: dict) -> set[str]:
    linked = (proposal.get("linkedObjectsIds") or {}).get("ticket") or {}
    return {str(value) for value in (linked.values() if isinstance(linked, dict) else linked)}


def _ticket(raw: dict, waiting_offer: bool) -> dict:
    code = _int(raw.get("fk_statut", raw.get("status")))
    step = dolibarr.TICKET_STATUS_STEPS.get(code, "eingegangen")
    if code not in (8, 9):
        if _is_yes((raw.get("array_options") or {}).get(dolibarr.PICKUP_FIELD)):
            step = "abholbereit"
        elif waiting_offer:
            step = "angebot_bereit"
    subject = str(raw.get("subject") or "").strip()
    found = INQUIRY_REF.search(subject)
    return {
        "id": _int(raw.get("id")), "ref": raw.get("ref"),
        "title": dolibarr.subject_without_ref(subject, found.group(1)) if found else subject,
        "inquiry_ref": found.group(1) if found else None,
        "step": step, "open": code in TICKET_OPEN_CODES,
        "created_at": dolibarr._iso_time(raw.get("datec")),
        "updated_at": dolibarr._iso_time(raw.get("date_modification") or raw.get("tms") or raw.get("datec")),
    }


def _offer(raw: dict) -> dict:
    status = _int(raw.get("status", raw.get("statut")))
    return {
        "id": _int(raw.get("id")), "ref": raw.get("ref"), "status": status, "state": PROPOSAL_STATES.get(status, "offen"),
        "date": dolibarr._iso_time(raw.get("date") or raw.get("datep")),
        "valid_until": dolibarr._iso_time(raw.get("fin_validite")),
        "total": raw.get("total_ttc"), "has_pdf": bool(raw.get("last_main_doc")),
    }


def _invoice(raw: dict) -> dict:
    status = _int(raw.get("status", raw.get("statut")))
    remain = raw.get("remaintopay")
    # Dolibarr sends what is left as text or as a number; a 0 means paid, never "take the total".
    left = remain if remain not in (None, "") else raw.get("total_ttc")
    open_ = status == INVOICE_VALIDATED and _int(raw.get("paye")) == 0 and float(left or 0) > 0
    state = "offen" if open_ else "storniert" if status == INVOICE_ABANDONED else "bezahlt"
    return {
        "id": _int(raw.get("id")), "ref": raw.get("ref"), "status": status, "state": state, "open": open_,
        "credit_note": _int(raw.get("type")) == 2,
        "date": dolibarr._iso_time(raw.get("date")), "due": dolibarr._iso_time(raw.get("date_lim_reglement")),
        "total": raw.get("total_ttc"), "remain": remain, "has_pdf": bool(raw.get("last_main_doc")),
    }


async def overview(session: dict) -> dict:
    """Everything of this customer: inquiries and repairs, offers, invoices."""
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        raise PortalError("Der Kundenbereich ist gerade nicht erreichbar.")
    ids = _ids(session)
    joined = ",".join(str(value) for value in ids)
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        tickets = []
        for socid in ids:
            tickets += await _list(client, cfg, "tickets", {"socid": socid, "sortfield": "t.datec", "sortorder": "DESC",
                                                            "limit": 100})
        proposals = await _list(client, cfg, "proposals", {"thirdparty_ids": joined, "sortfield": "t.datep",
                                                           "sortorder": "DESC", "limit": 100, "loadlinkedobjects": 1})
        invoices = await _list(client, cfg, "invoices", {"thirdparty_ids": joined, "sortfield": "t.datef",
                                                         "sortorder": "DESC", "limit": 100})
    allowed = {str(value) for value in ids}
    # Dolibarr filtered already; checking again costs nothing and keeps customers apart for sure.
    tickets = [item for item in tickets if str(item.get("fk_soc") or item.get("socid")) in allowed]
    proposals = [item for item in proposals if str(item.get("socid") or item.get("fk_soc")) in allowed
                 and _int(item.get("status", item.get("statut"))) != 0]
    invoices = [item for item in invoices if str(item.get("socid") or item.get("fk_soc")) in allowed
                and _int(item.get("status", item.get("statut"))) != 0]
    waiting = set().union(*(_linked_tickets(item) for item in proposals if _int(item.get("status")) == 1)) if proposals else set()
    ticket_items = sorted((_ticket(item, str(item.get("id")) in waiting) for item in tickets),
                          key=lambda item: item["created_at"] or "", reverse=True)
    offers = [_offer(item) for item in proposals]
    bills = [_invoice(item) for item in invoices]
    return {
        **public(session),
        "counts": {
            "offers_open": sum(1 for item in offers if item["status"] == 1),
            "invoices_open": sum(1 for item in bills if item["open"]),
            "repairs_running": sum(1 for item in ticket_items if item["open"]),
        },
        "tickets": ticket_items, "offers": offers, "invoices": bills,
    }


# ------------------------------------------------- offers and invoices (#65, #66)

class NotFound(PortalError):
    """Not there, or not this customer's: the same answer for both."""


DOC_PATH = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_. -]+\.pdf$")


def _socid(raw: dict) -> str:
    return str(raw.get("socid") or raw.get("fk_soc") or "")


async def _owned(client: httpx.AsyncClient, cfg: dict, kind: str, object_id: int, session: dict) -> dict:
    """One offer or invoice of this customer; a draft counts as not there."""
    response = await client.get(f"{cfg['base']}/api/index.php/{kind}/{int(object_id)}", headers=dolibarr._headers(cfg))
    if response.status_code == 404:
        raise NotFound("Das gibt es hier nicht.")
    response.raise_for_status()
    raw = response.json() or {}
    if _socid(raw) not in {str(value) for value in _ids(session)} or _int(raw.get("status", raw.get("statut"))) == 0:
        raise NotFound("Das gibt es hier nicht.")
    return raw


def _lines(raw: dict) -> list[dict]:
    from .site_data import plain_text

    items = []
    for line in raw.get("lines") or []:
        text = plain_text(line.get("product_label") or line.get("libelle") or line.get("label") or "")
        details = plain_text(line.get("desc") or line.get("description") or "")
        items.append({"text": text or details, "details": details if text and details != text else "",
                      "qty": line.get("qty"), "total": line.get("total_ttc")})
    return items


async def _config() -> dict:
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        raise PortalError("Der Kundenbereich ist gerade nicht erreichbar.")
    return cfg


def _expired(raw: dict) -> bool:
    until = _int(raw.get("fin_validite"))
    return bool(until) and until + 24 * 60 * 60 < now_utc().timestamp()


async def offer(session: dict, offer_id: int) -> dict:
    cfg = await _config()
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        raw = await _owned(client, cfg, "proposals", offer_id, session)
    item = _offer(raw)
    item.update({"lines": _lines(raw), "net": raw.get("total_ht"), "vat": raw.get("total_tva"),
                 "expired": _expired(raw), "can_answer": item["status"] == 1 and not _expired(raw)})
    return item


async def invoice(session: dict, invoice_id: int) -> dict:
    cfg = await _config()
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        raw = await _owned(client, cfg, "invoices", invoice_id, session)
    item = _invoice(raw)
    item.update({"lines": _lines(raw), "net": raw.get("total_ht"), "vat": raw.get("total_tva"), "payment": None})
    if item["open"] and not item["credit_note"]:
        item["payment"] = await payment_for(raw)
    return item


async def document(session: dict, kind: str, object_id: int) -> tuple[str, bytes]:
    """The PDF Dolibarr made for this offer or invoice."""
    import base64

    cfg = await _config()
    async with httpx.AsyncClient(timeout=max(cfg["timeout"], 20)) as client:
        raw = await _owned(client, cfg, kind, object_id, session)
        _folder, _, original = str(raw.get("last_main_doc") or "").partition("/")
        if not original or not DOC_PATH.fullmatch(original) or ".." in original:
            raise NotFound("Für dieses Dokument gibt es noch kein PDF.")
        module = "propal" if kind == "proposals" else "facture"
        response = await client.get(f"{cfg['base']}/api/index.php/documents/download", headers=dolibarr._headers(cfg),
                                    params={"modulepart": module, "original_file": original})
        if response.status_code == 404:
            raise NotFound("Für dieses Dokument gibt es noch kein PDF.")
        response.raise_for_status()
        body = response.json() or {}
    return original.rsplit("/", 1)[-1], base64.b64decode(body.get("content") or "")


# ------------------------------------------------------------ payment (#66)

def epc_payload(*, holder: str, iban: str, bic: str, amount, reference: str) -> str:
    """The text of the QR code Austrian banking apps read ("Zahlen mit Code"):
    the EPC standard for a SEPA credit transfer, version 002, UTF-8."""
    from decimal import ROUND_HALF_UP, Decimal

    value = Decimal(str(amount)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
    if not Decimal("0.01") <= value <= Decimal("999999999.99"):
        raise ValueError("Betrag außerhalb dessen, was eine Überweisung erlaubt")
    return "\n".join(["BCD", "002", "1", "SCT", bic, holder[:70], iban, f"EUR{value}", "", "", reference[:140]])


async def payment_for(raw: dict) -> dict | None:
    """Payee, IBAN, amount left and reference for an open invoice. The bank
    account is kept in the website settings: reading it from Dolibarr would
    need a right that also shows every bank movement."""
    settings = await get_db().settings.find_one({"_id": "site"}) or {}
    holder = str(settings.get("portal_bank_holder") or "").strip()
    iban = str(settings.get("portal_bank_iban") or "").strip()
    if not holder or not iban:
        return None
    bic = str(settings.get("portal_bank_bic") or "").strip()
    amount = raw.get("remaintopay") if raw.get("remaintopay") not in (None, "") else raw.get("total_ttc")
    reference = str(raw.get("ref") or "")
    try:
        epc = epc_payload(holder=holder, iban=iban, bic=bic, amount=amount, reference=reference)
    except (ValueError, ArithmeticError):
        return None
    return {"holder": holder, "iban": iban, "bic": bic, "amount": amount, "reference": reference, "epc": epc}


# ------------------------------------------------------------ answer (#65)

async def _company() -> dict:
    from .site_data import CACHE_ID

    cache = await get_db().site_cache.find_one({"_id": CACHE_ID}) or {}
    return cache.get("company") or {}


def money(value) -> str:
    """12345.5 -> "12.345,50 €"."""
    try:
        number = float(value)
    except (TypeError, ValueError):
        return str(value or "")
    return f"{number:,.2f} €".replace(",", "X").replace(".", ",").replace("X", ".")


def acceptance_mail(*, ref: str, total, name: str, when: str, start_now: bool, company: dict,
                    site_base: str) -> tuple[str, str]:
    """The confirmation on a durable medium the law asks for (FAGG), with the
    withdrawal right and the model form."""
    town = " ".join(part for part in (company.get("zip"), company.get("town")) if part)
    address = ", ".join(part for part in (company.get("name") or "IT-Tabelander", company.get("address"), town,
                                          company.get("email")) if part)
    start = ("Du hast verlangt, dass ich sofort beginne. Trittst du zurück, bevor ich fertig bin, zahlst du den Teil,\n"
             "der bis dahin gemacht ist. Ist die Arbeit fertig, kannst du nicht mehr zurücktreten."
             if start_now else
             "Du hast nicht verlangt, dass ich sofort beginne. Ich melde mich deshalb, bevor ich vor Ablauf\n"
             "der 14 Tage anfange.")
    subject = f"Bestätigung: Angebot {ref} angenommen"
    text = f"""Hallo,

du hast im Kundenbereich das Angebot {ref} über {money(total)} angenommen,
am {when} Uhr, bestätigt mit dem Namen „{name}“.

{start}

Dein Rücktrittsrecht
Bist du Verbraucherin oder Verbraucher, kannst du innerhalb von 14 Tagen ab
heute ohne Angabe von Gründen vom Vertrag zurücktreten. Schick mir dazu eine
eindeutige Nachricht, zum Beispiel per E-Mail. Du kannst dafür das
Muster-Formular unten verwenden.

Muster-Widerrufsformular
(Wenn Sie den Vertrag widerrufen wollen, dann füllen Sie bitte dieses Formular aus und senden Sie es zurück.)
- An {address}:
- Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über den Kauf der folgenden Waren (*)/die Erbringung der folgenden Dienstleistung (*): Angebot {ref}
- Bestellt am (*)/erhalten am (*)
- Name des/der Verbraucher(s)
- Anschrift des/der Verbraucher(s)
- Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier)
- Datum
(*) Unzutreffendes streichen.

Alle Bedingungen: {site_base}/rechtliches/nutzungsbedingungen

Viele Grüße
{company.get("name") or "IT-Tabelander"}
"""
    return subject, text


async def _notify_owner(subject: str, text: str) -> None:
    from .handover import warning_recipients

    try:
        recipients = await warning_recipients()
        if recipients:
            await mailer.send_mail(recipients, subject, text)
    except Exception as exc:  # noqa: BLE001 - Dolibarr has the answer already
        logger.warning("Owner not told about an offer answer: %s", type(exc).__name__)


async def answer(session: dict, offer_id: int, *, accept: bool, name: str = "", reason: str = "",
                 start_now: bool = False, ip: str = "") -> dict:
    """Accept (signed) or decline (not signed) in Dolibarr, with the proof in
    the offer's private note; the customer gets the confirmation by mail."""
    from .handover import _local_time

    cfg = await _config()
    when = _local_time(now_utc())
    # What the customer typed lands in a note Dolibarr shows as HTML: never as markup.
    signed, why = html.escape(name, quote=False), html.escape(reason, quote=False)
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        raw = await _owned(client, cfg, "proposals", offer_id, session)
        if _int(raw.get("status")) != 1 or _expired(raw):
            raise PortalError("Dieses Angebot kann nicht mehr beantwortet werden. Schreib mir einfach, dann klären wir es.")
        if accept:
            note = (f"Im Kundenbereich der Website angenommen am {when} Uhr, bestätigt mit dem Namen „{signed}“, "
                    f"angemeldet als {session['email']}, IP {ip or 'unbekannt'}. "
                    + ("Sofort beginnen verlangt: ja (Hinweis zum Rücktrittsrecht bestätigt)."
                       if start_now else
                       "Sofort beginnen verlangt: NEIN - vor Ablauf der 14 Tage nur nach Rückfrage beginnen."))
        else:
            note = (f"Im Kundenbereich der Website abgelehnt am {when} Uhr, angemeldet als {session['email']}, "
                    f"IP {ip or 'unbekannt'}." + (f" Grund: {why}" if why else ""))
        response = await client.post(
            f"{cfg['base']}/api/index.php/proposals/{int(offer_id)}/close", headers=dolibarr._headers(cfg),
            json={"status": 2 if accept else 3, "note_private": note, "notrigger": 0},
        )
        if response.status_code == 403:
            logger.warning("Offer answer refused by Dolibarr: the website user may not change offers")
            raise PortalError("Das hat gerade nicht geklappt. Bitte versuch es später noch einmal oder ruf kurz an.")
        if response.status_code != 304:
            response.raise_for_status()
    if response.status_code == 304:
        # A second click in the same moment: Dolibarr closed the offer already
        # for the first one, which also sent the mails. Show the answer, no
        # error and no second mail. (httpx counts 304 as an error status.)
        return await offer(session, offer_id)
    ref, total = raw.get("ref"), raw.get("total_ttc")
    if accept:
        subject, text = acceptance_mail(ref=ref, total=total, name=name, when=when, start_now=start_now,
                                        company=await _company(), site_base=cfg["site_base"])
        try:
            await mailer.send_mail(session["email"], subject, text)
        except Exception as exc:  # noqa: BLE001 - accepted in Dolibarr; the owner hears about the missing mail
            logger.warning("Acceptance confirmation not sent: %s", type(exc).__name__)
            note += " Die Bestätigungsmail an den Kunden ging NICHT hinaus - bitte selbst schicken."
        await _notify_owner(f"Angebot {ref} angenommen", note)
    else:
        await _notify_owner(f"Angebot {ref} abgelehnt", note)
    return await offer(session, offer_id)

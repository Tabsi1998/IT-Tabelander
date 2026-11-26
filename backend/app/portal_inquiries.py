"""Inquiries in the customer area (#122): one ticket with its history, the
customer's answers and closing it.

Everything comes from Dolibarr with every call and only for the customers
of the session: a ticket of another customer answers exactly like one that
does not exist.

The history is the ticket's first message and its public messages - never a
private one (TICKET_MSG_PRIVATE...), never Dolibarr's own log entries
("Ticket ... erstellt", "geändert"). Dolibarr's ticket API lists all events of
a ticket without saying which kind each is, so the history reads them from
the agenda, which names the kind ("code"). Answers by mail that Dolibarr's
e-mail collector puts on the ticket (AC_EMAIL_IN) show without the headers
and the quoted mail below.

An answer becomes a ticket message of the website's Dolibarr user, signed
"– geschrieben im Kundenbereich von <name> (<e-mail>)", so the workshop sees
who wrote it; the owner gets a short mail. "Hat sich erledigt" closes the
ticket. A closed ticket stays readable and takes no more answers.
"""
import html
import logging
import re
from urllib.parse import quote

import httpx

from . import dolibarr, mail_layout, mailer, portal
from .db import get_db, now_utc

logger = logging.getLogger("it-tabelander.portal")

SIGNATURE = "– geschrieben im Kundenbereich von "
# The kinds of ticket events a customer sees; None: decided by the signature.
PUBLIC_CODES = {"TICKET_MSG": None, "TICKET_MSG_SENTBYMAIL": "team", "AC_EMAIL_IN": "customer", "AC_EMAIL": "team"}
STATUS_NOT_READ, STATUS_ASSIGNED, STATUS_NEED_MORE_INFO, STATUS_CLOSED = 0, 2, 5, 8
CLOSED = {8, 9}
TRACK_ID = re.compile(r"^[A-Za-z0-9]{8,64}$")
NOT_HERE = "Diese Anfrage gibt es hier nicht."
CLOSED_TEXT = "Diese Anfrage ist abgeschlossen. Hast du noch etwas? Dann starte einfach eine neue Anfrage."


# ------------------------------------------------------------------ text

def message_text(value) -> str:
    """A message as plain text with its paragraphs; Dolibarr keeps some as HTML."""
    text = str(value or "").replace("\r\n", "\n").replace("\r", "\n")
    if re.search(r"<(br|p|div|li|ul|ol|span|b|i|strong|em|a|h\d|blockquote|table|tr|td)\b[^>]*>", text, re.I):
        text = text.replace("\n", " ")
        text = re.sub(r"<\s*br\s*/?>", "\n", text, flags=re.I)
        text = re.sub(r"<\s*li\b[^>]*>", "\n- ", text, flags=re.I)
        text = re.sub(r"</\s*(p|div|h\d|ul|ol|blockquote|table|tr)\s*>", "\n\n", text, flags=re.I)
        text = re.sub(r"<[^>]+>", "", text)
    text = html.unescape(text).replace(" ", " ")
    text = "\n".join(line.strip() for line in text.split("\n"))
    return re.sub(r"\n{3,}", "\n\n", text).strip()


# An answer by mail: where the quoted earlier mail begins.
QUOTE_START = re.compile(
    r"^(>|-{2,}\s*(original message|ursprüngliche nachricht)|(am|on)\s.{4,120}\s(schrieb|wrote)\b.*|"
    r"von:\s|from:\s|gesendet:\s|sent:\s)", re.I)


def email_text(text: str) -> str:
    """What Dolibarr's e-mail collector stores: notes about what it found,
    "-----", the mail, "----- Header" and the headers. Only the mail itself
    shows, without the earlier mail quoted below it."""
    lines = text.split("\n")
    marks = [index for index, line in enumerate(lines) if line.strip() == "-----"]
    if marks:
        lines = lines[marks[0] + 1:]
    kept = []
    for line in lines:
        if line.strip().startswith("----- Header") or QUOTE_START.match(line.strip()):
            break
        kept.append(line)
    return re.sub(r"\n{3,}", "\n\n", "\n".join(kept)).strip()


LEGACY_HINT = "Hinweis: Der Kunde war in Dolibarr schon vorhanden"
LEGACY_CONTACT = re.compile(r"\n\s*Kontakt:\s*\nName: ")
LEGACY_LINE = re.compile(r"^(Anfrage-Referenz|Quelle|Request-ID|Bewertungsbitte nach Abschluss|Fotos):|"
                         r"^- \d+ Foto\(s\)|^Kontaktnachricht über die Website \(|: –$|^–$")


def first_message_text(value) -> str:
    """The ticket's first message. Tickets of the website before #122 carry
    hints and the contact block for the workshop in it: those stay out."""
    text = message_text(value)
    legacy = text.startswith(LEGACY_HINT)
    if legacy:
        text = text.split("\n\n", 1)[1] if "\n\n" in text else ""
    contact = LEGACY_CONTACT.search(text)
    if contact:
        legacy, text = True, text[:contact.start()]
    if legacy:
        text = "\n".join(line for line in text.split("\n") if not LEGACY_LINE.search(line.strip()))
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def _signed(text: str) -> tuple[str, str | None]:
    """The text without the signature of the customer area, and who signed."""
    at = text.rfind(SIGNATURE)
    if at < 0:
        return text, None
    return text[:at].rstrip(), text[at + len(SIGNATURE):].strip()


def _name_only(signer: str) -> str:
    """"Max Muster (max@example.com)" -> "Max Muster"."""
    found = re.fullmatch(r"(.*?)\s*\([^()]*@[^()]*\)", signer)
    return (found.group(1) if found else signer).strip()


def _mail_sender(value) -> str:
    """'"Max Muster" <max@example.com>' -> "Max Muster"."""
    text = str(value or "").strip()
    found = re.fullmatch(r'"?([^"<]*?)"?\s*<[^>]+>', text)
    return (found.group(1).strip() if found and found.group(1).strip() else text.strip("<>"))[:120]


ADDRESS = re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")


def _addresses(value) -> set[str]:
    return {found.lower() for found in ADDRESS.findall(str(value or ""))}


def history(raw: dict, events: list[dict], customer: set[str] = frozenset()) -> list[dict]:
    """The first message, then every public message, oldest first. A mail the
    collector put on the ticket shows only between the workshop and the
    customer's own addresses (customer): a supplier's answer or a mail to
    someone else about the same ticket stays out."""
    customer = {address.lower() for address in customer} | _addresses(raw.get("origin_email"))
    items = []
    first = first_message_text(raw.get("message"))
    if first:
        from_customer = bool(str(raw.get("origin_email") or "").strip())
        items.append({"id": "start", "at": dolibarr._iso_time(raw.get("datec")),
                      "from": "customer" if from_customer else "team", "author": None if from_customer else "IT-Tabelander",
                      "via": None, "text": first})
    for event in sorted(events, key=lambda item: (portal._int(item.get("datep")), portal._int(item.get("id")))):
        code = str(event.get("code") or "")
        if code not in PUBLIC_CODES:
            continue
        if code == "AC_EMAIL_IN" and not _addresses(event.get("email_from")) & customer:
            continue
        if code == "AC_EMAIL" and not _addresses(event.get("email_to")) & customer:
            continue
        text = message_text(event.get("note_private") or event.get("note") or "")
        who, author, via = PUBLIC_CODES[code], None, "email" if code.startswith("AC_EMAIL") or code.endswith("SENTBYMAIL") else None
        if code == "AC_EMAIL_IN":
            text, author = email_text(text), _mail_sender(event.get("email_from")) or None
        elif who is None:
            text, signer = _signed(text)
            if signer is not None:
                who, author, via = "customer", _name_only(signer) or None, "portal"
            else:
                # Dolibarr's own public page writes without a user; the workshop always with one.
                who = "customer" if portal._int(event.get("authorid") or event.get("userownerid")) <= 0 else "team"
        if who == "team":
            author = "IT-Tabelander"
        if text:
            items.append({"id": str(event.get("id")), "at": dolibarr._iso_time(event.get("datep")), "from": who,
                          "author": author, "via": via, "text": text})
    return items


# ------------------------------------------------------------------ Dolibarr

def _status(raw: dict) -> int:
    return portal._int(raw.get("fk_statut", raw.get("status")))


async def _owned_ticket(client: httpx.AsyncClient, cfg: dict, session: dict, ticket_id: int) -> dict:
    response = await client.get(f"{cfg['base']}/api/index.php/tickets/{int(ticket_id)}", headers=dolibarr._headers(cfg),
                                params={"contact_list": -1})
    if response.status_code == 404:
        raise portal.NotFound(NOT_HERE)
    response.raise_for_status()
    raw = response.json() or {}
    if portal._socid(raw) not in {str(value) for value in portal._ids(session)}:
        raise portal.NotFound(NOT_HERE)
    return raw


async def _events(client: httpx.AsyncClient, cfg: dict, ticket_id) -> list[dict]:
    try:
        return await portal._list(client, cfg, "agendaevents", {
            "sqlfilters": f"(t.fk_element:=:{int(ticket_id)}) and (t.elementtype:=:'ticket')",
            "sortfield": "t.datep", "sortorder": "ASC", "limit": 500})
    except httpx.HTTPStatusError as exc:
        if exc.response.status_code != 403:
            raise
        logger.warning("Inquiry history without messages: the website user may not read agenda events")
        return []


async def _set_status(client: httpx.AsyncClient, cfg: dict, ticket_id, values: dict) -> None:
    response = await client.put(f"{cfg['base']}/api/index.php/tickets/{int(ticket_id)}", headers=dolibarr._headers(cfg),
                                json=values)
    response.raise_for_status()


async def _author(client: httpx.AsyncClient, cfg: dict, session: dict) -> str:
    """"Max Muster (max@example.com)": the contact with this address, else the customer."""
    email = session["email"]
    name = ""
    try:
        # The address passed clean_email when the session began: no quote can get in.
        contacts = await portal._list(client, cfg, "contacts", {"sqlfilters": f"(t.email:=:'{email}')", "limit": 20})
    except httpx.HTTPError:
        contacts = []
    allowed = {str(value) for value in portal._ids(session)}
    for contact in contacts:
        if str(contact.get("socid") or contact.get("fk_soc") or "") in allowed:
            name = " ".join(str(contact.get(key) or "").strip() for key in ("firstname", "lastname")).strip()
            if name:
                break
    name = name or ", ".join(customer["name"] for customer in session["customers"] if customer.get("name"))
    return f"{name} ({email})" if name else email


# ------------------------------------------------------------------ pages

async def find_by_track(session: dict, track_id: str) -> dict:
    """The inquiry behind the link in Dolibarr's mails, if it is this customer's."""
    if not TRACK_ID.fullmatch(str(track_id or "")):
        raise portal.NotFound(NOT_HERE)
    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        response = await client.get(f"{cfg['base']}/api/index.php/tickets/track_id/{quote(track_id, safe='')}",
                                    headers=dolibarr._headers(cfg), params={"contact_list": -1})
        if response.status_code == 404:
            raise portal.NotFound(NOT_HERE)
        response.raise_for_status()
        raw = response.json() or {}
    if portal._socid(raw) not in {str(value) for value in portal._ids(session)}:
        raise portal.NotFound(NOT_HERE)
    return {"id": portal._int(raw.get("id"))}


async def detail(session: dict, ticket_id: int) -> dict:
    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        raw = await _owned_ticket(client, cfg, session, ticket_id)
        code = _status(raw)
        waiting = code not in CLOSED and await dolibarr._offer_waiting(client, cfg, str(raw.get("id")), portal._socid(raw))
        events = await _events(client, cfg, raw.get("id"))
    return {**portal._ticket(raw, waiting), "closed_at": dolibarr._iso_time(raw.get("date_close")),
            "can_reply": code not in CLOSED, "messages": history(raw, events, {session["email"]})}


# ------------------------------------------------------------------ the owner

def owner_mail(*, what: str, raw: dict, author: str, text: str = "", site_base: str = "") -> tuple[str, str, str]:
    """The owner's notice about something the customer did (#122)."""
    ref = str(raw.get("ref") or raw.get("id") or "")
    title = portal._ticket(raw, False)["title"] or "Anfrage"
    link = f"{mail_layout.ERP_URL}/ticket/card.php?track_id={quote(str(raw.get('track_id') or ''), safe='')}"
    if what == "answer":
        subject = f"Kundenbereich: Antwort zu {ref}"
        parts = [mail_layout.paragraph(f"{author} hat im Kundenbereich zur Anfrage „{title}“ ({ref}) geschrieben:"),
                 mail_layout.quote(text)]
    else:
        subject = f"Kundenbereich: {ref} als erledigt geschlossen"
        parts = [mail_layout.paragraph(f"{author} hat die Anfrage „{title}“ ({ref}) im Kundenbereich als erledigt "
                                       "geschlossen. In Dolibarr ist das Ticket jetzt geschlossen.")]
    parts.append(mail_layout.button("Ticket in Dolibarr öffnen", link))
    body_text, body_html = mail_layout.render(parts, site_base=site_base, closing=False)
    return subject, body_text, body_html


async def _notify_owner(cfg: dict, **mail) -> None:
    from .handover import warning_recipients

    try:
        recipients = await warning_recipients()
        if recipients:
            subject, text, body = owner_mail(site_base=cfg["site_base"], **mail)
            await mailer.send_mail(recipients, subject, text, html=body)
    except Exception as exc:  # noqa: BLE001 - the message is in Dolibarr already
        logger.warning("Owner not told about a customer-area message: %s", type(exc).__name__)


# ------------------------------------------------------------------ answers

async def reply(session: dict, ticket_id: int, text: str) -> dict:
    """The customer's answer as a ticket message; a ticket that waited for
    the customer goes back to the workshop, as Dolibarr's e-mail collector
    does with an answer by mail."""
    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        raw = await _owned_ticket(client, cfg, session, ticket_id)
        code = _status(raw)
        if code in CLOSED:
            raise portal.PortalError(CLOSED_TEXT)
        track = str(raw.get("track_id") or "")
        if not track:
            raise portal.PortalError("Auf diese Anfrage kannst du hier nicht antworten. Antworte einfach auf meine "
                                     "letzte E-Mail dazu.")
        author = await _author(client, cfg, session)
        await dolibarr.post_ticket_message(client, cfg, track_id=track,
                                           message=dolibarr.as_note(f"{text}\n\n{SIGNATURE}{author}"))
        if code == STATUS_NEED_MORE_INFO:
            back = STATUS_ASSIGNED if portal._int(raw.get("fk_user_assign")) > 0 else STATUS_NOT_READ
            try:
                await _set_status(client, cfg, raw.get("id"), {"status": back})
            except httpx.HTTPError as exc:  # the answer is there; the owner sees it in the mail
                logger.warning("Ticket state not reset after an answer: %s", type(exc).__name__)
    await _notify_owner(cfg, what="answer", raw=raw, author=author, text=text)
    return await detail(session, ticket_id)


async def close(session: dict, ticket_id: int) -> dict:
    """"Hat sich erledigt": the ticket closes; a second click changes nothing."""
    from .handover import _local_time

    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        raw = await _owned_ticket(client, cfg, session, ticket_id)
        if _status(raw) in CLOSED:
            return await detail(session, ticket_id)
        author = await _author(client, cfg, session)
        now = now_utc()
        await _set_status(client, cfg, raw.get("id"),
                          {"status": STATUS_CLOSED, "progress": 100, "date_close": int(now.timestamp())})
        try:
            await dolibarr.post_ticket_message(
                client, cfg, track_id=str(raw.get("track_id") or ""), private=True,
                message=dolibarr.as_note(f"Im Kundenbereich als erledigt geschlossen von {author}, "
                                         f"am {_local_time(now)} Uhr."))
        except httpx.HTTPError as exc:  # closed is closed; the owner gets the mail
            logger.warning("No note about a customer closing a ticket: %s", type(exc).__name__)
    # Closed by the customer is no finished job: no review request for it (#71).
    await get_db().repair_requests.update_many(
        {"dolibarr.ticket_id": str(raw.get("id")), "review.invited_at": {"$exists": False},
         "review.stopped": {"$exists": False}},
        {"$set": {"review.stopped": "Der Kunde hat die Anfrage im Kundenbereich selbst geschlossen."}})
    await _notify_owner(cfg, what="closed", raw=raw, author=author)
    return await detail(session, ticket_id)

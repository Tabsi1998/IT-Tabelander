"""The online withdrawal function the law asks for (§ 13a FAGG, #142).

A consumer who concluded a contract online can withdraw on the website,
without signing in: "Vertrag widerrufen" opens the form, "Widerruf bestätigen"
sends it. The law asks for the name, the contract and the address for the
confirmation; the confirmation goes out at once on a durable medium (e-mail)
with the declaration and the date and time it arrived.

What happens with a declaration:
- the customer gets the confirmation (the site's own mail, look of mail_layout),
- the owner gets a notice at the "Warnungen an" address,
- an offer with that number gets a private note in Dolibarr (best effort:
  nobody signed in, so the note says the sender is not checked).

The website keeps only the receipt number, the times and what went out;
name, address and contract go once the owner has the notice.
"""
import html
import logging
import secrets

import httpx
from pymongo.errors import DuplicateKeyError

from . import dolibarr, mail_layout, mailer
from .db import get_db, now_utc

logger = logging.getLogger("it-tabelander.withdrawals")

SCOPES = {"all": "den ganzen Vertrag", "part": "einen Teil des Vertrags"}
PERSONAL = ("name", "email", "contract", "scope", "parts")


def _receipt() -> str:
    return "WR-" + secrets.token_hex(4).upper()


def _local(value) -> str:
    from .handover import _local_time

    return _local_time(value)


def declaration_lines(record: dict) -> list[str]:
    """The declaration as the customer made it, for both mails and the note."""
    lines = [
        f"Ich widerrufe {SCOPES[record['scope']]} mit der Nummer {record['contract']}.",
        f"Name: {record['name']}",
        f"E-Mail-Adresse für die Bestätigung: {record['email']}",
    ]
    if record["scope"] == "part":
        lines.insert(1, f"Widerrufener Teil: {record['parts']}")
    return lines


def confirmation_mail(record: dict, *, when: str, company: dict, site_base: str) -> tuple[str, str, str]:
    """Subject, text and HTML of the confirmation of receipt."""
    town = " ".join(part for part in (company.get("zip"), company.get("town")) if part)
    sender = ", ".join(part for part in (company.get("name") or "IT-Tabelander", company.get("address"), town,
                                         company.get("email")) if part)
    subject = f"Eingangsbestätigung: Widerruf {record['contract']}"
    text, body = mail_layout.render([
        mail_layout.paragraph(f"Hallo {record['name']},"),
        mail_layout.paragraph(f"dein Widerruf ist am {when} Uhr bei mir eingegangen. Deine Nummer dafür: "
                              f"{record['receipt']}."),
        mail_layout.heading("Das hast du erklärt"),
        mail_layout.items(*declaration_lines(record)),
        mail_layout.paragraph("Diese Mail bestätigt, dass deine Erklärung angekommen ist. Ich melde mich mit den "
                              "nächsten Schritten, etwa zur Rückzahlung oder zur Rückgabe."),
        mail_layout.paragraph(f"Empfänger der Erklärung: {sender}"),
        mail_layout.link("Widerrufsbelehrung", f"{site_base}/rechtliches/widerruf"),
    ], site_base=site_base)
    return subject, text, body


async def _note_on_offer(record: dict, when: str) -> str:
    """A private note on the accepted offer with this number: "added",
    "not_found", "not_signed", "off" (no Dolibarr) or "failed"."""
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        return "off"
    lines = "<br>\n".join(html.escape(line, quote=False) for line in declaration_lines(record))
    note = (f"<b>Widerruf über die Website eingegangen am {when} Uhr</b> (Nummer {record['receipt']}). "
            "Der Absender war nicht angemeldet: bitte prüfen, ob er der Kunde ist.<br>\n" + lines)
    try:
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
            # The number passed the pattern of WithdrawalInput: no quote or bracket can reach the filter.
            response = await client.get(
                f"{cfg['base']}/api/index.php/proposals", headers=dolibarr._headers(cfg),
                params={"sqlfilters": f"(t.ref:=:'{record['contract']}')", "limit": 1})
            if response.status_code == 404:
                return "not_found"
            response.raise_for_status()
            found = response.json() or []
            if not found:
                return "not_found"
            # The full offer, not the list entry: the note holds the proof of
            # the acceptance and must never be cut short.
            response = await client.get(f"{cfg['base']}/api/index.php/proposals/{int(found[0]['id'])}",
                                        headers=dolibarr._headers(cfg))
            response.raise_for_status()
            offer = response.json() or {}
            # Only an accepted offer is a contract to withdraw from; nobody signed
            # in, so other offers stay untouched by strangers.
            if int(offer.get("status") or offer.get("statut") or 0) != 2:
                return "not_signed"
            earlier = str(offer.get("note_private") or "").strip()
            response = await client.put(
                f"{cfg['base']}/api/index.php/proposals/{int(offer['id'])}", headers=dolibarr._headers(cfg),
                json={"note_private": f"{earlier}<br>\n<br>\n{note}" if earlier else note})
            response.raise_for_status()
    except (httpx.HTTPError, ValueError, KeyError, TypeError) as exc:
        logger.warning("Withdrawal note on the offer failed: %s", type(exc).__name__)
        return "failed"
    return "added"


async def _tell_owner(record: dict, *, when: str, mail_sent: bool, note: str) -> bool:
    from .handover import warning_recipients

    states = {"added": "Am Angebot in Dolibarr vermerkt (private Notiz).",
              "not_found": "In Dolibarr gibt es kein Angebot mit dieser Nummer: bitte Auftrag oder Rechnung suchen.",
              "not_signed": "Das Angebot mit dieser Nummer ist nicht angenommen: nichts vermerkt, bitte prüfen.",
              "off": "Dolibarr ist nicht verbunden: bitte selbst vermerken.",
              "failed": "Der Vermerk in Dolibarr hat nicht geklappt: bitte selbst vermerken."}
    text = "\n".join([
        f"Widerruf über die Website eingegangen am {when} Uhr (Nummer {record['receipt']}).",
        "Der Absender war nicht angemeldet: bitte prüfen, ob er der Kunde ist.",
        "",
        *declaration_lines(record),
        "",
        states[note],
        "Eingangsbestätigung an den Kunden: " + ("verschickt." if mail_sent else
                                                  "NICHT verschickt - bitte selbst per E-Mail bestätigen (Pflicht)."),
    ])
    try:
        recipients = await warning_recipients()
        if not recipients:
            return False
        await mailer.send_mail(recipients, f"Widerruf eingegangen: {record['contract']}", text)
    except Exception as exc:  # noqa: BLE001 - the record stays until the owner has it
        logger.warning("Owner not told about a withdrawal: %s", type(exc).__name__)
        return False
    return True


def _answer(record: dict) -> dict:
    return {"receipt": record["receipt"], "received_at": record["received_at"].isoformat(),
            "received_local": _local(record["received_at"]), "mail_sent": bool(record.get("mail_sent"))}


async def submit(payload) -> dict:
    """Take a declaration: confirm it, tell the owner, note it at the offer."""
    db = get_db()
    existing = await db.withdrawals.find_one({"request_id": payload.request_id})
    if existing:  # the same click again, e.g. after a lost answer: no second mail
        return _answer(existing)
    received = now_utc()
    record = {"request_id": payload.request_id, "receipt": _receipt(), "received_at": received,
              "name": payload.name, "email": str(payload.email).lower(), "contract": payload.contract,
              "scope": payload.scope, "parts": payload.parts if payload.scope == "part" else ""}
    try:
        await db.withdrawals.insert_one(record)
    except DuplicateKeyError:  # the same click twice at once: the first one sends
        return _answer(await db.withdrawals.find_one({"request_id": payload.request_id}))
    when = _local(received)

    from .portal import _company

    cfg = await dolibarr.get_config()
    subject, text, body = confirmation_mail(record, when=when, company=await _company(), site_base=cfg["site_base"])
    try:
        await mailer.send_mail(record["email"], subject, text, html=body)
        record["mail_sent"] = True
    except Exception as exc:  # noqa: BLE001 - the owner hears about it and confirms by hand
        logger.warning("Withdrawal confirmation not sent: %s", type(exc).__name__)
        record["mail_sent"] = False
    note = await _note_on_offer(record, when)
    told = await _tell_owner(record, when=when, mail_sent=record["mail_sent"], note=note)
    update = {"$set": {"mail_sent": record["mail_sent"], "dolibarr_note": note, "owner_told": told}}
    if told:
        update["$unset"] = {key: "" for key in PERSONAL}
    await db.withdrawals.update_one({"_id": record["_id"]}, update)
    logger.info("Withdrawal %s received (mail %s, note %s, owner %s)", record["receipt"],
                record["mail_sent"], note, told)
    return _answer(record)

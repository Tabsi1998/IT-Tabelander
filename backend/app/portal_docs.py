"""Documents in the customer area (#187): AGB, Nutzungsbedingungen, AVV,
Fernwartung and the like, with their state, as PDF, to confirm or to sign.

What a customer needs and where each document stands is decided in Dolibarr
by the module "IT-Tabelander PDF" (rules and history per customer) and read
on every call through its REST API (kunden/{socid}/unterlagen). Nothing of it
is stored here.

Who may act is checked twice: the website asks only for the customers of the
session (portal._ids), the module only answers for documents in the status
rows of that customer and never gives out a blank template made per
customer. A confirmation (name and tick) or a signature (drawn on the
screen) lands in the customer's history in Dolibarr with name, e-mail, IP
and the "Stand" of the document as proof; the owner gets a short mail.
"""
import base64
import logging
import re
from urllib.parse import quote

import httpx

from . import dolibarr, mail_layout, mailer, portal
from .db import now_utc

logger = logging.getLogger("it-tabelander.portal")

SLUG = re.compile(r"^[a-z0-9-]{1,64}$")
STAND = re.compile(r"^(\d{4}-\d{2}-\d{2})?$")
# A signature as js/sign.js of the module sends it: base64 without "data:"
# and "=" (Dolibarr's WAF refuses both); the module decodes and checks it.
PNG_BASE64 = re.compile(r"^[A-Za-z0-9+/]+$")
PNG_MAX = 800_000  # characters, about 600 kB of image like the module's limit
MEDIA = {"application/pdf", "image/jpeg", "image/png"}
NOT_THERE = "Diese Unterlage gibt es hier nicht."
LATER = "Das hat gerade nicht geklappt. Bitte versuch es später noch einmal."


def _path(socid: int, slug: str = "", action: str = "") -> str:
    tail = f"/{slug}" + (f"/{action}" if action else "") if slug else ""
    return f"ittabelanderpdf/kunden/{int(socid)}/unterlagen{tail}"


def _customer(session: dict, customer_id: int | None) -> int:
    """The customer the call is for: one of the session's, the only one when none is named."""
    ids = portal._ids(session)
    if customer_id is None and len(ids) == 1:
        return ids[0]
    if customer_id is not None and customer_id in ids:
        return customer_id
    raise portal.NotFound(NOT_THERE)


def _slug(slug: str) -> str:
    if not isinstance(slug, str) or not SLUG.fullmatch(slug):
        raise portal.NotFound(NOT_THERE)
    return slug


def _item(raw: dict, customer: dict) -> dict:
    """What the page shows, from a whitelist."""
    done = raw.get("done") if isinstance(raw.get("done"), dict) else None
    state = str(raw.get("state") or "missing")
    return {
        "customer_id": customer["id"], "customer": customer["name"],
        "slug": str(raw.get("slug") or ""), "title": str(raw.get("title") or raw.get("slug") or ""),
        "stand": str(raw.get("stand") or "") if STAND.fullmatch(str(raw.get("stand") or "")) else "",
        "state": state if state in {"missing", "sent", "generated", "signing", "done", "outdated"} else "missing",
        "action": str(raw.get("action") or ""), "required": bool(raw.get("required")),
        "can_confirm": raw.get("can_confirm") is True, "can_sign": raw.get("can_sign") is True,
        "waiting_countersign": raw.get("waiting_countersign") is True,
        "done_at": str(done.get("date") or "") if done else "",
        "has_pdf": raw.get("pdf") in ("signed", "generated", "general"),
        "signed_pdf": raw.get("pdf") == "signed",
    }


def _problem(response: httpx.Response) -> None:
    """Dolibarr's answer as an error the customer can read; nothing if it is fine."""
    if response.status_code < 400:
        return
    if response.status_code == 404:
        raise portal.NotFound(NOT_THERE)
    try:
        message = str(((response.json() or {}).get("error") or {}).get("message") or "")
    except ValueError:
        message = ""
    if response.status_code == 409 and "changed" in message:
        raise portal.PortalError("Diese Unterlage hat inzwischen einen neuen Stand. Bitte lade die Seite neu und lies sie noch einmal.")
    if response.status_code == 409:
        raise portal.PortalError("Das ist schon erledigt oder gerade nicht möglich. Lade die Seite neu, dann siehst du den Stand.")
    if response.status_code == 400 and "image" in message:
        raise portal.PortalError("Die Unterschrift ist nicht angekommen. Bitte unterschreib noch einmal.")
    if response.status_code == 400:
        raise portal.PortalError("Bitte prüf deinen Namen und versuch es noch einmal.")
    if response.status_code in (401, 403):
        logger.warning("Customer area documents refused by Dolibarr (%s): the website user needs \"Unterlagen lesen\"",
                       response.status_code)
        raise portal.PortalError(LATER)
    response.raise_for_status()


async def documents(session: dict) -> list[dict]:
    """The documents of every customer of the session. A Dolibarr without the
    module or without the right shows none instead of an error."""
    cfg = await portal._config()
    items = []
    async with httpx.AsyncClient(timeout=max(cfg["timeout"], 20)) as client:
        for customer in session["customers"]:
            response = await client.get(f"{cfg['base']}/api/index.php/{_path(customer['id'])}", headers=dolibarr._headers(cfg))
            if response.status_code in (403, 404, 501):
                if response.status_code != 404:
                    logger.warning("Customer area without documents: Dolibarr answers %s", response.status_code)
                continue
            response.raise_for_status()
            listed = response.json()
            items += [_item(raw, customer) for raw in (listed if isinstance(listed, list) else []) if isinstance(raw, dict)
                      and SLUG.fullmatch(str(raw.get("slug") or ""))]
    return items


async def pdf(session: dict, slug: str, customer_id: int | None = None) -> tuple[str, str, bytes]:
    """File name, type and content of the document as this customer gets it."""
    socid, slug = _customer(session, customer_id), _slug(slug)
    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=max(cfg["timeout"], 30)) as client:
        response = await client.get(f"{cfg['base']}/api/index.php/{_path(socid, slug, 'pdf')}", headers=dolibarr._headers(cfg))
        _problem(response)
    raw = response.json() or {}
    media = str(raw.get("content-type") or "")
    try:
        content = base64.b64decode(str(raw.get("content") or ""), validate=True)
    except (ValueError, TypeError):
        content = b""
    if media not in MEDIA or not content:
        raise portal.NotFound("Für diese Unterlage gibt es noch keine Datei.")
    name = re.sub(r"[^A-Za-z0-9._-]", "_", str(raw.get("filename") or f"{slug}.pdf"))[:120]
    return name, media, content


def _customer_of(session: dict, socid: int) -> dict:
    return next(customer for customer in session["customers"] if customer["id"] == socid)


async def _act(session: dict, socid: int, slug: str, action: str, body: dict) -> dict:
    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=max(cfg["timeout"], 60)) as client:
        response = await client.post(f"{cfg['base']}/api/index.php/{_path(socid, slug, action)}",
                                     headers=dolibarr._headers(cfg), json=body)
        _problem(response)
    return _item(response.json() or {}, _customer_of(session, socid))


async def confirm(session: dict, slug: str, *, name: str, version: str, ip: str = "",
                  customer_id: int | None = None) -> dict:
    """AGB or Nutzungsbedingungen confirmed with the name and the tick."""
    socid, slug = _customer(session, customer_id), _slug(slug)
    item = await _act(session, socid, slug, "bestaetigen",
                      {"name": name, "email": session["email"], "ip": ip, "version": version})
    await _notify_owner(session, item, what="confirm", name=name, ip=ip)
    return item


async def sign(session: dict, slug: str, *, name: str, png: str, version: str, ip: str = "",
               customer_id: int | None = None) -> dict:
    """AVV or Fernwartung signed on the screen; the counter signature follows in the workshop."""
    socid, slug = _customer(session, customer_id), _slug(slug)
    item = await _act(session, socid, slug, "unterschreiben",
                      {"name": name, "email": session["email"], "ip": ip, "version": version, "png": png})
    await _notify_owner(session, item, what="sign", name=name, ip=ip)
    return item


# ------------------------------------------------------------------ the owner

def owner_mail(*, what: str, item: dict, name: str, email: str, ip: str, when: str) -> tuple[str, str, str]:
    """The owner's notice, in the look of the other mails (no logo on top, signature below)."""
    # Names from Dolibarr go into the subject: one line, whatever they hold.
    title, customer = " ".join(item["title"].split()), " ".join(item["customer"].split())
    stand = f", Stand {item['stand'][8:10]}.{item['stand'][5:7]}.{item['stand'][:4]}" if item["stand"] else ""
    proof = f"Name „{name}“, angemeldet als {email}, IP {ip or 'unbekannt'}{stand}."
    if what == "confirm":
        subject = f"Kundenbereich: {title} bestätigt ({customer})"
        parts = [mail_layout.paragraph(f"{customer} hat im Kundenbereich „{title}“ am {when} Uhr bestätigt."),
                 mail_layout.paragraph(proof),
                 mail_layout.paragraph("Die Unterlage ist in Dolibarr erledigt; der Nachweis steht im Verlauf und in der Agenda.")]
    else:
        subject = f"Kundenbereich: {title} unterschrieben ({customer}) – Gegenzeichnung offen"
        parts = [mail_layout.paragraph(f"{customer} hat im Kundenbereich „{title}“ am {when} Uhr unterschrieben."),
                 mail_layout.paragraph(proof),
                 mail_layout.paragraph("Jetzt fehlt deine Unterschrift: im Reiter „Unterlagen“ des Kunden auf "
                                       "„Weiter unterschreiben“. Bis dahin steht sie auf „Unterschrift läuft“.")]
    link = f"{mail_layout.ERP_URL}/custom/ittabelanderpdf/docs.php?socid={quote(str(item['customer_id']), safe='')}"
    parts.append(mail_layout.button("Unterlagen in Dolibarr öffnen", link))
    text, body = mail_layout.render(parts, closing=False)
    return subject, text, body


async def _notify_owner(session: dict, item: dict, *, what: str, name: str, ip: str) -> None:
    from .handover import _local_time, warning_recipients

    try:
        recipients = await warning_recipients()
        if recipients:
            subject, text, body = owner_mail(what=what, item=item, name=name, email=session["email"], ip=ip,
                                             when=_local_time(now_utc()))
            await mailer.send_mail(recipients, subject, text, html=body)
    except Exception as exc:  # noqa: BLE001 - Dolibarr has the proof already
        logger.warning("Owner not told about a document in the customer area: %s", type(exc).__name__)

"""Files of an inquiry in the customer area (#123): see them, add photos and PDFs.

Dolibarr keeps every file of a ticket in one folder, the workshop's own
ones too (a supplier's invoice, notes, a backup log). So the customer sees
only what is meant for him:

- what he sent through the website ("kunde-…" from the customer area,
  "anfrage-foto-…" from the inquiry form),
- what the workshop sent with a public ticket message (Dolibarr links such a
  file to the message),
- what the workshop shares explicitly in Dolibarr (the share link of a file).

An upload is checked by its content, not by its name or the browser's word:
a photo (JPEG, PNG, WebP) is decoded and stored anew as WebP - upright,
without EXIF and location, at most 2000 pixels -, a PDF must begin like one.
It goes straight to the ticket through Dolibarr's document API; the website
keeps nothing of it.
"""
import base64
import logging
import re
import secrets
from datetime import timezone

import httpx
from fastapi.concurrency import run_in_threadpool

from . import dolibarr, portal
from .db import now_utc

logger = logging.getLogger("it-tabelander.portal")

IMAGE_MAX = 15 * 1024 * 1024   # a phone photo; it leaves as a WebP of a few hundred KB
PDF_MAX = 5 * 1024 * 1024      # Dolibarr takes the file base64 in one request (PHP post_max_size 8 MB by default)
FILES_PER_TICKET = 40          # from the customer, on one ticket
PHOTO_SIDE = 2000
CUSTOMER_PREFIXES = ("kunde-", "anfrage-foto-")
UPLOADED = re.compile(r"^kunde-\d{8}-\d{6}-[a-f0-9]{6}(-[A-Za-z0-9_-]{1,40})?\.(webp|pdf)$")
NAME = re.compile(r"^[^/\\<>|\x00-\x1f]{1,200}$")
TYPES = {"webp": "image/webp", "jpg": "image/jpeg", "jpeg": "image/jpeg", "png": "image/png", "gif": "image/gif",
         "pdf": "application/pdf"}
NOT_A_FILE = "Das geht leider nicht: nur Fotos (JPG, PNG, WebP) oder PDF-Dateien."


def _name(raw: dict) -> str:
    """Dolibarr 23 and 24 name a file in different fields of the list."""
    return next((str(raw[key]) for key in ("name", "relativename", "filename") if raw.get(key)), "")


def visible(files: list[dict], public_events: set[str]) -> list[dict]:
    """The files of a ticket that are meant for the customer, oldest first."""
    shown = []
    for raw in files:
        name = _name(raw)
        if not NAME.fullmatch(name) or name.startswith(".") or name.endswith(".noexe"):
            continue
        from_customer = name.startswith(CUSTOMER_PREFIXES)
        shared = bool(str(raw.get("share") or "").strip())
        with_message = str(raw.get("agenda_id") or "") in public_events
        if not (from_customer or shared or with_message):
            continue
        extension = name.rsplit(".", 1)[-1].lower() if "." in name else ""
        kind = "pdf" if extension == "pdf" else "image" if TYPES.get(extension, "").startswith("image/") else "file"
        shown.append({"name": name, "size": portal._int(raw.get("size")), "kind": kind,
                      "at": dolibarr._iso_time(raw.get("date")), "from": "customer" if from_customer else "team"})
    return sorted(shown, key=lambda item: (item["at"] or "", item["name"]))


async def ticket_files(client: httpx.AsyncClient, cfg: dict, ticket_id) -> list[dict]:
    """Every file in the ticket's folder; none without the right to read them."""
    try:
        return await portal._list(client, cfg, "documents", {"modulepart": "ticket", "id": int(ticket_id)})
    except httpx.HTTPStatusError as exc:
        if exc.response.status_code not in (403, 500, 501):
            raise
        logger.warning("Inquiry files not listed: Dolibarr answers %s", exc.response.status_code)
        return []


def _folder(raw: dict) -> str:
    """The ticket's folder, as Dolibarr's dol_sanitizeFileName writes its reference."""
    return re.sub(r'[\\/:*?"<>|\[\],;=]', "_", str(raw.get("ref") or ""))


async def download(session: dict, ticket_id: int, name: str) -> tuple[str, str, bytes]:
    """One file of the customer's own ticket, if it is one he may see."""
    from . import portal_inquiries

    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=max(cfg["timeout"], 30)) as client:
        raw = await portal_inquiries._owned_ticket(client, cfg, session, ticket_id)
        events = {item["id"] for item in portal_inquiries.history(
            raw, await portal_inquiries._events(client, cfg, raw.get("id")), {session["email"]})}
        allowed = {item["name"] for item in visible(await ticket_files(client, cfg, raw.get("id")), events)}
        if name not in allowed:
            raise portal.NotFound("Diese Datei gibt es hier nicht.")
        response = await client.get(f"{cfg['base']}/api/index.php/documents/download", headers=dolibarr._headers(cfg),
                                    params={"modulepart": "ticket", "original_file": f"{_folder(raw)}/{name}"})
        if response.status_code == 404:
            raise portal.NotFound("Diese Datei gibt es hier nicht.")
        response.raise_for_status()
        content = base64.b64decode((response.json() or {}).get("content") or "")
    extension = name.rsplit(".", 1)[-1].lower() if "." in name else ""
    return name, TYPES.get(extension, "application/octet-stream"), content


def _clean_stem(filename: str) -> str:
    """The original name, reduced to what is safe in any file system and URL."""
    stem = str(filename or "").rsplit(".", 1)[0]
    stem = re.sub(r"[^A-Za-z0-9_-]+", "-", stem.translate(str.maketrans("äöüÄÖÜß", "aouAOUs"))).strip("-_")
    return stem[:40]


def prepare(raw: bytes, filename: str) -> tuple[str, bytes]:
    """The file as it goes to Dolibarr - (name, content) - or ValueError with
    a message for the customer. Runs in a worker thread: decoding takes CPU."""
    from .routers.media import ImageTooLarge, webp_bytes

    if not raw:
        raise ValueError("Die Datei ist leer.")
    if raw.startswith(b"%PDF-"):
        if len(raw) > PDF_MAX:
            raise ValueError("Die PDF-Datei ist zu groß (höchstens 5 MB).")
        extension, content = "pdf", raw
    else:
        if len(raw) > IMAGE_MAX:
            raise ValueError("Das Foto ist zu groß (höchstens 15 MB).")
        try:
            content = webp_bytes(raw, max_dim=PHOTO_SIDE, formats=("JPEG", "PNG", "WEBP"))
        except ImageTooLarge as exc:
            raise ValueError(str(exc)) from None
        except Exception:  # noqa: BLE001 - whatever it is, it is no photo we take
            raise ValueError(NOT_A_FILE) from None
        extension = "webp"
    stamp = now_utc().astimezone(timezone.utc).strftime("%Y%m%d-%H%M%S")
    stem = _clean_stem(filename)
    return f"kunde-{stamp}-{secrets.token_hex(3)}{'-' + stem if stem else ''}.{extension}", content


async def upload(session: dict, ticket_id: int, raw: bytes, filename: str) -> dict:
    """A photo or PDF of the customer straight onto his open ticket."""
    from . import portal_inquiries

    cfg = await portal._config()
    async with httpx.AsyncClient(timeout=max(cfg["timeout"], 60)) as client:
        ticket = await portal_inquiries._owned_ticket(client, cfg, session, ticket_id)
        if portal_inquiries._status(ticket) in portal_inquiries.CLOSED:
            raise portal.PortalError(portal_inquiries.CLOSED_TEXT)
        existing = [_name(item) for item in await ticket_files(client, cfg, ticket.get("id"))]
        if sum(1 for name in existing if name.startswith("kunde-")) >= FILES_PER_TICKET:
            raise portal.PortalError("An dieser Anfrage hängen schon sehr viele Dateien. Schick mir weitere bitte per E-Mail.")
        try:
            name, content = await run_in_threadpool(prepare, raw, filename)
        except ValueError as exc:
            raise portal.PortalError(str(exc)) from None
        response = await client.post(f"{cfg['base']}/api/index.php/documents/upload", headers=dolibarr._headers(cfg), json={
            "filename": name, "modulepart": "ticket", "ref": str(int(ticket["id"])),
            "filecontent": base64.b64encode(content).decode("ascii"), "fileencoding": "base64",
            "overwriteifexists": 0,
        })
        response.raise_for_status()
    return {"name": name, "size": len(content), "kind": "pdf" if name.endswith(".pdf") else "image"}

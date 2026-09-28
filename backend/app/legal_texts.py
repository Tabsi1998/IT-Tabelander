"""The legal texts from Dolibarr: what is missing, and drafts to start from (#68, #69, #75).

The imprint is built from the company data in Dolibarr plus a knowledge
article for what the setup has no field for: trade, authority, chamber and
the legal form (Dolibarr's API does not give that one out). Privacy policy
and terms are knowledge articles of their own.

"Entwurf anlegen" writes a plain-language draft into Dolibarr's knowledge base
and picks it for the website. The places only the owner can fill in are
marked "[BITTE ERGÄNZEN ...]", "[BITTE PRÜFEN ...]" or "[BITTE ENTSCHEIDEN
...]"; the admin counts them and warns until every text is released and
complete.
"""
import html
import logging
import re
from pathlib import Path

import httpx

from . import dolibarr, site_data
from .db import get_db

logger = logging.getLogger("it-tabelander.legal")

DRAFTS_DIR = Path(__file__).resolve().parent / "legal_drafts"
OPEN_POINT = re.compile(r"\[BITTE (?:ERGÄNZEN|PRÜFEN|ENTSCHEIDEN|MIT DER WKO KLÄREN)[^\]]*\]")
# kind: (name in the admin, title of the article in Dolibarr)
TEXTS = {
    "impressum": ("Ergänzung zum Impressum", "Impressum – Ergänzung"),
    "datenschutz": ("Datenschutzerklärung", "Datenschutzerklärung"),
    "nutzungsbedingungen": ("Nutzungsbedingungen", "Nutzungsbedingungen"),
}
COMPANY_SETUP = "Dolibarr: Einstellungen → Unternehmen/Institution"
# What the imprint takes from Dolibarr's company data: fields (all needed, or
# any of them), name in the checklist, label in Dolibarr, required, note.
IMPRINT_FIELDS = (
    (("name",), "all", "Firmenname", "Firmenname", True, ""),
    (("address", "zip", "town"), "all", "Anschrift", "Firmenadresse, Postleitzahl und Stadt", True, ""),
    (("email",), "all", "E-Mail-Adresse", "E-Mail", True, ""),
    (("phone", "phone_mobile"), "any", "Telefon", "Telefon", False, "empfohlen"),
    (("managers",), "all", "Inhaber", "Name(n) des/der Manager", False, "empfohlen, wenn der Firmenname nicht dein Name ist"),
    (("socialobject",), "all", "Unternehmensgegenstand", "Gegenstand des Unternehmens", True, ""),
    (("tva_intra",), "all", "UID-Nummer", "Umsatzsteuer-ID", False, "nur wenn du eine hast"),
    (("idprof3",), "all", "Firmenbuchnummer", "Firmenbuchnummer", False, "nur wenn du im Firmenbuch stehst"),
)


def draft_html(kind: str) -> str:
    return (DRAFTS_DIR / f"{kind}.html").read_text(encoding="utf-8")


def open_points(text: str) -> list[str]:
    """The marked places still to fill in, as the owner sees them."""
    plain = html.unescape(re.sub(r"<[^>]+>", " ", str(text or "")))
    return [" ".join(match.group(0).split()) for match in OPEN_POINT.finditer(plain)]


def imprint_checklist(company: dict | None) -> list[dict]:
    company = company or {}
    items = []
    for fields, mode, label, where, required, note in IMPRINT_FIELDS:
        present = [bool(str(company.get(field) or "").strip()) for field in fields]
        items.append({
            "label": label, "ok": all(present) if mode == "all" else any(present),
            "required": required, "where": f"{COMPANY_SETUP} → „{where}“", "note": note,
        })
    return items


def _article_state(article: dict | None) -> str:
    if not article:
        return "missing"
    return "draft" if article["status"] == site_data.ARTICLE_DRAFT else "released"


def overview(data: dict, settings: dict) -> dict:
    """For the admin and the overview: each text, the imprint checklist, and
    the articles that may be picked (the website's category plus the picked ones)."""
    legal = data.get("legal") or {}
    texts = []
    for kind, (label, _title) in TEXTS.items():
        setting = site_data.LEGAL_ARTICLES[kind]
        chosen = settings.get(setting)
        article = legal.get(kind) if chosen else None
        texts.append({
            "kind": kind, "label": label, "setting": setting,
            "article": {key: article[key] for key in ("id", "question", "status")} if article else None,
            # Picked, but Dolibarr no longer has it (deleted or out of date).
            "gone": bool(chosen and not article and not data.get("errors", {}).get("legal")),
            "state": _article_state(article),
            "open_points": open_points(article["answer_html"]) if article else [],
        })
    checklist = imprint_checklist(data.get("company"))
    choices = {item["id"]: item for item in data.get("articles") or []}
    for article in legal.values():
        if article:
            choices.setdefault(article["id"], article)
    todo = [f"{item['label']} fehlt im Impressum" for item in checklist if item["required"] and not item["ok"]]
    for text in texts:
        if text["state"] == "missing":
            todo.append(f"{text['label']} fehlt")
        elif text["state"] == "draft":
            todo.append(f"{text['label']} ist noch ein Entwurf")
        if text["open_points"]:
            count = len(text["open_points"])
            todo.append(f"{text['label']}: {count} {'Stelle' if count == 1 else 'Stellen'} noch zu ergänzen")
    return {
        "texts": texts,
        "imprint": checklist,
        "choices": [{key: item[key] for key in ("id", "question", "status")} for item in choices.values()],
        "todo": todo,
        "error": (data.get("errors") or {}).get("legal"),
    }


async def create_draft(kind: str) -> dict:
    """Write the draft into Dolibarr's knowledge base and pick it."""
    if kind not in TEXTS:
        raise LookupError("Diesen Rechtstext gibt es nicht.")
    db = get_db()
    settings = await db.settings.find_one({"_id": "site"}) or {}
    setting = site_data.LEGAL_ARTICLES[kind]
    if settings.get(setting):
        raise ValueError("Für diesen Text ist schon ein Artikel gewählt. Er wird in Dolibarr bearbeitet.")
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        raise ValueError("Dolibarr ist nicht aktiviert oder der API-Key fehlt.")
    try:
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
            response = await client.post(
                f"{cfg['base']}/api/index.php/knowledgemanagement/knowledgerecords",
                headers=dolibarr._headers(cfg),
                # "status" is mandatory in the API; 0 = draft for the owner to fill in and release.
                json={"question": TEXTS[kind][1], "answer": draft_html(kind), "lang": "de_DE", "status": 0},
            )
            response.raise_for_status()
            article_id = int(dolibarr._remote_id(response.json()))
    except httpx.HTTPStatusError as exc:
        if exc.response.status_code == 403:
            raise PermissionError("Dem Website-Benutzer in Dolibarr vorübergehend das Recht "
                                  "„Wissensmanagement: Artikel anlegen/ändern“ geben.") from None
        raise ConnectionError(site_data._hint("articles", exc)) from None
    except httpx.HTTPError as exc:
        raise ConnectionError(site_data._hint("articles", exc)) from None
    await db.settings.update_one({"_id": "site"}, {"$set": {setting: article_id}}, upsert=True)
    await site_data.forget(db)
    logger.info("Legal draft %s created as knowledge article %s", kind, article_id)
    return {"kind": kind, "article_id": article_id}

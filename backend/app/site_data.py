"""Company data, opening hours, legal texts and FAQ from Dolibarr (#74, #81).

Everything the website says about the company is kept in Dolibarr: company
data and opening hours in its setup, legal texts and FAQ as articles of its
knowledge base. Three categories decide what an article is (#128): "FAQ"
(public questions), "Rechtstexte" (legal pages, picked by number) and
"Unterlagen" (internal papers, never on the website). The website settings
name each by its number; left empty, the category of that name is used.
Everything else in the knowledge base stays internal.

Since the Dolibarr module ittabelanderpdf 1.5.0 the legal texts are its own
documents ("Unterlagen", REST /ittabelanderpdf/unterlagen): a released public
text there wins over the picked article (#156). With an older module, or a
text the module does not have, the picked article stays the source.

The website keeps a copy for ten minutes and shows the last known copy while
Dolibarr is unreachable. The API user needs no admin rights: two constants
name it for company data and settings, the rest are plain read rights.
"""
import asyncio
import html
import logging
import re
from datetime import datetime, timedelta, timezone

import httpx
import nh3

from . import dolibarr
from .db import get_db, now_utc

logger = logging.getLogger("it-tabelander.site-data")

CACHE_TTL = timedelta(minutes=10)
CACHE_ID = "dolibarr_site_data"
WEEKDAYS = (
    ("MONDAY", "Montag"), ("TUESDAY", "Dienstag"), ("WEDNESDAY", "Mittwoch"),
    ("THURSDAY", "Donnerstag"), ("FRIDAY", "Freitag"), ("SATURDAY", "Samstag"), ("SUNDAY", "Sonntag"),
)
# Website setting per legal page: which knowledge article carries it.
LEGAL_ARTICLES = {
    "impressum": "dolibarr_imprint_article_id",
    "datenschutz": "dolibarr_privacy_article_id",
    "nutzungsbedingungen": "dolibarr_terms_article_id",
    "widerruf": "dolibarr_withdrawal_article_id",
    "agb-unternehmer": "dolibarr_b2b_terms_article_id",
}
LEGAL_TITLES = {"impressum": "Impressum", "datenschutz": "Datenschutzerklärung",
                "nutzungsbedingungen": "Nutzungsbedingungen", "widerruf": "Widerrufsbelehrung",
                "agb-unternehmer": "AGB für Unternehmer"}
# The three pages every visitor finds; the other two show once they are picked.
CORE_LEGAL_PAGES = ("impressum", "datenschutz", "nutzungsbedingungen")
# Role of a category: website setting with its number, name used when it is empty.
CATEGORY_SETTINGS = {
    "faq": ("dolibarr_content_category_id", "FAQ"),
    "legal": ("dolibarr_legal_category_id", "Rechtstexte"),
    "internal": ("dolibarr_internal_category_id", "Unterlagen"),
}
COMPANY_FIELDS = (
    "name", "managers", "address", "zip", "town", "country_code", "phone", "phone_mobile",
    "email", "url", "tva_intra", "idprof1", "idprof2", "idprof3", "idprof5", "socialobject",
    "capital", "socialnetworks",
)
ARTICLE_DRAFT, ARTICLE_VALIDATED = 0, 1

_refresh_lock = asyncio.Lock()


def _hint(part: str, exc: Exception) -> str:
    """What the owner has to change in Dolibarr, in plain words."""
    status = exc.response.status_code if isinstance(exc, httpx.HTTPStatusError) else None
    if status == 403 and part == "company":
        return ("Dolibarr gibt die Firmendaten nicht heraus: den Login des Website-Benutzers in der "
                "Konstante API_LOGINS_ALLOWED_FOR_GET_COMPANY eintragen.")
    if status == 403 and part == "opening_hours":
        return ("Dolibarr gibt die Öffnungszeiten nicht heraus: den Login des Website-Benutzers in der "
                "Konstante API_LOGINS_ALLOWED_FOR_CONST_READ eintragen.")
    if status == 403 and part == "articles":
        return "Dem Website-Benutzer das Recht „Wissensmanagement: Artikel lesen“ geben."
    if status == 403 and part == "categories":
        return "Dem Website-Benutzer das Recht „Kategorien: lesen“ geben."
    if status in (404, 501) and part in ("articles", "categories"):
        return "In Dolibarr die Module „Wissensmanagement-System“ und „Kategorien“ aktivieren."
    if isinstance(exc, (httpx.TimeoutException, httpx.RequestError)):
        return "Dolibarr ist gerade nicht erreichbar; die Website zeigt den letzten bekannten Stand."
    return f"Dolibarr meldet einen Fehler ({type(exc).__name__}{f', HTTP {status}' if status else ''})."


async def _get(client: httpx.AsyncClient, cfg: dict, path: str, params: dict | None = None):
    response = await client.get(f"{cfg['base']}/api/index.php/{path}", headers=dolibarr._headers(cfg),
                                params=params)
    return response


async def fetch_company(client, cfg) -> dict:
    response = await _get(client, cfg, "setup/company")
    response.raise_for_status()
    raw = response.json() or {}
    # A whitelist: the answer also carries private notes of the company.
    company = {key: raw.get(key) for key in COMPANY_FIELDS if raw.get(key) not in (None, "", [], {})}
    if raw.get("socialobject") is None and raw.get("object"):
        company["socialobject"] = raw["object"]
    return company


async def fetch_opening_hours(client, cfg) -> list[dict]:
    hours = []
    for key, day in WEEKDAYS:
        response = await _get(client, cfg, f"setup/conf/MAIN_INFO_OPENINGHOURS_{key}")
        if response.status_code == 400:
            continue  # not set for this day
        response.raise_for_status()
        value = str(response.json() or "").strip()
        if value:
            hours.append({"day": day, "hours": value})
    return hours


def clean_html(value: str) -> str:
    return nh3.clean(str(value or ""))


def plain_text(value: str) -> str:
    text = re.sub(r"<\s*br\s*/?>|</p>|</li>", "\n", str(value or ""), flags=re.IGNORECASE)
    text = html.unescape(re.sub(r"<[^>]+>", "", text))
    return "\n".join(line.strip() for line in text.splitlines() if line.strip())


def _article(raw: dict) -> dict | None:
    try:
        status = int(raw.get("status"))
    except (TypeError, ValueError):
        return None
    if status not in (ARTICLE_DRAFT, ARTICLE_VALIDATED):
        return None  # obsolete articles never show
    return {
        "id": int(raw.get("id")),
        "ref": raw.get("ref"),
        "question": str(raw.get("question") or "").strip(),
        "answer_html": clean_html(raw.get("answer")),
        "status": status,
        "updated_at": dolibarr._iso_time(raw.get("tms") or raw.get("date_modification") or raw.get("date_creation")),
    }


async def fetch_articles(client, cfg, category_id: int) -> list[dict]:
    response = await _get(client, cfg, "knowledgemanagement/knowledgerecords", {
        "category": int(category_id), "limit": 200, "sortfield": "t.rowid", "sortorder": "ASC",
    })
    if response.status_code == 404:
        detail = dolibarr._response_detail(response).lower()
        if "not found" in detail and "api" not in detail:
            return []  # an empty category
    response.raise_for_status()
    return [article for article in (_article(raw) for raw in response.json() or []) if article]


async def fetch_article(client, cfg, article_id: int) -> dict | None:
    """One picked article, whatever its category: picking it for a legal page
    is the decision to publish it (#68)."""
    response = await _get(client, cfg, f"knowledgemanagement/knowledgerecords/{int(article_id)}")
    if response.status_code == 404:
        return None
    response.raise_for_status()
    return _article(response.json() or {})


async def fetch_module_legal(client, cfg) -> dict | None:
    """The released public legal texts of the module, by legal page; None
    while the module has no such endpoint (older than 1.5.0) or the API user
    may not read it, so the knowledge base stays the source."""
    from . import legal_pdf

    response = await _get(client, cfg, "ittabelanderpdf/unterlagen")
    if response.status_code in (401, 403, 404, 501):
        return None
    response.raise_for_status()
    listed = {str(item.get("slug") or ""): item for item in response.json() or [] if isinstance(item, dict)}
    texts = {}
    for kind, slug in legal_pdf.SLUGS.items():
        if slug not in listed:
            continue
        answer = await _get(client, cfg, f"ittabelanderpdf/unterlagen/{slug}")
        if answer.status_code == 404:
            continue
        answer.raise_for_status()
        raw = answer.json() or {}
        body = clean_html(raw.get("html"))
        if not body.strip():
            continue
        texts[kind] = {
            "id": f"modul-{slug}", "slug": slug, "question": str(raw.get("title") or "").strip(),
            "answer_html": body, "status": ARTICLE_VALIDATED, "source": "module",
            "version": int(raw.get("version") or 0), "updated_at": str(raw.get("stand") or "") or None,
            "pdf": bool(raw.get("pdf")),
        }
    return texts


async def fetch_categories(client, cfg) -> list[dict]:
    response = await _get(client, cfg, "categories", {"type": "knowledgemanagement", "limit": 200})
    if response.status_code == 404:
        return []
    response.raise_for_status()
    return [{"id": int(item["id"]), "label": item.get("label") or f"#{item['id']}"}
            for item in response.json() or []]


async def _settings() -> dict:
    return await get_db().settings.find_one({"_id": "site"}) or {}


def _fresh(cache: dict) -> bool:
    fetched = cache.get("fetched_at")
    if not isinstance(fetched, datetime):
        return False
    if fetched.tzinfo is None:
        fetched = fetched.replace(tzinfo=timezone.utc)
    return now_utc() - fetched < CACHE_TTL


async def refresh(*, force: bool = False) -> dict:
    """The cached Dolibarr copy, fetched again when older than ten minutes.
    A part that fails keeps its last known value and notes why."""
    db = get_db()
    cache = await db.site_cache.find_one({"_id": CACHE_ID}) or {}
    if not force and _fresh(cache):
        return cache
    async with _refresh_lock:
        cache = await db.site_cache.find_one({"_id": CACHE_ID}) or {}
        if not force and _fresh(cache):
            return cache
        cfg = await dolibarr.get_config()
        settings = await _settings()
        if not cfg["enabled"]:
            return {**cache, "errors": {"dolibarr": "Dolibarr ist in den Website-Einstellungen nicht aktiviert."}}
        result = {"company": cache.get("company"), "opening_hours": cache.get("opening_hours"),
                  "articles": cache.get("articles"), "legal_articles": cache.get("legal_articles"),
                  "internal_ids": cache.get("internal_ids"), "legal": cache.get("legal") or {},
                  "module_legal": cache.get("module_legal") or {}, "errors": {}}
        parts = [("company", fetch_company), ("opening_hours", fetch_opening_hours)]
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
            for part, fetch in parts:
                try:
                    result[part] = await fetch(client, cfg)
                except Exception as exc:  # noqa: BLE001
                    result["errors"][part] = _hint(part, exc)
                    logger.warning("Dolibarr %s unavailable: %s", part, type(exc).__name__)
            ids = await _category_ids(client, cfg, settings, cache, result)
            for role, key in (("faq", "articles"), ("legal", "legal_articles"), ("internal", "internal_ids")):
                if not ids.get(role):
                    result[key] = []
                    continue
                try:
                    articles = await fetch_articles(client, cfg, ids[role])
                except Exception as exc:  # noqa: BLE001
                    result["errors"]["articles"] = _hint("articles", exc)
                    logger.warning("Dolibarr knowledge base unavailable: %s", type(exc).__name__)
                    continue
                result[key] = [item["id"] for item in articles] if role == "internal" else articles
            result["legal"] = await _fetch_legal(client, cfg, settings, result)
            try:
                result["module_legal"] = await fetch_module_legal(client, cfg) or {}
            except Exception as exc:  # noqa: BLE001
                result["errors"]["legal"] = _hint("articles", exc)
                logger.warning("Dolibarr module documents unavailable: %s", type(exc).__name__)
        result.update({"category_id": ids.get("faq"), "category_ids": ids, "fetched_at": now_utc()})
        await db.site_cache.replace_one({"_id": CACHE_ID}, {"_id": CACHE_ID, **result}, upsert=True)
        return result


def category_by_name(categories: list[dict], name: str) -> int | None:
    wanted = name.casefold()
    return next((item["id"] for item in categories if str(item["label"]).strip().casefold() == wanted), None)


async def _category_ids(client, cfg, settings: dict, cache: dict, result: dict) -> dict:
    """The number of each category: from the settings, else by its name.
    Never by an article's title: the category alone says what is public."""
    ids, categories = {}, None
    known = cache.get("category_ids") or {}
    for role, (key, name) in CATEGORY_SETTINGS.items():
        if settings.get(key):
            ids[role] = int(settings[key])
            continue
        if categories is None:
            try:
                categories = await fetch_categories(client, cfg)
            except Exception as exc:  # noqa: BLE001
                result["errors"]["categories"] = _hint("categories", exc)
                logger.warning("Dolibarr categories unavailable: %s", type(exc).__name__)
                categories = False
        # Dolibarr unreachable: the number found last time.
        ids[role] = known.get(role) if categories is False else category_by_name(categories, name)
    return ids


async def _fetch_legal(client, cfg, settings: dict, result: dict) -> dict:
    """The picked legal articles by their number; a failure keeps the last copy."""
    legal = {}
    listed = {item["id"]: item for item in [*(result.get("articles") or []), *(result.get("legal_articles") or [])]}
    for kind, key in LEGAL_ARTICLES.items():
        if not settings.get(key):
            continue
        article_id = int(settings[key])
        if article_id in listed:
            legal[kind] = listed[article_id]
            continue
        try:
            legal[kind] = await fetch_article(client, cfg, article_id)
        except Exception as exc:  # noqa: BLE001
            result["errors"]["legal"] = _hint("articles", exc)
            legal[kind] = (result.get("legal") or {}).get(kind)
            logger.warning("Dolibarr legal article unavailable: %s", type(exc).__name__)
    return legal


async def forget(db=None) -> None:
    """Drop the copy, e.g. after the owner picked other articles."""
    await (db if db is not None else get_db()).site_cache.delete_one({"_id": CACHE_ID})


def _legal_ids(settings: dict) -> set[int]:
    return {int(settings[key]) for key in LEGAL_ARTICLES.values() if settings.get(key)}


def faq_entries(data: dict, settings: dict) -> list[dict]:
    """Validated articles of the FAQ category that are no legal page and
    neither a legal text nor an internal paper by their category."""
    legal = (_legal_ids(settings) | {item["id"] for item in data.get("legal_articles") or []}
             | set(data.get("internal_ids") or []))
    return [
        {"id": f"dolibarr-{article['id']}", "question": article["question"],
         "answer": plain_text(article["answer_html"]), "answer_html": article["answer_html"],
         "category": "allgemein", "sort": index, "active": True, "source": "dolibarr"}
        for index, article in enumerate(
            item for item in data.get("articles") or []
            if item["status"] == ARTICLE_VALIDATED and item["id"] not in legal and item["question"])
    ]


async def dolibarr_faq() -> list[dict] | None:
    """The FAQ from Dolibarr, or None while the website still shows its own:
    before there is a FAQ category, and until the first article is released."""
    settings = await _settings()
    data = await refresh()
    if not data.get("category_id"):
        return None
    entries = faq_entries(data, settings)
    return entries or None


def _line(*parts) -> str:
    return " ".join(str(part).strip() for part in parts if part and str(part).strip())


def imprint_html(company: dict, extra_html: str = "") -> str:
    """The imprint from Dolibarr's company data; an article adds what the
    setup has no field for (authority, chamber, trade law)."""
    esc = html.escape
    rows = []
    country = {"AT": "Österreich", "DE": "Deutschland", "CH": "Schweiz"}.get(str(company.get("country_code") or "").upper(),
                                                                          company.get("country_code"))
    address = [company.get("name"), company.get("managers") and f"Inhaber/Geschäftsführung: {company['managers']}",
               company.get("address"), _line(company.get("zip"), company.get("town")), country]
    rows.append("<p>" + "<br>".join(esc(str(item)) for item in address if item) + "</p>")
    contact = [("Telefon", company.get("phone")), ("Mobil", company.get("phone_mobile")),
               ("E-Mail", company.get("email")), ("Web", company.get("url"))]
    if any(value for _label, value in contact):
        rows.append("<p>" + "<br>".join(f"{label}: {esc(str(value))}" for label, value in contact if value) + "</p>")
    facts = [("Unternehmensgegenstand", company.get("socialobject")), ("UID-Nummer", company.get("tva_intra")),
             # In Austria Dolibarr's Prof. Id 2 is the court of jurisdiction; it is
             # the register court only for a company in the register (#138).
             ("Firmenbuchnummer", company.get("idprof3")),
             ("Firmenbuchgericht", company.get("idprof2") if company.get("idprof3") else None),
             ("Steuernummer", company.get("idprof1"))]
    if any(value for _label, value in facts):
        rows.append("<p>" + "<br>".join(f"{label}: {esc(str(value))}" for label, value in facts if value) + "</p>")
    return "".join(rows) + (extra_html or "")


def legal_source(data: dict, settings: dict, kind: str) -> dict | None:
    """What carries a legal page: the module's released document, else the picked article."""
    module = (data.get("module_legal") or {}).get(kind)
    if module:
        return module
    return (data.get("legal") or {}).get(kind) if settings.get(LEGAL_ARTICLES[kind]) else None


def page_from(data: dict, settings: dict, kind: str) -> dict | None:
    """One legal page from the cached copy: the imprint from company data
    (plus an optional text), the others from the module's document or their
    picked article."""
    article = legal_source(data, settings, kind)
    if kind == "impressum":
        if not data.get("company"):
            return None
        body = imprint_html(data["company"], article["answer_html"] if article else "")
        draft = bool(article and article["status"] == ARTICLE_DRAFT)
    else:
        if not article:
            return None
        body, draft = article["answer_html"], article["status"] == ARTICLE_DRAFT
    return {"kind": kind, "title": LEGAL_TITLES[kind], "html": body, "draft": draft,
            "updated_at": (article or {}).get("updated_at"), "source": (article or {}).get("source", "article")}


async def legal_data() -> tuple[dict, dict]:
    settings = await _settings()
    data = await refresh()
    if "legal" not in data:
        data = await refresh(force=True)  # a copy from before the legal articles were read by number
    return data, settings


def available_pages(data: dict, settings: dict) -> list[str]:
    """The legal pages for the navigation: the three main ones always, the
    others once an article is picked for them."""
    return [kind for kind in LEGAL_ARTICLES if kind in CORE_LEGAL_PAGES or page_from(data, settings, kind)]


async def legal_page(kind: str) -> dict | None:
    """One legal page for the website, with the other pages and whether
    there is a PDF to download. A draft is marked and never a PDF."""
    from . import legal_pdf

    if kind not in LEGAL_ARTICLES:
        return None
    data, settings = await legal_data()
    page = page_from(data, settings, kind)
    if not page:
        return None
    return {
        **page,
        "pages": [{"kind": item, "title": LEGAL_TITLES[item]} for item in available_pages(data, settings)],
        "pdf": not page["draft"] and await legal_pdf.available(kind),
        "fetched_at": data.get("fetched_at").isoformat() if isinstance(data.get("fetched_at"), datetime) else None,
    }


async def site_info() -> dict:
    data = await refresh()
    settings = await _settings()
    return {
        "company": data.get("company"),
        "opening_hours": data.get("opening_hours") or [],
        "faq": faq_entries(data, settings),
        "fetched_at": data.get("fetched_at").isoformat() if isinstance(data.get("fetched_at"), datetime) else None,
        # True while some part shows an older copy; the page itself stays usable.
        "stale": bool(data.get("errors")),
    }


async def admin_overview() -> dict:
    """For the owner: what comes from Dolibarr, and what to fix where."""
    from . import legal_pdf, legal_texts

    data = await refresh(force=True)
    legal_pdf.forget()  # "Neu laden" also looks for new PDFs
    settings = await _settings()
    cfg = await dolibarr.get_config()
    categories, category_error = [], None
    if cfg["enabled"]:
        try:
            async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
                categories = await fetch_categories(client, cfg)
        except Exception as exc:  # noqa: BLE001
            category_error = _hint("categories", exc)
    errors = dict(data.get("errors") or {})
    if category_error:
        errors["categories"] = category_error
    return {
        "company": data.get("company"),
        "opening_hours": data.get("opening_hours") or [],
        "categories": categories,
        # The number of each category actually used, set or found by its name.
        "category_ids": {role: (data.get("category_ids") or {}).get(role) for role in CATEGORY_SETTINGS},
        "articles": [{key: item[key] for key in ("id", "ref", "question", "status")}
                     for item in data.get("articles") or []],
        "faq_count": len(faq_entries(data, settings)),
        "legal": legal_texts.overview(data, settings),
        "pdf": await legal_pdf.overview() if cfg["enabled"] else None,
        "errors": errors,
        "fetched_at": data.get("fetched_at").isoformat() if isinstance(data.get("fetched_at"), datetime) else None,
    }


# ------------------------------------------------------------ FAQ migration

async def copy_website_faq() -> dict:
    """Copy the website's own FAQ into Dolibarr's knowledge base as drafts.
    The owner reviews them there, tags them "FAQ" and releases them; the
    category API cannot tag knowledge articles."""
    db = get_db()
    cfg = await dolibarr.get_config()
    if not cfg["enabled"]:
        raise RuntimeError("Dolibarr ist nicht aktiviert oder der API-Key fehlt.")
    faqs = await db.faqs.find({"active": True, "dolibarr_article_id": {"$exists": False}}).sort("sort", 1).to_list(200)
    copied, failed = 0, []
    async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
        for faq in faqs:
            answer = "".join(f"<p>{html.escape(part)}</p>" for part in str(faq.get("answer") or "").split("\n\n") if part.strip())
            try:
                response = await client.post(
                    f"{cfg['base']}/api/index.php/knowledgemanagement/knowledgerecords",
                    headers=dolibarr._headers(cfg),
                    # "status" is mandatory in the API; 0 = draft for the owner to review.
                    json={"question": faq.get("question"), "answer": answer, "lang": "de_DE", "status": 0},
                )
                response.raise_for_status()
                article_id = dolibarr._remote_id(response.json())
            except Exception as exc:  # noqa: BLE001
                status = exc.response.status_code if isinstance(exc, httpx.HTTPStatusError) else None
                reason = ("Dem Website-Benutzer für die Kopie vorübergehend „Wissensmanagement: Artikel anlegen/ändern“ geben."
                          if status == 403 else _hint("articles", exc))
                failed.append({"question": faq.get("question"), "reason": reason})
                if status == 403:
                    break
                continue
            await db.faqs.update_one({"_id": faq["_id"]}, {"$set": {"dolibarr_article_id": int(article_id),
                                                                   "copied_at": now_utc()}})
            copied += 1
    return {"copied": copied, "failed": failed,
            "remaining": await db.faqs.count_documents({"active": True, "dolibarr_article_id": {"$exists": False}})}

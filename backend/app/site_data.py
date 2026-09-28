"""Company data, opening hours, legal texts and FAQ from Dolibarr (#74, #81).

Everything the website says about the company is kept in Dolibarr: company
data and opening hours in its setup, legal texts and FAQ as articles of its
knowledge base. Only articles tagged with the category chosen in the website
settings are public; everything else in the knowledge base stays internal.

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
}
LEGAL_TITLES = {"impressum": "Impressum", "datenschutz": "Datenschutzerklärung",
                "nutzungsbedingungen": "Nutzungsbedingungen"}
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
        category_id = settings.get("dolibarr_content_category_id")
        result = {"company": cache.get("company"), "opening_hours": cache.get("opening_hours"),
                  "articles": cache.get("articles"), "legal": cache.get("legal") or {}, "errors": {}}
        parts = [("company", fetch_company), ("opening_hours", fetch_opening_hours)]
        async with httpx.AsyncClient(timeout=cfg["timeout"]) as client:
            for part, fetch in parts:
                try:
                    result[part] = await fetch(client, cfg)
                except Exception as exc:  # noqa: BLE001
                    result["errors"][part] = _hint(part, exc)
                    logger.warning("Dolibarr %s unavailable: %s", part, type(exc).__name__)
            if category_id:
                try:
                    result["articles"] = await fetch_articles(client, cfg, int(category_id))
                except Exception as exc:  # noqa: BLE001
                    result["errors"]["articles"] = _hint("articles", exc)
                    logger.warning("Dolibarr knowledge base unavailable: %s", type(exc).__name__)
            else:
                result["articles"] = []
            result["legal"] = await _fetch_legal(client, cfg, settings, result)
        result.update({"category_id": category_id, "fetched_at": now_utc()})
        await db.site_cache.replace_one({"_id": CACHE_ID}, {"_id": CACHE_ID, **result}, upsert=True)
        return result


async def _fetch_legal(client, cfg, settings: dict, result: dict) -> dict:
    """The picked legal articles by their number; a failure keeps the last copy."""
    legal = {}
    listed = {item["id"]: item for item in result.get("articles") or []}
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
    """Validated articles of the website category that are no legal page."""
    legal = _legal_ids(settings)
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
    before a category is chosen, and until the first article is released."""
    settings = await _settings()
    if not settings.get("dolibarr_content_category_id"):
        return None
    entries = faq_entries(await refresh(), settings)
    return entries or None


def _line(*parts) -> str:
    return " ".join(str(part).strip() for part in parts if part and str(part).strip())


def imprint_html(company: dict, extra_html: str = "") -> str:
    """The imprint from Dolibarr's company data; an article adds what the
    setup has no field for (authority, chamber, trade law)."""
    esc = html.escape
    rows = []
    address = [company.get("name"), company.get("managers") and f"Inhaber/Geschäftsführung: {company['managers']}",
               company.get("address"), _line(company.get("zip"), company.get("town")), company.get("country_code")]
    rows.append("<p>" + "<br>".join(esc(str(item)) for item in address if item) + "</p>")
    contact = [("Telefon", company.get("phone")), ("Mobil", company.get("phone_mobile")),
               ("E-Mail", company.get("email")), ("Web", company.get("url"))]
    if any(value for _label, value in contact):
        rows.append("<p>" + "<br>".join(f"{label}: {esc(str(value))}" for label, value in contact if value) + "</p>")
    facts = [("Unternehmensgegenstand", company.get("socialobject")), ("UID-Nummer", company.get("tva_intra")),
             ("Firmenbuchnummer", company.get("idprof3")), ("Firmenbuchgericht", company.get("idprof2")),
             ("Steuernummer", company.get("idprof1"))]
    if any(value for _label, value in facts):
        rows.append("<p>" + "<br>".join(f"{label}: {esc(str(value))}" for label, value in facts if value) + "</p>")
    return "".join(rows) + (extra_html or "")


async def legal_page(kind: str) -> dict | None:
    """One legal page: the imprint from company data (plus an optional
    article), privacy and terms from their articles. A draft is marked."""
    if kind not in LEGAL_ARTICLES:
        return None
    settings = await _settings()
    data = await refresh()
    if "legal" not in data:
        data = await refresh(force=True)  # a copy from before the legal articles were read by number
    article = (data.get("legal") or {}).get(kind) if settings.get(LEGAL_ARTICLES[kind]) else None
    if kind == "impressum":
        if not data.get("company"):
            return None
        body = imprint_html(data["company"], article["answer_html"] if article else "")
        draft = bool(article and article["status"] == ARTICLE_DRAFT)
    else:
        if not article:
            return None
        body, draft = article["answer_html"], article["status"] == ARTICLE_DRAFT
    return {
        "kind": kind,
        "title": LEGAL_TITLES[kind],
        "html": body,
        "draft": draft,
        "updated_at": (article or {}).get("updated_at"),
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
    from . import legal_texts

    data = await refresh(force=True)
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
        "articles": [{key: item[key] for key in ("id", "ref", "question", "status")}
                     for item in data.get("articles") or []],
        "faq_count": len(faq_entries(data, settings)),
        "legal": legal_texts.overview(data, settings),
        "errors": errors,
        "fetched_at": data.get("fetched_at").isoformat() if isinstance(data.get("fetched_at"), datetime) else None,
    }


# ------------------------------------------------------------ FAQ migration

async def copy_website_faq() -> dict:
    """Copy the website's own FAQ into Dolibarr's knowledge base as drafts.
    The owner reviews them there, tags them "Website" and releases them; the
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

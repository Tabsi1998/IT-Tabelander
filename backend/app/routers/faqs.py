import logging

from fastapi import APIRouter

from .. import site_data
from ..db import get_db, serialize

router = APIRouter(prefix="/api", tags=["faqs"])
logger = logging.getLogger("it-tabelander.faqs")


@router.get("/faqs")
async def list_faqs(category: str | None = None):
    """The FAQ comes from Dolibarr's knowledge base (#81). The website's own
    list shows only until the first article is released there; it is no
    longer edited here."""
    try:
        from_dolibarr = await site_data.dolibarr_faq()
    except Exception as exc:  # noqa: BLE001
        logger.warning("Dolibarr FAQ unavailable: %s", type(exc).__name__)
        from_dolibarr = None
    if from_dolibarr is not None:
        return from_dolibarr
    query = {"active": True}
    if category:
        query["category"] = category
    docs = await get_db().faqs.find(query).sort("sort", 1).to_list(200)
    return [serialize(d) for d in docs]


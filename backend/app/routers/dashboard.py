from fastapi import APIRouter, Depends

from .. import dolibarr, legal_texts, mailer, site_data
from ..db import get_db, serialize
from ..security import require_admin

router = APIRouter(prefix="/api/admin", tags=["dashboard"])


@router.get("/dashboard")
async def dashboard(_: dict = Depends(require_admin)):
    """The admin's overview (#57): what waits, what is new, what is missing."""
    db = get_db()
    waiting = {"auto_handover": True, "dolibarr.synced": {"$ne": True}}
    recent = await db.repair_requests.find(
        {}, {"ref": 1, "request_type": 1, "created_at": 1, "dolibarr.synced": 1, "dolibarr.ticket_ref": 1},
    ).sort("created_at", -1).to_list(10)
    mail = await mailer.get_mail_config()
    # The cached copy; the overview never waits longer than one refresh. Also
    # without Dolibarr: then the imprint and the privacy policy are missing on
    # the live website, and that must show first (#68, #69).
    settings = await db.settings.find_one({"_id": "site"}) or {}
    legal = legal_texts.overview(await site_data.refresh(), settings)["todo"]
    return {
        "inquiries": {
            "waiting": await db.repair_requests.count_documents(waiting),
            "recent": [{
                "id": str(doc["_id"]),
                "ref": doc.get("ref"),
                "request_type": doc.get("request_type") or "repair",
                "request_type_label": dolibarr.REQUEST_TYPE_LABELS.get(doc.get("request_type") or "repair", "Anfrage"),
                "created_at": serialize({"at": doc.get("created_at")})["at"],
                "in_dolibarr": bool((doc.get("dolibarr") or {}).get("synced")),
                "ticket_ref": (doc.get("dolibarr") or {}).get("ticket_ref"),
            } for doc in recent],
        },
        "reviews": {
            "visible": await db.reviews.count_documents({"visible": True, "is_demo": {"$ne": True}}),
            "hidden": await db.reviews.count_documents({"visible": {"$ne": True}, "pending": {"$ne": True}}),
            "pending": await db.reviews.count_documents({"pending": True}),
        },
        "gallery": {
            "visible": await db.gallery.count_documents({"visible": {"$ne": False}}),
            "total": await db.gallery.count_documents({}),
        },
        "services": {"active": await db.services.count_documents({"active": True})},
        "dolibarr_enabled": await dolibarr.is_enabled(),
        "mail_configured": bool(mail["host"] and mail["sender"]),
        # What is still missing for imprint, privacy policy and terms (#68, #69, #75).
        "legal_todo": legal,
    }

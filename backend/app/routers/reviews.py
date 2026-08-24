import os

from fastapi import APIRouter, Depends, HTTPException

from ..db import get_db, now_utc, serialize, to_oid
from ..models import ReviewInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["reviews"])


@router.get("/reviews")
async def list_reviews():
    """Public: only visible reviews (admin-curated). No fabricated data."""
    docs = await get_db().reviews.find({"visible": True}).sort([("featured", -1), ("sort", 1)]).to_list(100)
    reviews = [serialize(d) for d in docs]
    visible = [r for r in reviews if not r.get("is_demo")]
    avg = round(sum(r["rating"] for r in visible) / len(visible), 1) if visible else None
    return {
        "reviews": reviews,
        "average": avg,
        "count": len(visible),
        "google_place_configured": bool(os.environ.get("GOOGLE_PLACES_API_KEY", "").strip()
                                         and os.environ.get("GOOGLE_PLACE_ID", "").strip()),
    }


@router.get("/admin/reviews")
async def admin_list_reviews(_: dict = Depends(require_admin)):
    docs = await get_db().reviews.find().sort([("featured", -1), ("sort", 1)]).to_list(200)
    return [serialize(d) for d in docs]


@router.post("/admin/reviews")
async def create_review(payload: ReviewInput, _: dict = Depends(require_admin)):
    data = payload.model_dump()
    data["created_at"] = now_utc()
    res = await get_db().reviews.insert_one(data)
    data["_id"] = res.inserted_id
    return serialize(data)


@router.put("/admin/reviews/{review_id}")
async def update_review(review_id: str, payload: ReviewInput, _: dict = Depends(require_admin)):
    db = get_db()
    oid = to_oid(review_id)
    res = await db.reviews.update_one({"_id": oid}, {"$set": payload.model_dump()})
    if res.matched_count == 0:
        raise HTTPException(status_code=404, detail="Bewertung nicht gefunden")
    return serialize(await db.reviews.find_one({"_id": oid}))


@router.delete("/admin/reviews/{review_id}")
async def delete_review(review_id: str, _: dict = Depends(require_admin)):
    res = await get_db().reviews.delete_one({"_id": to_oid(review_id)})
    if res.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Bewertung nicht gefunden")
    return {"ok": True}

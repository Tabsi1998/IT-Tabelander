from fastapi import APIRouter, Depends, HTTPException

from ..db import get_db, now_utc, serialize, to_oid
from ..models import ReviewInput
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["reviews"])

# What a visitor may see of a review; the rest (order, inquiry, flags) stays here.
PUBLIC_FIELDS = ("id", "author", "rating", "text", "source", "source_url", "review_date", "created_at")


@router.get("/reviews")
async def list_reviews():
    """Public: only visible, real reviews (admin-curated). A demo entry never
    leaves the server - invented reviews are not allowed on the site (#51)."""
    db = get_db()
    docs = await db.reviews.find({"visible": True, "is_demo": {"$ne": True}}).sort(
        [("featured", -1), ("sort", 1)]).to_list(100)
    visible = [{key: value for key, value in serialize(d).items() if key in PUBLIC_FIELDS} for d in docs]
    avg = round(sum(r["rating"] for r in visible) / len(visible), 1) if visible else None
    return {
        "reviews": visible,
        "average": avg,
        "count": len(visible),
    }


@router.get("/admin/reviews")
async def admin_list_reviews(_: dict = Depends(require_admin)):
    # Reviews from the link in the mail that wait for a release come first (#71).
    docs = await get_db().reviews.find().sort([("pending", -1), ("featured", -1), ("sort", 1)]).to_list(200)
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
    changes = payload.model_dump(exclude_unset=True)
    update = {"$set": changes}
    if changes.get("visible") is True:
        # Switching it on is the release of a review from the link (#71).
        update["$unset"] = {"pending": ""}
    res = await db.reviews.update_one({"_id": oid}, update)
    if res.matched_count == 0:
        raise HTTPException(status_code=404, detail="Bewertung nicht gefunden")
    return serialize(await db.reviews.find_one({"_id": oid}))


@router.delete("/admin/reviews/{review_id}")
async def delete_review(review_id: str, _: dict = Depends(require_admin)):
    res = await get_db().reviews.delete_one({"_id": to_oid(review_id)})
    if res.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Bewertung nicht gefunden")
    return {"ok": True}

from fastapi import APIRouter, Depends, HTTPException

from .. import dolibarr, review_invites
from ..models import ReviewInviteCheck, ReviewInviteSubmit
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["review-invites"])


@router.post("/review-invites/check")
async def check_invite(payload: ReviewInviteCheck):
    """Before the form shows: is the link from the mail still good (#71)?
    The token travels in the body, so it never lands in an access log."""
    invite = await review_invites.open_invite(payload.token)
    if not invite:
        raise HTTPException(status_code=404, detail=review_invites.LINK_GONE)
    return {
        "ref": invite.get("ref"),
        "request_type_label": dolibarr.REQUEST_TYPE_LABELS.get(invite.get("request_type") or "repair", "Anfrage"),
    }


@router.post("/review-invites/submit")
async def submit_review(payload: ReviewInviteSubmit):
    if payload.honeypot:
        raise HTTPException(status_code=400, detail="Ungültige Anfrage")
    if not payload.publish_ok:
        raise HTTPException(status_code=400,
                            detail="Bitte bestätige, dass die Bewertung auf der Website erscheinen darf.")
    stored = await review_invites.submit(payload.token, rating=payload.rating, text=payload.text,
                                         author=payload.author)
    if not stored:
        raise HTTPException(status_code=404, detail=review_invites.LINK_GONE)
    return {"ok": True}


@router.get("/admin/review-invites")
async def invites_overview(_: dict = Depends(require_admin)):
    return await review_invites.overview()


@router.post("/admin/review-invites/run")
async def run_invites(_: dict = Depends(require_admin)):
    """"Jetzt prüfen": closed tickets get their mail now, not within 30 minutes."""
    return await review_invites.run(force=True)

"""What the website says about the company, read from Dolibarr (#74, #81)."""
from fastapi import APIRouter, Depends, HTTPException

from .. import site_data
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["site"])


@router.get("/site-info")
async def site_info():
    """Company data, opening hours and FAQ for the website."""
    return await site_data.site_info()


@router.get("/legal/{kind}")
async def legal(kind: str):
    """Impressum, Datenschutz or Nutzungsbedingungen; a draft says so."""
    page = await site_data.legal_page(kind)
    if not page:
        raise HTTPException(status_code=404, detail="Dieser Text ist noch nicht in Dolibarr hinterlegt.")
    return page


@router.get("/admin/dolibarr/content")
async def content_overview(_: dict = Depends(require_admin)):
    """What comes from Dolibarr right now, freshly read, and what to fix."""
    return await site_data.admin_overview()

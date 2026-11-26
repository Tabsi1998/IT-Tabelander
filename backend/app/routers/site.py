"""What the website says about the company, read from Dolibarr (#74, #81)."""
from fastapi import APIRouter, Depends, HTTPException, Response

from .. import legal_pdf, legal_texts, site_data
from ..security import require_admin

router = APIRouter(prefix="/api", tags=["site"])


@router.get("/site-info")
async def site_info():
    """Company data, opening hours and FAQ for the website."""
    return await site_data.site_info()


@router.get("/legal/{kind}")
async def legal(kind: str):
    """One legal page (Impressum, Datenschutz, Nutzungsbedingungen, Widerruf,
    AGB für Unternehmer); a draft says so."""
    page = await site_data.legal_page(kind)
    if not page:
        raise HTTPException(status_code=404, detail="Dieser Text ist noch nicht in Dolibarr hinterlegt.")
    return page


@router.get("/legal/{kind}/pdf")
async def legal_pdf_download(kind: str):
    """The legal page as PDF from Dolibarr's documents (#128); only released
    pages, only the fixed file of each page."""
    if kind not in legal_pdf.SLUGS:
        raise HTTPException(status_code=404, detail="Dieses PDF gibt es nicht.")
    data, settings = await site_data.legal_data()
    page = site_data.page_from(data, settings, kind)
    content = await legal_pdf.fetch(kind) if page and not page["draft"] else None
    if content is None:
        raise HTTPException(status_code=404, detail="Für diesen Text gibt es noch kein PDF.")
    return Response(content=content, media_type="application/pdf", headers={
        "Content-Disposition": f'attachment; filename="{legal_pdf.file_name(kind)}"',
        "Cache-Control": "public, max-age=600",
        "X-Content-Type-Options": "nosniff",
    })


@router.get("/admin/dolibarr/content")
async def content_overview(_: dict = Depends(require_admin)):
    """What comes from Dolibarr right now, freshly read, and what to fix."""
    return await site_data.admin_overview()


@router.post("/admin/legal/{kind}/draft")
async def legal_draft(kind: str, _: dict = Depends(require_admin)):
    """"Entwurf anlegen": the plain-language draft as a knowledge article in
    Dolibarr, picked for the website; the owner completes and releases it there."""
    try:
        return await legal_texts.create_draft(kind)
    except LookupError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from None
    except PermissionError as exc:
        raise HTTPException(status_code=403, detail=str(exc)) from None
    except ValueError as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from None
    except ConnectionError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from None

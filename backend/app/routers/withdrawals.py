"""The online withdrawal (§ 13a FAGG, #142): public, without sign-in; the
request guard in server.py limits it per address."""
from fastapi import APIRouter, HTTPException

from .. import withdrawals
from ..models import WithdrawalInput

router = APIRouter(prefix="/api", tags=["withdrawals"])


@router.post("/withdrawals")
async def withdraw(payload: WithdrawalInput):
    """"Widerruf bestätigen": the declaration arrives, the confirmation goes out."""
    if payload.honeypot:
        raise HTTPException(status_code=400, detail="Ungültige Anfrage")
    return await withdrawals.submit(payload)

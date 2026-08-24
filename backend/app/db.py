import os
from datetime import datetime, timezone

from bson import ObjectId
from pymongo import AsyncMongoClient

_client: AsyncMongoClient | None = None
_db = None


def _timeout_ms(name: str, default: int = 3000) -> int:
    try:
        return max(500, int(os.environ.get(name, default)))
    except (TypeError, ValueError):
        return default


def get_client() -> AsyncMongoClient:
    global _client
    if _client is None:
        _client = AsyncMongoClient(
            os.environ["MONGO_URL"],
            serverSelectionTimeoutMS=_timeout_ms("MONGO_SERVER_SELECTION_TIMEOUT_MS"),
            connectTimeoutMS=_timeout_ms("MONGO_CONNECT_TIMEOUT_MS"),
            timeoutMS=_timeout_ms("MONGO_OPERATION_TIMEOUT_MS", 5000),
        )
    return _client


def get_db():
    global _db
    if _db is None:
        _db = get_client()[os.environ["DB_NAME"]]
    return _db


async def close_client() -> None:
    global _client, _db
    if _client is not None:
        await _client.close()
    _client = None
    _db = None


def now_utc() -> datetime:
    return datetime.now(timezone.utc)


def oid(value) -> ObjectId:
    """Coerce a value to ObjectId, raising ValueError on invalid input."""
    if isinstance(value, ObjectId):
        return value
    return ObjectId(str(value))


def is_valid_oid(value) -> bool:
    return ObjectId.is_valid(str(value))


def to_oid(value) -> ObjectId:
    """Return an ObjectId or raise HTTP 400 for malformed ids."""
    from fastapi import HTTPException
    if not ObjectId.is_valid(str(value)):
        raise HTTPException(status_code=400, detail="Ungültige ID")
    return ObjectId(str(value))


def serialize(doc):
    """Recursively convert BSON types (ObjectId, datetime) to JSON-safe values.
    Maps the Mongo `_id` field to `id`."""
    if doc is None:
        return None
    if isinstance(doc, list):
        return [serialize(d) for d in doc]
    if not isinstance(doc, dict):
        return doc
    out = {}
    for key, value in doc.items():
        k = "id" if key == "_id" else key
        if isinstance(value, ObjectId):
            out[k] = str(value)
        elif isinstance(value, datetime):
            out[k] = value.isoformat()
        elif isinstance(value, dict):
            out[k] = serialize(value)
        elif isinstance(value, list):
            out[k] = [serialize(v) if isinstance(v, (dict, list)) else (str(v) if isinstance(v, ObjectId) else v) for v in value]
        else:
            out[k] = value
    # never leak password hashes
    out.pop("password_hash", None)
    return out

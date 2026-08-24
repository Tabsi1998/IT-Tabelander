"""Small, atomic updates for admin-managed bootstrap credentials."""

import os
import re
from pathlib import Path

BACKEND_ENV_PATH = Path(__file__).resolve().parents[1] / ".env"


def _encode_env_value(value: str) -> str:
    if "\n" in value or "\r" in value:
        raise ValueError("Env-Werte dürfen keine Zeilenumbrüche enthalten")
    if re.fullmatch(r"[A-Za-z0-9_@./:+-]*", value):
        return value
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def update_backend_env(values: dict[str, str]) -> None:
    content = BACKEND_ENV_PATH.read_text(encoding="utf-8")
    for key, value in values.items():
        if not re.fullmatch(r"[A-Z][A-Z0-9_]*", key):
            raise ValueError(f"Ungültiger Env-Schlüssel: {key}")
        line = f"{key}={_encode_env_value(value)}"
        pattern = rf"(?m)^{re.escape(key)}=.*$"
        content = (re.sub(pattern, line, content)
                   if re.search(pattern, content)
                   else content.rstrip() + "\n" + line + "\n")

    temporary = BACKEND_ENV_PATH.with_name(f".{BACKEND_ENV_PATH.name}.tmp.{os.getpid()}")
    temporary.write_text(content, encoding="utf-8", newline="\n")
    os.chmod(temporary, 0o600)
    os.replace(temporary, BACKEND_ENV_PATH)
    os.chmod(BACKEND_ENV_PATH, 0o600)
    os.environ.update(values)

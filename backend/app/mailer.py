"""The website's own e-mail (#38).

Customers get their confirmations from Dolibarr. The website sends only what
Dolibarr cannot: warnings to the operator (#39), the one review request after
a closed ticket (#71) and, with the portal, sign-in links. The SMTP password is
written by a super admin and never read back.

A mail server in the own network often shows a self-signed certificate. A
super admin can trust exactly that certificate by its SHA-256 fingerprint
(#109): then TLS runs without the CA and host name check, but the connection
is used only if the server's certificate has that fingerprint - checked right
after the TLS handshake, before the login. Without a pinned fingerprint the
check stays as strict as before.
"""
import asyncio
import hashlib
import hmac
import logging
import re
import smtplib
import ssl
from datetime import datetime, timezone
from email.message import EmailMessage
from email.utils import formataddr, make_msgid

from .db import get_db, now_utc

logger = logging.getLogger("it-tabelander.mail")

SECURITY_MODES = ("starttls", "ssl", "none")
DEFAULT_PORTS = {"starttls": 587, "ssl": 465, "none": 25}
TIMEOUT_SECONDS = 20


class MailNotConfigured(Exception):
    """No SMTP server or sender is set up yet."""


class MailError(Exception):
    """Sending failed; the message is safe to show an admin."""


class MailCertificateUntrusted(MailError):
    """The server's certificate is not trusted (self-signed, unknown CA, other name)."""

    def __init__(self, message: str, certificate: dict):
        super().__init__(message)
        self.certificate = certificate


class MailCertificateChanged(MailCertificateUntrusted):
    """A fingerprint is pinned, and the server shows another certificate: nothing is sent."""


UNTRUSTED = ("Der Mailserver zeigt ein Zertifikat, dem die Website nicht vertraut (zum Beispiel selbst signiert). "
             "Vergleiche den Fingerabdruck mit dem auf dem Mailserver; stimmt er, kannst du diesem Zertifikat vertrauen.")
CHANGED = ("Das Zertifikat des Mailservers hat sich geändert. Zur Sicherheit wurde nichts gesendet und kein Passwort "
           "übertragen. Prüfe den neuen Fingerabdruck; stimmt er, vertraue dem neuen Zertifikat.")
FINGERPRINT = re.compile(r"^[0-9a-f]{64}$")


def clean_fingerprint(value) -> str:
    """"AB:CD:…" or "abcd…" -> 64 lower-case hex digits, or ValueError."""
    text = re.sub(r"[\s:]", "", str(value or "")).lower()
    if not FINGERPRINT.fullmatch(text):
        raise ValueError("Das ist kein SHA-256-Fingerabdruck (64 Hex-Zeichen).")
    return text


def fingerprint_text(value: str) -> str:
    """The fingerprint as openssl prints it: "AB:CD:…"."""
    return ":".join(value[index:index + 2] for index in range(0, len(value), 2)).upper()


# ------------------------------------------------- reading a certificate
# Only what an admin needs to compare: names and validity. A few lines of
# DER instead of a dependency; anything unexpected leaves the field empty.

_NAME_PARTS = {b"\x55\x04\x03": "CN", b"\x55\x04\x0a": "O"}


def _tlv(data: bytes, pos: int) -> tuple[int, int, int]:
    tag, length = data[pos], data[pos + 1]
    pos += 2
    if length & 0x80:
        count = length & 0x7F
        length = int.from_bytes(data[pos:pos + count], "big")
        pos += count
    return tag, pos, pos + length


def _items(data: bytes, start: int, end: int):
    while start < end:
        tag, inner, after = _tlv(data, start)
        yield tag, inner, after
        start = after


def _text(tag: int, raw: bytes) -> str:
    return raw.decode("utf-16-be" if tag == 0x1E else "utf-8", errors="replace")


def _name(data: bytes, start: int, end: int) -> str:
    found = {}
    for _set_tag, set_start, set_end in _items(data, start, end):
        for _seq_tag, seq_start, seq_end in _items(data, set_start, set_end):
            parts = list(_items(data, seq_start, seq_end))
            if len(parts) == 2 and parts[0][0] == 0x06:
                key = _NAME_PARTS.get(data[parts[0][1]:parts[0][2]])
                if key and key not in found:
                    found[key] = _text(parts[1][0], data[parts[1][1]:parts[1][2]])
    return found.get("CN") or found.get("O") or ""


def _time(tag: int, raw: bytes) -> str | None:
    text = raw.decode("ascii", errors="replace").rstrip("Z")
    if tag == 0x17:  # UTCTime: two-digit year, 1950-2049
        text = ("19" if int(text[:2]) >= 50 else "20") + text
    return datetime.strptime(text[:14], "%Y%m%d%H%M%S").replace(tzinfo=timezone.utc).isoformat()


def certificate_info(der: bytes) -> dict:
    """Fingerprint, subject, issuer and validity of a DER certificate."""
    sha256 = hashlib.sha256(der).hexdigest()
    info = {"sha256": sha256, "sha256_text": fingerprint_text(sha256), "subject": "", "issuer": "",
            "not_before": None, "not_after": None, "self_signed": False}
    try:
        _tag, cert_start, cert_end = _tlv(der, 0)
        _tag, tbs_start, tbs_end = next(_items(der, cert_start, cert_end))
        fields = list(_items(der, tbs_start, tbs_end))
        if fields and fields[0][0] == 0xA0:  # explicit version
            fields = fields[1:]
        issuer, validity, subject = fields[2], fields[3], fields[4]
        info["issuer"] = _name(der, issuer[1], issuer[2])
        info["subject"] = _name(der, subject[1], subject[2])
        times = list(_items(der, validity[1], validity[2]))
        info["not_before"] = _time(times[0][0], der[times[0][1]:times[0][2]])
        info["not_after"] = _time(times[1][0], der[times[1][1]:times[1][2]])
        info["self_signed"] = der[issuer[1]:issuer[2]] == der[subject[1]:subject[2]]
    except (IndexError, ValueError, StopIteration):
        pass  # the fingerprint alone still identifies the certificate
    return info


async def get_mail_config() -> dict:
    s = await get_db().settings.find_one({"_id": "site"}) or {}
    security = str(s.get("smtp_security") or "starttls")
    if security not in SECURITY_MODES:
        security = "starttls"
    try:
        port = int(s.get("smtp_port") or DEFAULT_PORTS[security])
    except (TypeError, ValueError):
        port = DEFAULT_PORTS[security]
    return {
        "host": str(s.get("smtp_host") or "").strip(),
        "port": port,
        "security": security,
        "username": str(s.get("smtp_username") or "").strip(),
        "password": str(s.get("smtp_password") or ""),
        "sender": str(s.get("smtp_from") or "").strip(),
        "sender_name": str(s.get("smtp_from_name") or s.get("company_name") or "").strip(),
        "pinned_sha256": str(s.get("smtp_pinned_sha256") or "").strip().lower(),
    }


def _context(pinned: bool) -> ssl.SSLContext:
    """Strict by default. With a pinned fingerprint the certificate is checked
    by _check_pin instead of a CA - nothing else is relaxed."""
    context = ssl.create_default_context()
    if pinned:
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
    return context


def _connect(cfg: dict, context: ssl.SSLContext):
    """The SMTP session with TLS up, before anything is sent or logged in."""
    if cfg["security"] == "ssl":
        return smtplib.SMTP_SSL(cfg["host"], cfg["port"], timeout=TIMEOUT_SECONDS, context=context)
    server = smtplib.SMTP(cfg["host"], cfg["port"], timeout=TIMEOUT_SECONDS)
    if cfg["security"] == "starttls":
        try:
            server.starttls(context=context)
        except BaseException:
            server.close()
            raise
    return server


def _peer_certificate(server) -> bytes:
    der = server.sock.getpeercert(binary_form=True) if isinstance(server.sock, ssl.SSLSocket) else None
    if not der:
        raise ssl.SSLError("the mail server sent no certificate")
    return der


def _check_pin(server, cfg: dict) -> None:
    """Right after the handshake: the certificate must be the trusted one."""
    der = _peer_certificate(server)
    if not hmac.compare_digest(hashlib.sha256(der).hexdigest(), cfg["pinned_sha256"]):
        raise MailCertificateChanged(CHANGED, certificate_info(der))


def _send_blocking(cfg: dict, message: EmailMessage) -> None:
    pinned = bool(cfg["pinned_sha256"]) and cfg["security"] != "none"
    with _connect(cfg, _context(pinned)) as server:
        if pinned:
            _check_pin(server, cfg)  # before the login: a wrong server never sees the password
        if cfg["username"]:
            server.login(cfg["username"], cfg["password"])
        server.send_message(message)


def _probe_blocking(cfg: dict) -> dict:
    """The certificate the server shows, without trusting, logging in or sending."""
    with _connect(cfg, _context(True)) as server:
        return certificate_info(_peer_certificate(server))


async def server_certificate(cfg: dict | None = None) -> dict:
    """What the configured mail server shows now; MailError if that cannot be read."""
    cfg = cfg or await get_mail_config()
    if not cfg["host"] or cfg["security"] == "none":
        raise MailError("Ohne Verschlüsselung gibt es kein Zertifikat zu prüfen.")
    try:
        return await asyncio.to_thread(_probe_blocking, cfg)
    except Exception as exc:  # noqa: BLE001
        raise MailError(_explain(exc, cfg)) from None


def _explain(exc: Exception, cfg: dict) -> str:
    if isinstance(exc, smtplib.SMTPAuthenticationError):
        return "Der Mailserver lehnt Benutzername oder Passwort ab."
    if isinstance(exc, smtplib.SMTPRecipientsRefused):
        return "Der Mailserver nimmt die Empfänger-Adresse nicht an."
    if isinstance(exc, smtplib.SMTPSenderRefused):
        return "Der Mailserver nimmt die Absender-Adresse nicht an."
    if isinstance(exc, smtplib.SMTPNotSupportedError):
        return "Der Mailserver kann die gewählte Verschlüsselung nicht (STARTTLS/SSL prüfen)."
    if isinstance(exc, ssl.SSLError):
        return "Verschlüsselte Verbindung zum Mailserver fehlgeschlagen (Port und Verschlüsselung prüfen)."
    if isinstance(exc, (TimeoutError, OSError)):
        return f"Mailserver {cfg['host']}:{cfg['port']} ist nicht erreichbar."
    return f"Senden fehlgeschlagen ({type(exc).__name__})."


async def send_mail(to, subject: str, text: str) -> None:
    """Send one plain-text mail; raise MailNotConfigured or MailError."""
    cfg = await get_mail_config()
    if not cfg["host"] or not cfg["sender"]:
        raise MailNotConfigured("Der E-Mail-Versand ist noch nicht eingerichtet (Mailserver und Absender fehlen).")
    recipients = [to] if isinstance(to, str) else list(to)
    message = EmailMessage()
    message["Subject"] = subject
    message["From"] = formataddr((cfg["sender_name"], cfg["sender"])) if cfg["sender_name"] else cfg["sender"]
    message["To"] = ", ".join(recipients)
    message["Message-ID"] = make_msgid(domain=cfg["sender"].rpartition("@")[2] or None)
    message.set_content(text)
    try:
        await asyncio.to_thread(_send_blocking, cfg, message)
    except MailCertificateChanged as exc:
        logger.warning("Mail not sent: the mail server's certificate is not the trusted one (%s)",
                       exc.certificate["sha256_text"])
        await _remember_problem(exc.certificate)
        raise
    except ssl.SSLCertVerificationError:
        # Not trusted and nothing pinned: show the admin what the server shows.
        logger.warning("Mail not sent: the mail server's certificate is not trusted")
        try:
            certificate = await asyncio.to_thread(_probe_blocking, cfg)
        except Exception:  # noqa: BLE001 - then the plain message has to do
            raise MailError(_explain(ssl.SSLError(), cfg)) from None
        raise MailCertificateUntrusted(UNTRUSTED, certificate) from None
    except Exception as exc:  # noqa: BLE001
        explained = _explain(exc, cfg)
        logger.warning("Mail to %s recipient(s) failed: %s", len(recipients), explained)
        raise MailError(explained) from None
    if cfg["pinned_sha256"]:
        await get_db().settings.update_one({"_id": "site", "smtp_certificate_problem": {"$exists": True}},
                                           {"$unset": {"smtp_certificate_problem": ""}})


async def _remember_problem(certificate: dict) -> None:
    """For the warning in the admin: which certificate the server showed instead."""
    await get_db().settings.update_one({"_id": "site"}, {"$set": {"smtp_certificate_problem": {
        "at": now_utc(), "sha256": certificate["sha256"], "sha256_text": certificate["sha256_text"],
        "subject": certificate["subject"], "issuer": certificate["issuer"]}}})

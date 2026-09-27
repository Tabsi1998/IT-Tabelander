"""The website's own e-mail (#38).

Customers get their confirmations from Dolibarr. The website sends only what
Dolibarr cannot: warnings to the operator (#39), the one review request after
a closed ticket (#71) and, with the portal, sign-in links. The SMTP password is
written by a super admin and never read back.
"""
import asyncio
import logging
import smtplib
import ssl
from email.message import EmailMessage
from email.utils import formataddr, make_msgid

from .db import get_db

logger = logging.getLogger("it-tabelander.mail")

SECURITY_MODES = ("starttls", "ssl", "none")
DEFAULT_PORTS = {"starttls": 587, "ssl": 465, "none": 25}
TIMEOUT_SECONDS = 20


class MailNotConfigured(Exception):
    """No SMTP server or sender is set up yet."""


class MailError(Exception):
    """Sending failed; the message is safe to show an admin."""


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
    }


def _send_blocking(cfg: dict, message: EmailMessage) -> None:
    if cfg["security"] == "ssl":
        server = smtplib.SMTP_SSL(cfg["host"], cfg["port"], timeout=TIMEOUT_SECONDS,
                                  context=ssl.create_default_context())
    else:
        server = smtplib.SMTP(cfg["host"], cfg["port"], timeout=TIMEOUT_SECONDS)
    with server:
        if cfg["security"] == "starttls":
            server.starttls(context=ssl.create_default_context())
        if cfg["username"]:
            server.login(cfg["username"], cfg["password"])
        server.send_message(message)


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
    except Exception as exc:  # noqa: BLE001
        explained = _explain(exc, cfg)
        logger.warning("Mail to %s recipient(s) failed: %s", len(recipients), explained)
        raise MailError(explained) from None

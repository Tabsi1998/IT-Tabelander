"""Trusting the mail server's own certificate by its fingerprint (#109).

A small SMTP server on 127.0.0.1 with a throwaway self-signed certificate
(made with openssl for this run) speaks STARTTLS or implicit TLS. It records
whether a login arrived: with a wrong certificate the password must never
leave the website.
"""
import asyncio
import base64
import hashlib
import shutil
import socket
import ssl
import subprocess
import threading
from pathlib import Path

import pytest
from fastapi import HTTPException

from app import mailer
from app.routers import settings as settings_router


def _openssl() -> str:
    for candidate in (shutil.which("openssl"), r"C:\Program Files\Git\mingw64\bin\openssl.exe",
                      r"C:\Program Files\Git\usr\bin\openssl.exe"):
        if candidate and Path(candidate).is_file():
            return candidate
    pytest.skip("openssl is not installed")


def _certificate(folder: Path, name: str) -> tuple[Path, Path, bytes]:
    cert, key = folder / f"{name}.pem", folder / f"{name}.key"
    subprocess.run([_openssl(), "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-sha256", "-days", "2",
                    "-keyout", str(key), "-out", str(cert), "-subj", f"/O=Werkstatt/CN={name}"],
                   check=True, capture_output=True, timeout=120)
    der = ssl.PEM_cert_to_DER_cert(cert.read_text(encoding="ascii"))
    return cert, key, der


@pytest.fixture(scope="module")
def certificates(tmp_path_factory):
    folder = tmp_path_factory.mktemp("smtp-certificates")
    return {"mail": _certificate(folder, "mail.werkstatt.test"), "other": _certificate(folder, "fremd.test")}


class SmtpServer:
    """One line at a time, as much SMTP as smtplib needs."""

    def __init__(self, cert: Path, key: Path, implicit_tls: bool):
        self.context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        self.context.load_cert_chain(str(cert), str(key))
        self.implicit_tls = implicit_tls
        self.logins, self.messages = [], []
        self.listener = socket.create_server(("127.0.0.1", 0))
        self.port = self.listener.getsockname()[1]
        self.thread = threading.Thread(target=self._serve, daemon=True)
        self.thread.start()

    def _serve(self):
        while True:
            try:
                connection, _ = self.listener.accept()
            except OSError:
                return
            try:
                self._session(connection)
            except (OSError, ssl.SSLError):
                pass
            finally:
                connection.close()

    def _session(self, connection):
        if self.implicit_tls:
            connection = self.context.wrap_socket(connection, server_side=True)
        stream = connection.makefile("rb")

        def say(text):
            connection.sendall(text.encode() + b"\r\n")

        say("220 mail.werkstatt.test ESMTP")
        while True:
            line = stream.readline().decode(errors="replace").strip()
            if not line:
                return
            verb = line.split(" ", 1)[0].upper()
            if verb in ("EHLO", "HELO"):
                features = [] if self.implicit_tls or isinstance(connection, ssl.SSLSocket) else ["STARTTLS"]
                for feature in ("mail.werkstatt.test", *features, "AUTH PLAIN"):
                    say(f"250-{feature}")
                say("250 SIZE 1000000")
            elif verb == "STARTTLS":
                say("220 Ready to start TLS")
                connection = self.context.wrap_socket(connection, server_side=True)
                stream = connection.makefile("rb")
            elif verb == "AUTH":
                self.logins.append(base64.b64decode(line.split()[-1]).split(b"\0")[1:])
                say("235 Authentication successful")
            elif verb in ("MAIL", "RCPT", "RSET", "NOOP"):
                say("250 OK")
            elif verb == "DATA":
                say("354 End data with <CR><LF>.<CR><LF>")
                body = []
                while (chunk := stream.readline()) not in (b".\r\n", b""):
                    body.append(chunk)
                self.messages.append(b"".join(body))
                say("250 Queued")
            elif verb == "QUIT":
                say("221 Bye")
                return
            else:
                say("502 Not implemented")

    def close(self):
        self.listener.close()


class Settings:
    def __init__(self, doc):
        self.doc, self.updates = doc, []

    async def find_one(self, *_args, **_kwargs):
        return dict(self.doc)

    async def update_one(self, query, update, **_kwargs):
        self.updates.append((query, update))
        self.doc.update(update.get("$set", {}))
        for field in update.get("$unset", {}):
            self.doc.pop(field, None)


class Database:
    def __init__(self, doc):
        self.settings = Settings(doc)


def _use(monkeypatch, server: SmtpServer, *, security="starttls", pinned=""):
    db = Database({"smtp_host": "127.0.0.1", "smtp_port": server.port, "smtp_security": security,
                   "smtp_username": "office", "smtp_password": "Mail-Test-Kennwort",
                   "smtp_from": "office@werkstatt.test", "smtp_pinned_sha256": pinned})
    monkeypatch.setattr(mailer, "get_db", lambda: db)
    monkeypatch.setattr(settings_router, "get_db", lambda: db)
    return db


@pytest.fixture
def smtp(certificates):
    servers = []

    def start(name="mail", implicit_tls=False):
        cert, key, _der = certificates[name]
        servers.append(SmtpServer(cert, key, implicit_tls))
        return servers[-1]

    yield start
    for server in servers:
        server.close()


def _fingerprint(certificates, name="mail") -> str:
    return hashlib.sha256(certificates[name][2]).hexdigest()


def test_without_a_pin_a_self_signed_certificate_is_refused_and_shown(monkeypatch, smtp, certificates):
    server = smtp()
    _use(monkeypatch, server)
    with pytest.raises(mailer.MailCertificateUntrusted) as refused:
        asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))
    assert not isinstance(refused.value, mailer.MailCertificateChanged)
    shown = refused.value.certificate
    assert shown["sha256"] == _fingerprint(certificates)
    assert shown["sha256_text"].count(":") == 31 and shown["sha256_text"] == shown["sha256_text"].upper()
    assert shown["subject"] == "mail.werkstatt.test" and shown["issuer"] == "mail.werkstatt.test"
    assert shown["self_signed"] is True and shown["not_before"] < shown["not_after"]
    assert server.logins == [] and server.messages == []


@pytest.mark.parametrize("implicit_tls", [False, True])
def test_with_the_right_pin_the_mail_goes_out(monkeypatch, smtp, certificates, implicit_tls):
    server = smtp(implicit_tls=implicit_tls)
    db = _use(monkeypatch, server, security="ssl" if implicit_tls else "starttls", pinned=_fingerprint(certificates))
    db.settings.doc["smtp_certificate_problem"] = {"sha256": "alt"}
    asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))
    assert server.logins == [[b"office", b"Mail-Test-Kennwort"]]
    assert len(server.messages) == 1 and b"Subject: Hallo" in server.messages[0]
    assert "smtp_certificate_problem" not in db.settings.doc, "a working send clears the old warning"


@pytest.mark.parametrize("implicit_tls", [False, True])
def test_another_certificate_is_refused_before_the_login(monkeypatch, smtp, certificates, implicit_tls):
    server = smtp("other", implicit_tls=implicit_tls)
    db = _use(monkeypatch, server, security="ssl" if implicit_tls else "starttls", pinned=_fingerprint(certificates))
    with pytest.raises(mailer.MailCertificateChanged) as refused:
        asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))
    assert "hat sich geändert" in str(refused.value) and "Kennwort" not in str(refused.value)
    assert refused.value.certificate["sha256"] == _fingerprint(certificates, "other")
    assert server.logins == [] and server.messages == [], "the password never reaches the wrong server"
    problem = db.settings.doc["smtp_certificate_problem"]
    assert problem["sha256"] == _fingerprint(certificates, "other") and problem["subject"] == "fremd.test"


def test_a_fingerprint_is_read_with_or_without_colons():
    plain = "ab" * 32
    assert mailer.clean_fingerprint(" AB:" * 31 + "AB") == plain
    assert mailer.fingerprint_text(plain) == ":".join(["AB"] * 32)
    for bad in ("", "ab" * 31, "zz" * 32, "ab" * 33):
        with pytest.raises(ValueError):
            mailer.clean_fingerprint(bad)


def _admin(role="super_admin"):
    return {"email": "chef@example.com", "role": role}


def test_trusting_stores_only_the_certificate_the_server_shows_now(monkeypatch, smtp, certificates):
    server = smtp()
    db = _use(monkeypatch, server)
    wrong = settings_router.TrustInput(sha256=_fingerprint(certificates, "other"))
    with pytest.raises(HTTPException) as stale:
        asyncio.run(settings_router.trust_certificate(wrong, _admin()))
    assert stale.value.status_code == 409 and not db.settings.doc.get("smtp_pinned_sha256")

    right = settings_router.TrustInput(sha256=mailer.fingerprint_text(_fingerprint(certificates)))
    with pytest.raises(HTTPException) as forbidden:
        asyncio.run(settings_router.trust_certificate(right, _admin("admin")))
    assert forbidden.value.status_code == 403

    answer = asyncio.run(settings_router.trust_certificate(right, _admin()))
    assert db.settings.doc["smtp_pinned_sha256"] == _fingerprint(certificates)
    assert answer["smtp_pinned_sha256_text"] == mailer.fingerprint_text(_fingerprint(certificates))
    assert answer["smtp_pinned_subject"] == "mail.werkstatt.test" and "smtp_password" not in answer
    asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))
    assert len(server.messages) == 1

    with pytest.raises(HTTPException):
        asyncio.run(settings_router.forget_certificate(_admin("admin")))
    asyncio.run(settings_router.forget_certificate(_admin()))
    assert "smtp_pinned_sha256" not in db.settings.doc
    with pytest.raises(mailer.MailCertificateUntrusted):
        asyncio.run(mailer.send_mail("eva@example.com", "Hallo", "Text"))


def test_the_test_mail_hands_the_certificate_to_the_admin(monkeypatch, smtp, certificates):
    _use(monkeypatch, smtp())
    with pytest.raises(HTTPException) as answer:
        asyncio.run(settings_router.send_test_mail(settings_router.TestMailInput(to="eva@example.com"), _admin()))
    assert answer.value.status_code == 400
    detail = answer.value.detail
    assert detail["changed"] is False and detail["certificate"]["sha256"] == _fingerprint(certificates)
    assert "Fingerabdruck" in detail["message"]


def test_another_mail_server_drops_the_trusted_certificate(monkeypatch):
    db = Database({"smtp_host": "192.168.2.106", "smtp_port": 587, "smtp_security": "starttls",
                   "smtp_pinned_sha256": "ab" * 32, "smtp_pinned_subject": "mail"})
    monkeypatch.setattr(settings_router, "get_db", lambda: db)
    monkeypatch.setattr(settings_router.site_data, "forget", lambda _db: asyncio.sleep(0))
    same = settings_router.SettingsInput(smtp_host="192.168.2.106", smtp_port=587, smtp_from="office@example.at")
    asyncio.run(settings_router.update_settings(same, _admin()))
    assert db.settings.doc["smtp_pinned_sha256"] == "ab" * 32
    other = settings_router.SettingsInput(smtp_host="smtp.anbieter.at")
    asyncio.run(settings_router.update_settings(other, _admin()))
    assert "smtp_pinned_sha256" not in db.settings.doc and "smtp_pinned_subject" not in db.settings.doc

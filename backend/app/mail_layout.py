"""The look of the website's own customer mails (#114).

The same look as the mails of the Dolibarr module ittabelanderpdf from 1.9.0
(WerkbankMailTemplates in dolibarr/ittabelanderpdf/): no logo on top, the mail
starts with the text, 16 px Arial, an orange button, and ends with the
owner's signature (closing, name and company, a rule, the logo 180 px wide,
address, e-mail, web, phone), on a white sheet of 560 px at most. Light by default; one style block switches to the dark design
with prefers-color-scheme. Samsung Mail honours classes in that block but no
attribute selectors, so every element carries its class. No layout tables:
editors and clients take them apart.

A mail is a list of parts. The same parts give the HTML and the plain text
alternative, so both always say the same. Every text is escaped here; a part
never carries markup.

The customer area uses Du: the closing is always "Liebe Grüße".
"""
from dataclasses import dataclass, field
from html import escape

INK = "#1B3348"
BODY = "#3B4D63"
MUTED = "#4A5B6E"
BRAND = "#FE5A23"
LINE = "#D6DEE7"
FONT = "Arial, Helvetica, sans-serif"

# The logo comes from the module's public endpoint: a linked image, never an
# inline or cid: one (those show as broken attachments on mobile clients).
ERP_URL = "https://erp.tabelander.co.at"
LOGO = ERP_URL + "/custom/ittabelanderpdf/public/maillogo.php"
LOGO_DARK = LOGO + "?variant=dark"

CLOSING = "Liebe Grüße"
OWNER = "Fabian Tabelander"
COMPANY = "IT-Tabelander"
ADDRESS = "Spridrichstraße 17, 6410 Telfs"
EMAIL = "office@tabelander.co.at"
WEB = "it.tabelander.co.at"
WEB_URL = "https://it.tabelander.co.at"
PHONE = "+43 676 6039945"
PHONE_DIAL = "+436766039945"

TEXT = f"margin:0 0 16px 0;font-family:{FONT};font-size:16px;line-height:1.6;color:{BODY};"

# The dark design of the module (website dark colours, light logo), by class only.
DARK = {
    ".wb-bg": "background:#0D1823 !important;background-color:#0D1823 !important;",
    ".wb-tx": "color:#C5D0DC !important;",
    ".wb-ink": "color:#EEF2F6 !important;",
    ".wb-mu": "color:#A9B8CA !important;",
    ".wb-ln": "border-color:#2C4763 !important;",
    "hr": "border-color:#2C4763 !important;background-color:#2C4763 !important;",
    ".ittab-page": "background:#0D1823 !important;background-color:#0D1823 !important;",
}
LOGO_SWITCH = {
    ".ittab-logo-light": "display:none !important;",
    ".ittab-logo-dark": "display:block !important;max-height:none !important;overflow:visible !important;",
}


@dataclass(frozen=True)
class Part:
    kind: str  # "p", "heading", "button", "link", "items", "quote"
    text: str = ""
    url: str = ""
    items: tuple = field(default_factory=tuple)


def paragraph(text: str) -> Part:
    return Part("p", text)


def heading(text: str) -> Part:
    return Part("heading", text)


def button(label: str, url: str) -> Part:
    return Part("button", label, url)


def link(label: str, url: str) -> Part:
    """"Label: https://…" as one line."""
    return Part("link", label, url)


def items(*lines: str) -> Part:
    return Part("items", items=tuple(lines))


def quote(text: str) -> Part:
    """What somebody wrote, with its line breaks, set off by a line (#122)."""
    return Part("quote", text)


def _html_part(part: Part) -> str:
    text = escape(part.text)
    if part.kind == "p":
        return f'<p class="wb-tx" style="{TEXT}">{text}</p>'
    if part.kind == "heading":
        return (f'<p class="wb-ink" style="margin:24px 0 8px 0;font-family:{FONT};font-size:16px;line-height:1.4;'
                f'font-weight:bold;color:{INK};">{text}</p>')
    if part.kind == "link":
        return (f'<p class="wb-tx" style="{TEXT}">{text}: <a class="wb-ink" href="{escape(part.url)}" '
                f'style="color:{INK};word-break:break-all;">{escape(part.url)}</a></p>')
    if part.kind == "button":
        url = escape(part.url)
        return (f'<div style="margin:4px 0 8px 0;"><a href="{url}" style="display:block;padding:14px 20px;'
                f'border-radius:4px;background:{BRAND};color:#ffffff;font-family:{FONT};font-size:16px;'
                f'font-weight:bold;line-height:1.3;text-align:center;text-decoration:none;">{text}</a></div>'
                f'<p class="wb-mu" style="margin:0 0 24px 0;font-family:{FONT};font-size:13px;line-height:1.6;'
                f'color:{MUTED};word-break:break-all;">Oder diesen Link öffnen: <a class="wb-mu" href="{url}" '
                f'style="color:{MUTED};">{url}</a></p>')
    if part.kind == "items":
        rows = "".join(f'<li style="margin:0 0 6px 0;">{escape(line)}</li>' for line in part.items)
        return (f'<ul class="wb-tx" style="margin:0 0 16px 0;padding:0 0 0 20px;font-family:{FONT};font-size:16px;'
                f'line-height:1.6;color:{BODY};">{rows}</ul>')
    if part.kind == "quote":
        lines = "<br>".join(escape(line) for line in part.text.split("\n"))
        return (f'<p class="wb-tx wb-ln" style="{TEXT}padding:0 0 0 14px;border-left:3px solid {LINE};">{lines}</p>')
    raise ValueError(f"unknown mail part {part.kind!r}")


def _text_part(part: Part) -> str:
    if part.kind == "quote":
        return "\n".join(f"> {line}" for line in part.text.split("\n"))
    if part.kind == "button":
        return f"{part.text}:\n{part.url}"
    if part.kind == "link":
        return f"{part.text}: {part.url}"
    if part.kind == "items":
        return "\n".join(f"- {line}" for line in part.items)
    return part.text


def _logo() -> str:
    """Light logo, and the dark one hidden until the dark style shows it: two images in one paragraph."""
    image = ('<img class="{cls}" alt="IT-Tabelander" src="{src}" width="180" '
             'style="{hide}border:0;display:{display};height:auto;max-width:100%;width:180px" />')
    return (image.format(cls="ittab-logo-light", src=escape(LOGO), hide="", display="block")
            + image.format(cls="ittab-logo-dark", src=escape(LOGO_DARK),
                           hide="max-height:0;overflow:hidden;mso-hide:all;", display="none"))


def _signature(closing: bool) -> str:
    """As in the owner's own signature (Dolibarr sender profile)."""
    quiet = f"color:{MUTED};text-decoration:none;"
    # Every block carries its font, like the module's signature in hand-sent mails.
    font = f"font-family:{FONT};line-height:1.6;"
    words = f'<p class="wb-tx" style="{TEXT}margin:24px 0 16px 0;">{CLOSING}</p>' if closing else ""
    return (words
            + f'<div class="wb-tx" style="color:#3b4d63;{font}font-size:16px;">'
            + f'<p class="wb-tx" style="margin:0 0 10px 0;{font}font-size:16px;color:#3b4d63;">'
              f'<strong class="wb-ink" style="color:{INK};">{OWNER}</strong><br>{COMPANY}</p>'
            + f'<hr class="wb-ln" style="border:0;border-top:1px solid {LINE};" />'
            + f'<p style="margin:0 0 8px 0;">{_logo()}</p>'
            + f'<p class="wb-tx" style="margin:0;{font}font-size:14px;color:#3b4d63;">{escape(ADDRESS)}<br>'
              f'<a class="wb-mu" href="mailto:{EMAIL}" style="{quiet}">{EMAIL}</a> · '
              f'<a class="wb-mu" href="{WEB_URL}" style="{quiet}">{WEB}</a><br>'
              f'<a class="wb-mu" href="tel:{PHONE_DIAL}" style="{quiet}white-space:nowrap;">{PHONE}</a></p>'
            + "</div>")


def _style() -> str:
    dark = "".join(f"{selector}{{{rule}}}" for selector, rule in {**DARK, **LOGO_SWITCH}.items())
    # Outlook.com and the Outlook apps mark their forced dark mode with data-ogsc/data-ogsb.
    outlook = "".join(f"[data-ogsc] {selector},[data-ogsb] {selector}{{{rule}}}" for selector, rule in DARK.items())
    return ("<style>:root{color-scheme:light dark;supported-color-schemes:light dark;}"
            "body{margin:0;padding:0;-webkit-text-size-adjust:100%;}"
            "a[x-apple-data-detectors]{color:inherit !important;text-decoration:none !important;}"
            f"@media (prefers-color-scheme: dark){{{dark}}}{outlook}</style>")


def render(parts: list, *, site_base: str = "", closing: bool = True) -> tuple[str, str]:
    """Plain text and HTML of one mail, both ending with the signature.
    A notice to the owner himself goes without the closing words (#122).
    site_base is kept for the callers; the logo no longer links anywhere."""
    sheet = (f'<div class="wb-bg wb-tx" style="max-width:560px;margin:0 auto;padding:20px 20px 28px 20px;'
             f'background:#ffffff;font-family:{FONT};font-size:16px;line-height:1.6;color:{BODY};">'
             + "".join(_html_part(part) for part in parts)
             + _signature(closing) + "</div>")
    html = ('<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            '<meta name="viewport" content="width=device-width, initial-scale=1">'
            '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            + _style() + '</head><body class="ittab-page" style="margin:0;padding:0;background:#ffffff;">'
            + sheet + "</body></html>")
    text = "\n\n".join(_text_part(part) for part in parts)
    text += f"\n\n{CLOSING}\n" if closing else "\n\n"
    text += f"{OWNER}\n{COMPANY}\n--\n{ADDRESS}\n{EMAIL} · {WEB}\n{PHONE}\n"
    return text, html

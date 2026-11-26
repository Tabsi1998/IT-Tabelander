"""The look of the website's own customer mails (#114).

The same sheet as the mails of the Dolibarr module ittabelanderpdf
(WerkbankMailTemplates in dolibarr/ittabelanderpdf/): white, 560 px at most,
the logo, a short orange line, 16 px Arial, an orange button, the closing and
a calm footer. Light by default; one style block switches to the dark design
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
    ".ittab-page": "background:#0D1823 !important;background-color:#0D1823 !important;",
}
LOGO_SWITCH = {
    ".ittab-logo-light": "display:none !important;",
    ".ittab-logo-dark": "display:block !important;max-height:none !important;overflow:visible !important;"
                        "width:auto !important;",
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


def _logo(site_base: str) -> str:
    image = ('<img src="{src}" width="240" alt="IT-Tabelander" style="display:block;border:0;outline:none;'
             'text-decoration:none;width:240px;max-width:100%;height:auto;">')
    href = escape(site_base or WEB_URL)
    return (f'<div style="margin:0 0 18px 0;"><a href="{href}" style="text-decoration:none;">'
            f'<div class="ittab-logo-light">{image.format(src=escape(LOGO))}</div>'
            f'<div class="ittab-logo-dark" style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'
            f'{image.format(src=escape(LOGO_DARK))}</div></a></div>')


def _footer() -> str:
    quiet = f"color:{MUTED};text-decoration:none;"
    return (f'<div class="wb-mu wb-ln" style="margin:32px 0 0 0;padding:16px 0 0 0;border-top:1px solid {LINE};'
            f'font-family:{FONT};font-size:13px;line-height:1.7;color:{MUTED};">'
            f'<strong class="wb-ink" style="color:{INK};">{COMPANY}</strong> · {OWNER}<br>'
            f'{escape(ADDRESS)}<br>'
            f'<a class="wb-mu" href="mailto:{EMAIL}" style="{quiet}">{EMAIL}</a> · '
            f'<a class="wb-mu" href="{WEB_URL}" style="{quiet}">{WEB}</a><br>'
            f'<a class="wb-mu" href="tel:{PHONE_DIAL}" style="{quiet}white-space:nowrap;">{PHONE}</a></div>')


def _style() -> str:
    dark = "".join(f"{selector}{{{rule}}}" for selector, rule in {**DARK, **LOGO_SWITCH}.items())
    # Outlook.com and the Outlook apps mark their forced dark mode with data-ogsc/data-ogsb.
    outlook = "".join(f"[data-ogsc] {selector},[data-ogsb] {selector}{{{rule}}}" for selector, rule in DARK.items())
    return ("<style>:root{color-scheme:light dark;supported-color-schemes:light dark;}"
            "body{margin:0;padding:0;-webkit-text-size-adjust:100%;}"
            "a[x-apple-data-detectors]{color:inherit !important;text-decoration:none !important;}"
            f"@media (prefers-color-scheme: dark){{{dark}}}{outlook}</style>")


def render(parts: list, *, site_base: str = "", closing: bool = True) -> tuple[str, str]:
    """Plain text and HTML of one mail, both with the closing and the footer.
    A notice to the owner himself goes without the closing (#122)."""
    closing_html = (f'<p class="wb-tx" style="{TEXT}margin:24px 0 0 0;">{CLOSING}<br>'
                    f'<strong class="wb-ink" style="color:{INK};">{OWNER}</strong><br>{COMPANY}</p>') if closing else ""
    sheet = (f'<div class="wb-bg wb-tx" style="max-width:560px;margin:0 auto;padding:12px 20px 28px 20px;'
             f'background:#ffffff;font-family:{FONT};font-size:16px;line-height:1.6;color:{BODY};">'
             + _logo(site_base)
             + f'<div style="width:56px;height:0;max-height:0;overflow:hidden;border-top:2px solid {BRAND};'
               f'margin:0 0 28px 0;font-size:1px;line-height:1px;">&nbsp;</div>'
             + "".join(_html_part(part) for part in parts)
             + closing_html + _footer() + "</div>")
    html = ('<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            '<meta name="viewport" content="width=device-width, initial-scale=1">'
            '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            + _style() + '</head><body class="ittab-page" style="margin:0;padding:0;background:#ffffff;">'
            + sheet + "</body></html>")
    text = "\n\n".join(_text_part(part) for part in parts)
    if closing:
        text += f"\n\n{CLOSING}\n{OWNER}\n{COMPANY}"
    text += f"\n\n--\n{COMPANY} · {OWNER}\n{ADDRESS}\n{EMAIL} · {WEB}\n{PHONE}\n"
    return text, html

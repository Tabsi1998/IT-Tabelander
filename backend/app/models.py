import re
from datetime import datetime, timedelta, timezone
from typing import List, Literal, Optional
from urllib.parse import urlparse

from pydantic import (
    AwareDatetime, BaseModel, EmailStr, Field, ValidationInfo, field_validator, model_validator,
)

REQUEST_ID_PATTERN = r"^[A-Za-z0-9][A-Za-z0-9._:-]*$"


def check_callback(moment: Optional[datetime], phone: Optional[str]) -> None:
    """A callback wish needs a phone number and a time ahead, within 60 days (#73)."""
    if moment is None:
        return
    if not (phone or "").strip():
        raise ValueError("Für einen Rückruf fehlt die Telefonnummer")
    now = datetime.now(timezone.utc)
    if moment < now + timedelta(minutes=10):
        raise ValueError("Der Rückruf-Wunsch muss mindestens 10 Minuten in der Zukunft liegen")
    if moment > now + timedelta(days=60):
        raise ValueError("Der Rückruf-Wunsch darf höchstens 60 Tage in der Zukunft liegen")


# ---------- Auth ----------
class LoginInput(BaseModel):
    email: EmailStr
    password: str


class UserCreate(BaseModel):
    email: EmailStr
    password: str = Field(min_length=12, max_length=72)
    name: str
    role: str = "staff"


class AccountUpdate(BaseModel):
    current_password: str = Field(min_length=1, max_length=256)
    email: Optional[EmailStr] = None
    new_password: Optional[str] = Field(default=None, min_length=12, max_length=72)


# ---------- Services ----------
class ServiceInput(BaseModel):
    title: str
    heading: Optional[str] = ""
    short_description: str = ""
    long_description: str = ""
    image_url: Optional[str] = ""
    icon: Optional[str] = "wrench"
    slug: Optional[str] = ""
    bullets: List[str] = Field(default_factory=list)
    seo_title: Optional[str] = ""
    seo_description: Optional[str] = ""
    sort: int = 0
    active: bool = True


# ---------- Reviews ----------
def _https_url(value, what: str):
    if not value:
        return value
    cleaned = value.strip()
    parsed = urlparse(cleaned)
    if parsed.scheme != "https" or not parsed.netloc or any(character.isspace() for character in cleaned):
        raise ValueError(f"{what} muss eine vollständige https-Adresse sein")
    return cleaned


class ReviewInput(BaseModel):
    author: str
    rating: int = Field(ge=1, le=5)
    text: str
    source: str = "manuell"
    # Link to the original review, e.g. on Google (#51), and when it was written.
    source_url: Optional[str] = Field(default="", max_length=2048)
    review_date: Optional[str] = Field(default="", max_length=10, pattern=r"^(\d{4}-\d{2}-\d{2})?$")
    is_demo: bool = False
    featured: bool = False
    visible: bool = True
    sort: int = 0

    @field_validator("source_url")
    @classmethod
    def validate_source_url(cls, value):
        return _https_url(value, "Der Link zur Bewertung")


# ---------- Website inquiries ----------
class RepairContact(BaseModel):
    name: str = Field(min_length=2, max_length=128)
    email: EmailStr
    phone: Optional[str] = Field(default="", max_length=40)
    preferred_contact: Literal["email", "phone"] = "email"
    contact_type: Literal["private", "business"] = "private"
    company_name: Optional[str] = Field(default="", max_length=128)
    address: Optional[str] = Field(default="", max_length=255)
    postal_code: Optional[str] = Field(default="", max_length=25)
    city: Optional[str] = Field(default="", max_length=80)
    country_code: Optional[str] = Field(default="AT", max_length=2)
    website: Optional[str] = Field(default="", max_length=255)
    vat_id: Optional[str] = Field(default="", max_length=40)
    company_registration: Optional[str] = Field(default="", max_length=80)
    tax_number: Optional[str] = Field(default="", max_length=80)
    court: Optional[str] = Field(default="", max_length=120)
    eori: Optional[str] = Field(default="", max_length=40)

    @field_validator(
        "name", "phone", "company_name", "address", "postal_code", "city",
        "country_code", "website", "vat_id", "company_registration", "tax_number",
        "court", "eori",
        mode="before",
    )
    @classmethod
    def strip_contact_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @model_validator(mode="after")
    def require_preferred_phone(self):
        if self.preferred_contact == "phone" and not self.phone:
            raise ValueError("Telefonnummer fehlt für den bevorzugten Telefonkontakt")
        if self.contact_type == "business" and not self.company_name:
            raise ValueError("Firmenname fehlt für eine geschäftliche Anfrage")
        if self.country_code:
            if len(self.country_code) != 2:
                raise ValueError("Ländercode muss aus zwei Zeichen bestehen")
            self.country_code = self.country_code.upper()
        if self.website:
            parsed = urlparse(self.website)
            if parsed.scheme not in ("http", "https") or not parsed.netloc:
                raise ValueError("Website muss eine vollständige http(s)-URL sein")
        return self


class InquiryInput(BaseModel):
    request_type: Literal[
        "repair", "pc_build", "pc_upgrade", "controller_custom", "consulting", "other"
    ] = "repair"
    source: Optional[str] = Field(default="website", max_length=80)
    device_type: Optional[str] = Field(default="", max_length=120)
    device_source: Optional[Literal["new_controller", "send_in", "unsure", ""]] = ""
    manufacturer: Optional[str] = Field(default="", max_length=160)
    model: Optional[str] = Field(default="", max_length=160)
    issues: List[str] = Field(default_factory=list, max_length=20)
    desired_services: List[str] = Field(default_factory=list, max_length=20)
    budget: Optional[str] = Field(default="", max_length=120)
    timeframe: Optional[str] = Field(default="", max_length=120)
    description: str = Field(min_length=10, max_length=10000)
    attachment_ids: List[str] = Field(default_factory=list, max_length=5)
    contact: RepairContact
    consent: bool
    honeypot: Optional[str] = Field(default="", max_length=500)
    request_id: str = Field(
        min_length=8,
        max_length=128,
        pattern=REQUEST_ID_PATTERN,
    )
    # With the browser's UTC offset, so the workshop sees the customer's time.
    callback_at: Optional[AwareDatetime] = None

    @field_validator(
        "source", "device_type", "manufacturer", "model", "budget", "timeframe",
        "description", "honeypot", "request_id", mode="before",
    )
    @classmethod
    def strip_inquiry_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @field_validator("issues", "desired_services", "attachment_ids")
    @classmethod
    def clean_inquiry_lists(cls, values, info: ValidationInfo):
        cleaned = []
        max_item_length = 128 if info.field_name == "attachment_ids" else 300
        for value in values:
            text = str(value).strip()
            if len(text) > max_item_length:
                raise ValueError(
                    f"Ein Eintrag in {info.field_name} ist länger als {max_item_length} Zeichen"
                )
            if text and text not in cleaned:
                cleaned.append(text)
        return cleaned

    @model_validator(mode="after")
    def validate_inquiry_details(self):
        if self.request_type in ("repair", "pc_upgrade", "other") and not self.device_type:
            raise ValueError("Gerätetyp fehlt für diese Anfrageart")
        if self.request_type == "controller_custom":
            if not self.device_source or not self.manufacturer or not self.model:
                raise ValueError("Controller, Herkunft, Hersteller und Modell müssen angegeben werden")
        check_callback(self.callback_at, self.contact.phone)
        return self


class ContactInput(BaseModel):
    """The contact form: a message that becomes a Dolibarr ticket (#42)."""
    request_id: str = Field(min_length=8, max_length=128, pattern=REQUEST_ID_PATTERN)
    name: str = Field(min_length=2, max_length=128)
    email: EmailStr
    phone: Optional[str] = Field(default="", max_length=40)
    message: str = Field(min_length=10, max_length=5000)
    consent: bool
    honeypot: Optional[str] = Field(default="", max_length=500)
    callback_at: Optional[AwareDatetime] = None

    @field_validator("request_id", "name", "phone", "message", "honeypot", mode="before")
    @classmethod
    def strip_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @model_validator(mode="after")
    def validate_callback(self):
        check_callback(self.callback_at, self.phone)
        return self


# Backwards-compatible names for old clients using /repairs.
RepairInput = InquiryInput


class InquiryStatusQuery(BaseModel):
    """Status lookup on the website: reference number plus the e-mail (#43)."""
    ref: str = Field(min_length=12, max_length=12)
    email: EmailStr

    @field_validator("ref", mode="before")
    @classmethod
    def normalize_ref(cls, value):
        text = str(value or "").strip().upper()
        if not re.fullmatch(r"ANF-[A-Z0-9]{8}", text):
            raise ValueError("Die Anfrage-Nummer hat die Form ANF-XXXXXXXX")
        return text


class InquiryStatusUpdate(BaseModel):
    status: str


RepairStatusUpdate = InquiryStatusUpdate


# ---------- Settings ----------
class SettingsInput(BaseModel):
    company_name: Optional[str] = None
    tagline: Optional[str] = None
    email: Optional[str] = None
    phone: Optional[str] = None
    address: Optional[str] = None
    city: Optional[str] = None
    region: Optional[str] = None
    postal_code: Optional[str] = None
    country: Optional[str] = None
    service_area: Optional[str] = None
    opening_hours: Optional[List[dict]] = None
    social_links: Optional[dict] = None
    ga_measurement_id: Optional[str] = None
    canonical_base_url: Optional[str] = None
    dolibarr_enabled: Optional[bool] = None
    dolibarr_base_url: Optional[str] = Field(default=None, max_length=2048)
    dolibarr_api_key: Optional[str] = None
    clear_dolibarr_api_key: Optional[bool] = None
    dolibarr_timeout_seconds: Optional[float] = Field(default=None, ge=1, le=60)
    dolibarr_country_code: Optional[str] = Field(default=None, min_length=2, max_length=2)
    dolibarr_public_ticket_enabled: Optional[bool] = None
    dolibarr_ticket_categories: Optional[dict] = None
    # The website's own mail (#38). The password is write-only.
    smtp_host: Optional[str] = Field(default=None, max_length=255)
    smtp_port: Optional[int] = Field(default=None, ge=1, le=65535)
    smtp_security: Optional[Literal["starttls", "ssl", "none"]] = None
    smtp_username: Optional[str] = Field(default=None, max_length=255)
    smtp_password: Optional[str] = Field(default=None, max_length=512)
    clear_smtp_password: Optional[bool] = None
    smtp_from: Optional[str] = Field(default=None, max_length=255)
    smtp_from_name: Optional[str] = Field(default=None, max_length=120)
    # Who hears about inquiries stuck on their way to Dolibarr (#39).
    warning_email: Optional[str] = Field(default=None, max_length=255)
    # "Auf Google bewerten" on the website (#51).
    google_review_url: Optional[str] = Field(default=None, max_length=2048)
    # "Über mich" on the website (#58); empty = the built-in text.
    about_text: Optional[str] = Field(default=None, max_length=4000)
    about_qualifications: Optional[List[str]] = Field(default=None, max_length=12)
    about_photo_url: Optional[str] = Field(default=None, max_length=512)
    # Website content from Dolibarr's knowledge base (#74, #81): the public
    # category and which article is which legal page. 0 = none.
    dolibarr_content_category_id: Optional[int] = Field(default=None, ge=0)
    dolibarr_imprint_article_id: Optional[int] = Field(default=None, ge=0)
    dolibarr_privacy_article_id: Optional[int] = Field(default=None, ge=0)
    dolibarr_terms_article_id: Optional[int] = Field(default=None, ge=0)
    logo_light_url: Optional[str] = None
    logo_dark_url: Optional[str] = None
    seo_default_title: Optional[str] = None
    seo_default_description: Optional[str] = None
    impressum_html: Optional[str] = None
    datenschutz_html: Optional[str] = None
    legal_reviewed: Optional[bool] = None

    @field_validator("canonical_base_url")
    @classmethod
    def validate_canonical_base_url(cls, value):
        if not value:
            return value
        cleaned = value.strip().rstrip("/")
        parsed = urlparse(cleaned)
        if (
            parsed.scheme not in ("https", "http")
            or not parsed.netloc
            or "\n" in cleaned
            or "\r" in cleaned
        ):
            raise ValueError("Website-URL muss eine vollständige http(s)-URL sein")
        return cleaned

    @field_validator("dolibarr_base_url")
    @classmethod
    def validate_dolibarr_base_url(cls, value):
        if not value:
            return value
        cleaned = value.strip().rstrip("/")
        parsed = urlparse(cleaned)
        try:
            _port = parsed.port
        except ValueError as exc:
            raise ValueError("Dolibarr-URL enthält einen ungültigen Port") from exc
        if (
            parsed.scheme not in ("https", "http")
            or not parsed.hostname
            or parsed.username is not None
            or parsed.password is not None
            or parsed.query
            or parsed.fragment
            or any(character.isspace() for character in cleaned)
        ):
            raise ValueError(
                "Dolibarr-URL muss eine vollständige http(s)-URL ohne Zugangsdaten, Query oder Fragment sein"
            )
        # HTTP and private/LAN hosts are intentional: Dolibarr commonly runs on
        # the same internal network as this application.
        suffix = "/api/index.php"
        if cleaned.lower().endswith(suffix):
            cleaned = cleaned[:-len(suffix)].rstrip("/")
        return cleaned

    @field_validator("google_review_url")
    @classmethod
    def validate_google_review_url(cls, value):
        return _https_url(value, "Der Link zum Google-Profil")

    @field_validator("about_qualifications")
    @classmethod
    def validate_about_qualifications(cls, values):
        if values is None:
            return values
        cleaned = []
        for value in values:
            text = str(value or "").strip()
            if len(text) > 60:
                raise ValueError("Eine Qualifikation darf höchstens 60 Zeichen lang sein")
            if text and text not in cleaned:
                cleaned.append(text)
        return cleaned

    @field_validator("about_photo_url")
    @classmethod
    def validate_about_photo_url(cls, value):
        if value and not re.fullmatch(r"/api/media/[A-Za-z0-9._-]+", value.strip()):
            raise ValueError("Das Foto muss hier hochgeladen werden")
        return value.strip() if value else value

    @field_validator("smtp_host")
    @classmethod
    def validate_smtp_host(cls, value):
        if value is None:
            return value
        cleaned = value.strip()
        if cleaned and not re.fullmatch(r"[A-Za-z0-9.-]{1,253}|\[[0-9A-Fa-f:.]+\]", cleaned):
            raise ValueError("Mailserver: nur der Hostname, z. B. smtp.example.at")
        return cleaned

    @field_validator("smtp_from", "warning_email")
    @classmethod
    def validate_mail_address(cls, value):
        if value is None:
            return value
        cleaned = value.strip()
        if cleaned and not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", cleaned):
            raise ValueError("Bitte eine gültige E-Mail-Adresse eingeben")
        return cleaned

    @field_validator("smtp_username", "smtp_from_name", mode="before")
    @classmethod
    def strip_mail_text(cls, value):
        if isinstance(value, str) and ("\n" in value or "\r" in value):
            raise ValueError("Zeilenumbrüche sind hier nicht erlaubt")
        return value.strip() if isinstance(value, str) else value

    @field_validator("dolibarr_ticket_categories")
    @classmethod
    def validate_dolibarr_ticket_categories(cls, value):
        if value is None:
            return value
        allowed = {
            "repair", "pc_build", "pc_upgrade", "controller_custom", "consulting", "other",
            "contact",
        }
        cleaned = {}
        for key, code in value.items():
            if key not in allowed:
                raise ValueError("Unbekannte Anfrageart in der Dolibarr-Themengruppen-Zuordnung")
            code = str(code or "").strip().upper()
            if code and not re.fullmatch(r"[A-Z0-9_-]{1,32}", code):
                raise ValueError("Dolibarr-Themengruppen-Codes dürfen nur A-Z, 0-9, _ und - enthalten")
            if code:
                cleaned[key] = code
        return cleaned


# ---------- Admin: order and gallery (#58, #60) ----------
class OrderInput(BaseModel):
    """The new order of a list, as the ids from top to bottom."""
    ids: List[str] = Field(min_length=1, max_length=500)


GALLERY_CATEGORIES = ("pc_build", "repair", "upgrade", "console", "controller", "other")


class GalleryUpdate(BaseModel):
    caption: Optional[str] = Field(default=None, max_length=160)
    category: Optional[Literal["pc_build", "repair", "upgrade", "console", "controller", "other"]] = None
    visible: Optional[bool] = None

    @field_validator("caption", mode="before")
    @classmethod
    def strip_caption(cls, value):
        return value.strip() if isinstance(value, str) else value

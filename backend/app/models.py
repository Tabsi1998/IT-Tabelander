from typing import List, Literal, Optional
from urllib.parse import urlparse

from pydantic import (
    BaseModel, EmailStr, Field, ValidationInfo, field_validator, model_validator,
)


# ---------- Auth ----------
class LoginInput(BaseModel):
    email: EmailStr
    password: str


class UserCreate(BaseModel):
    email: EmailStr
    password: str = Field(min_length=12, max_length=72)
    name: str
    role: str = "staff"


class ForgotPasswordInput(BaseModel):
    email: EmailStr


class ResetPasswordInput(BaseModel):
    token: str
    password: str = Field(min_length=12, max_length=72)


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


# ---------- FAQ ----------
class FAQInput(BaseModel):
    question: str
    answer: str
    category: str = "allgemein"
    sort: int = 0
    active: bool = True


# ---------- Reviews ----------
class ReviewInput(BaseModel):
    author: str
    rating: int = Field(ge=1, le=5)
    text: str
    source: str = "manuell"
    is_demo: bool = False
    featured: bool = False
    visible: bool = True
    sort: int = 0


# ---------- Website inquiries ----------
class RepairContact(BaseModel):
    name: str = Field(min_length=2, max_length=128)
    email: EmailStr
    phone: Optional[str] = Field(default="", max_length=40)
    preferred_contact: Literal["email", "phone"] = "email"

    @field_validator("name", "phone", mode="before")
    @classmethod
    def strip_contact_text(cls, value):
        return value.strip() if isinstance(value, str) else value

    @model_validator(mode="after")
    def require_preferred_phone(self):
        if self.preferred_contact == "phone" and not self.phone:
            raise ValueError("Telefonnummer fehlt für den bevorzugten Telefonkontakt")
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
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]*$",
    )

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
        return self


# Backwards-compatible names for old clients using /repairs.
RepairInput = InquiryInput


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
    google_place_id: Optional[str] = None
    google_places_api_key: Optional[str] = None
    clear_google_places_api_key: Optional[bool] = None
    dolibarr_enabled: Optional[bool] = None
    dolibarr_base_url: Optional[str] = Field(default=None, max_length=2048)
    dolibarr_api_key: Optional[str] = None
    clear_dolibarr_api_key: Optional[bool] = None
    dolibarr_timeout_seconds: Optional[float] = Field(default=None, ge=1, le=60)
    dolibarr_country_code: Optional[str] = Field(default=None, min_length=2, max_length=2)
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

from typing import List, Optional
from pydantic import BaseModel, EmailStr, Field


# ---------- Auth ----------
class LoginInput(BaseModel):
    email: EmailStr
    password: str


class UserCreate(BaseModel):
    email: EmailStr
    password: str = Field(min_length=8)
    name: str
    role: str = "staff"


class ForgotPasswordInput(BaseModel):
    email: EmailStr


class ResetPasswordInput(BaseModel):
    token: str
    password: str = Field(min_length=8)


# ---------- Services ----------
class ServiceInput(BaseModel):
    title: str
    heading: Optional[str] = ""
    short_description: str = ""
    long_description: str = ""
    image_url: Optional[str] = ""
    icon: Optional[str] = "wrench"
    slug: Optional[str] = ""
    bullets: List[str] = []
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


# ---------- Repair request ----------
class RepairContact(BaseModel):
    name: str
    email: EmailStr
    phone: Optional[str] = ""
    preferred_contact: str = "email"


class RepairInput(BaseModel):
    device_type: str
    manufacturer: Optional[str] = ""
    model: Optional[str] = ""
    issues: List[str] = []
    description: str = ""
    attachment_ids: List[str] = []
    contact: RepairContact
    consent: bool
    honeypot: Optional[str] = ""


class RepairStatusUpdate(BaseModel):
    status: str


# ---------- Contact ----------
class ContactInput(BaseModel):
    name: str
    email: EmailStr
    phone: Optional[str] = ""
    subject: str = ""
    message: str
    consent: bool
    honeypot: Optional[str] = ""


# ---------- Configurator ----------
class ConfigCategoryInput(BaseModel):
    configurator: str  # 'ps5' | 'pc'
    key: str
    name: str
    description: Optional[str] = ""
    required: bool = False
    multi: bool = False
    sort: int = 0
    active: bool = True


class ConfigOptionInput(BaseModel):
    configurator: str
    category_key: str
    name: str
    description: Optional[str] = ""
    image_url: Optional[str] = ""
    overlay_image_url: Optional[str] = ""
    color_hex: Optional[str] = ""
    dolibarr_product_id: Optional[str] = ""
    sku: Optional[str] = ""
    price: Optional[float] = None
    price_on_request: bool = False
    available: bool = True
    active: bool = True
    is_demo: bool = False
    sort: int = 0
    specs: dict = {}
    incompatible_with: List[str] = []
    depends_on: List[str] = []


class SavedConfigInput(BaseModel):
    configurator: str
    selections: dict
    note: Optional[str] = ""
    contact: Optional[RepairContact] = None


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
    google_place_id: Optional[str] = None
    seo_default_title: Optional[str] = None
    seo_default_description: Optional[str] = None
    impressum_html: Optional[str] = None
    datenschutz_html: Optional[str] = None
    legal_reviewed: Optional[bool] = None

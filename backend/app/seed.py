"""Idempotent database seeding for the website and central inquiry flow."""

import os

from .db import get_db, now_utc
from .security import hash_password


async def ensure_indexes():
    db = get_db()
    await db.users.create_index("email", unique=True)
    await db.password_reset_tokens.create_index("expires_at", expireAfterSeconds=0)
    await db.login_attempts.create_index("identifier")
    await db.services.create_index("slug")
    await db.repair_requests.create_index(
        "request_id",
        unique=True,
        name="unique_inquiry_request_id",
        partialFilterExpression={"request_id": {"$type": "string", "$gt": ""}},
    )
    await db.media.create_index(
        "expires_at",
        name="inquiry_attachment_expiry",
    )
    await db.media.create_index(
        [("draft_request_id", 1), ("draft_slot", 1)],
        unique=True,
        name="unique_inquiry_attachment_slot",
        partialFilterExpression={
            "kind": "repair_attachment",
            "draft_request_id": {"$type": "string"},
            "draft_slot": {"$type": "number"},
        },
    )


async def seed_admin():
    db = get_db()
    email = os.environ.get("ADMIN_EMAIL", "admin@it-tabelander.at").lower().strip()
    password = os.environ.get("ADMIN_PASSWORD", "changeme123")
    existing = await db.users.find_one({"role": "super_admin"})
    if existing is None:
        existing = await db.users.find_one({"email": email})
    if existing is None:
        await db.users.insert_one({
            "email": email,
            "password_hash": hash_password(password),
            "name": "Administrator",
            "role": "super_admin",
            "created_at": now_utc(),
        })
    elif os.environ.get("IT_TABELANDER_RESET_ADMIN", "0") == "1":
        updates = {"password_hash": hash_password(password), "role": "super_admin"}
        email_owner = await db.users.find_one({"email": email})
        if email_owner is None or email_owner["_id"] == existing["_id"]:
            updates["email"] = email
        await db.users.update_one({"_id": existing["_id"]}, {"$set": updates})
    os.environ.pop("IT_TABELANDER_RESET_ADMIN", None)


async def seed_settings():
    db = get_db()
    if await db.settings.find_one({"_id": "site"}) is not None:
        return
    await db.settings.insert_one({
        "_id": "site",
        "company_name": "IT-Tabelander",
        "tagline": "IT-Technik, die funktioniert.",
        "email": "",
        "phone": "",
        "address": "",
        "city": "",
        "region": "Tirol",
        "postal_code": "",
        "country": "Österreich",
        "service_area": "Tirol & Österreich",
        "opening_hours": [],
        "social_links": {},
        "ga_measurement_id": os.environ.get("GA_MEASUREMENT_ID", ""),
        "canonical_base_url": os.environ.get(
            "CANONICAL_BASE_URL", "https://it.tabelander.co.at"
        ),
        "dolibarr_timeout_seconds": 8,
        "dolibarr_country_code": "AT",
        "google_place_id": os.environ.get("GOOGLE_PLACE_ID", ""),
        "seo_default_title": (
            "IT-Tabelander – IT-Service, Reparatur & Gaming-Hardware in Tirol"
        ),
        "seo_default_description": (
            "Professionelle IT-Technik, Reparatur und individuelle Gaming-Hardware "
            "aus einer Hand. CompTIA A+ zertifiziert, Ausbildung am WIFI Tirol."
        ),
        "impressum_html": "",
        "datenschutz_html": "",
        "legal_reviewed": False,
        "updated_at": now_utc(),
    })


SERVICES = [
    {
        "slug": "pc-bau",
        "title": "Individueller PC-Bau",
        "icon": "cpu",
        "short_description": (
            "Maßgeschneiderte Systeme nach deinem Einsatzzweck – vom Office-PC bis "
            "zum High-End-Gaming-Rechner."
        ),
        "long_description": (
            "Ob Gaming-PC, Office-PC, Workstation oder Multimedia-System: Ich baue "
            "individuelle Rechner exakt nach deinen Anforderungen. Jede Zusammenstellung "
            "wird auf Kompatibilität geprüft und vor der Auslieferung getestet."
        ),
        "bullets": [
            "Gaming-PC", "Office-PC", "Workstation", "Multimedia-PC", "Individuelle Builds"
        ],
        "sort": 1,
    },
    {
        "slug": "pc-reparatur",
        "title": "PC-Reparatur",
        "icon": "wrench",
        "short_description": (
            "Diagnose und Reparatur von Hardware- und Systemfehlern – strukturiert und transparent."
        ),
        "long_description": (
            "Von der Fehlerdiagnose über defekte Komponenten bis zu Überhitzung, Netzteil-, "
            "Mainboard- oder Speicherproblemen. Auch Betriebssystemprobleme und Aufrüstungen "
            "gehören dazu."
        ),
        "bullets": [
            "Diagnose", "Hardwarefehler", "Kühlung / Überhitzung", "Netzteil & Mainboard",
            "Speicher", "Betriebssystem", "Aufrüstung"
        ],
        "sort": 2,
    },
    {
        "slug": "notebook-reparatur",
        "title": "Notebook-Reparatur",
        "icon": "laptop",
        "short_description": (
            "Fehlersuche, Aufrüstung und Reparatur – abhängig vom Modell und technisch machbar."
        ),
        "long_description": (
            "SSD-Tausch, RAM-Erweiterung, Akkutausch, Lüfter/Kühlung und Wärmeleitpaste. "
            "Weitere Reparaturen je nach Modell. Ob eine Reparatur möglich ist, wird vorab geprüft."
        ),
        "bullets": [
            "Fehlersuche", "SSD-Tausch", "RAM-Erweiterung", "Akkutausch",
            "Lüfter / Kühlung", "Wärmeleitpaste"
        ],
        "sort": 3,
    },
    {
        "slug": "pc-aufruestung",
        "title": "PC- & Notebook-Upgrades",
        "icon": "arrow-up-circle",
        "short_description": (
            "Mehr Leistung durch gezielte Upgrades – nach Prüfung von Kompatibilität und Sinnhaftigkeit."
        ),
        "long_description": (
            "RAM, SSD/NVMe, Grafikkarte, CPU, Netzteil, Kühler oder WLAN/Netzwerk. "
            "Ich prüfe vorher Kompatibilität und Sinnhaftigkeit."
        ),
        "bullets": [
            "RAM", "SSD / NVMe / HDD", "Grafikkarte", "CPU", "Netzteil",
            "Kühler / Lüfter", "WLAN / Netzwerk"
        ],
        "sort": 4,
    },
    {
        "slug": "konsolen-reparatur",
        "title": "Konsolenreparatur",
        "icon": "gamepad-2",
        "short_description": (
            "Reparatur von PlayStation, Xbox und Nintendo – unterstützte Modelle werden laufend erweitert."
        ),
        "long_description": (
            "PlayStation 5 und weitere PlayStation-Modelle, Xbox Series und weitere Xbox-Modelle "
            "sowie Nintendo Switch und weitere Modelle."
        ),
        "bullets": ["PlayStation 5", "Xbox Series", "Nintendo Switch", "weitere Modelle auf Anfrage"],
        "sort": 5,
    },
    {
        "slug": "controller-reparatur",
        "title": "Controller-Reparatur",
        "icon": "gamepad",
        "short_description": (
            "Stick Drift, Buttons, Trigger, Ladeprobleme und individuelle Umbauten."
        ),
        "long_description": (
            "Reparatur von Analogsticks, Buttons, Triggern, Bumpern, Gehäuse und Ladeproblemen. "
            "Individuelle Umbauten werden nach einer Machbarkeitsprüfung persönlich abgestimmt."
        ),
        "bullets": [
            "Stick Drift / Analogsticks", "Buttons", "Trigger & Bumper", "Gehäuse",
            "Ladeprobleme", "individuelle Umbauten"
        ],
        "sort": 6,
    },
]


async def seed_services():
    db = get_db()
    if await db.services.count_documents({}) > 0:
        return
    docs = []
    for service in SERVICES:
        document = dict(service)
        document.update({
            "heading": service["title"],
            "image_url": "/assets/img/services/service-overview-1200.webp",
            "seo_title": f"{service['title']} in Tirol – IT-Tabelander",
            "seo_description": service["short_description"],
            "active": True,
            "created_at": now_utc(),
            "updated_at": now_utc(),
        })
        docs.append(document)
    await db.services.insert_many(docs)


FAQS = [
    {
        "question": "Wie lange dauert eine Reparatur?",
        "category": "reparatur",
        "answer": (
            "Die Dauer hängt vom Gerät und Fehlerbild ab. Nach der Diagnose erhältst du "
            "eine realistische Einschätzung."
        ),
    },
    {
        "question": "Was kostet eine Diagnose?",
        "category": "reparatur",
        "answer": (
            "Die Diagnosekosten werden transparent vor Beginn kommuniziert. "
            "Verbindliche Preise erhältst du nach Prüfung deiner Anfrage."
        ),
    },
    {
        "question": "Kann jeder Laptop aufgerüstet werden?",
        "category": "upgrades",
        "answer": (
            "Nein. Ich prüfe vorab Kompatibilität und Sinnhaftigkeit und melde mich "
            "mit einer ehrlichen Einschätzung."
        ),
    },
    {
        "question": "Welche Konsolen repariert IT-Tabelander?",
        "category": "konsolen",
        "answer": (
            "PlayStation, Xbox und Nintendo. Frag mit Modell und Fehlerbild über das "
            "Anfrageformular an."
        ),
    },
    {
        "question": "Werden auch eigene Komponenten verbaut?",
        "category": "reparatur",
        "answer": (
            "Ja, sofern sie technisch passen und geprüft wurden. Die Kompatibilität wird "
            "vor dem Einbau kontrolliert."
        ),
    },
    {
        "question": "Wie frage ich einen Wunsch-PC an?",
        "category": "pc-bau",
        "answer": (
            "Wähle im Anfrageformular ‚Neuer PC nach Wunsch‘ und nenne Einsatzzweck, "
            "Budget, Zeitrahmen und besondere Wünsche. Die Zusammenstellung wird persönlich geprüft."
        ),
    },
    {
        "question": "Wird ein Wunsch-PC vor dem Auftrag geprüft?",
        "category": "pc-bau",
        "answer": (
            "Ja. Komponenten, Kompatibilität und Sinnhaftigkeit werden persönlich geprüft. "
            "Ein verbindlicher Auftrag entsteht erst nach Angebot und ausdrücklicher Zustimmung."
        ),
    },
    {
        "question": "Kann ich einen Controller individuell umbauen lassen?",
        "category": "controller",
        "answer": (
            "Ja. Beschreibe gewünschte Optik, Tasten und Funktionen im Anfrageformular. "
            "Machbarkeit, Teile und Preis werden danach persönlich abgestimmt."
        ),
    },
    {
        "question": "Was passiert nach dem Absenden?",
        "category": "anfrage",
        "answer": (
            "Du erhältst eine Referenznummer. Die Anfrage wird intern gespeichert und "
            "zur Bearbeitung als Interessent mit Ticket an Dolibarr übergeben."
        ),
    },
]

LEGACY_FAQ_MIGRATIONS = {
    "Wie funktioniert der PC Builder?": FAQS[5],
    "Wird eine PC-Konfiguration vor Bestellung geprüft?": FAQS[6],
    "Kann ich meinen PS5-Controller individuell gestalten?": FAQS[7],
}


async def migrate_legacy_builder_faqs() -> bool:
    """Replace only the exact FAQ records seeded by former builder versions."""
    db = get_db()
    migrated = False
    for old_question, replacement in LEGACY_FAQ_MIGRATIONS.items():
        old = await db.faqs.find_one({"question": old_question})
        if not old:
            continue
        duplicate = await db.faqs.find_one({"question": replacement["question"]})
        if duplicate and duplicate["_id"] != old["_id"]:
            await db.faqs.delete_one({"_id": old["_id"]})
        else:
            await db.faqs.update_one(
                {"_id": old["_id"]},
                {"$set": {**replacement, "updated_at": now_utc()}},
            )
        migrated = True
    return migrated


async def seed_faqs():
    db = get_db()
    migration_id = "central_inquiry_faqs_v1"
    if await db.faqs.count_documents({}) == 0:
        docs = [dict(item, sort=index, active=True, created_at=now_utc()) for index, item in enumerate(FAQS)]
        await db.faqs.insert_many(docs)
        await db.app_migrations.update_one(
            {"_id": migration_id},
            {"$set": {"completed_at": now_utc()}},
            upsert=True,
        )
        return
    if await db.app_migrations.find_one({"_id": migration_id}):
        return

    await migrate_legacy_builder_faqs()
    follow_up = FAQS[-1]
    await db.faqs.update_one(
        {"question": follow_up["question"]},
        {"$setOnInsert": {
            **follow_up,
            "sort": await db.faqs.count_documents({}),
            "active": True,
            "created_at": now_utc(),
        }},
        upsert=True,
    )
    # The marker is written last. A crash before this point safely retries the
    # exact-question migration and upsert on the next start.
    await db.app_migrations.update_one(
        {"_id": migration_id},
        {"$set": {"completed_at": now_utc()}},
        upsert=True,
    )


async def run_all_seeds():
    await ensure_indexes()
    await seed_admin()
    await seed_settings()
    await seed_services()
    await seed_faqs()

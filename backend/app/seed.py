"""Idempotent database seeding: admin user, indexes, default settings,
real service catalogue, FAQs and demo configurator data (clearly flagged)."""
import os

from .db import get_db, now_utc
from .security import hash_password, verify_password


async def ensure_indexes():
    db = get_db()
    await db.users.create_index("email", unique=True)
    await db.password_reset_tokens.create_index("expires_at", expireAfterSeconds=0)
    await db.login_attempts.create_index("identifier")
    await db.services.create_index("slug")
    await db.saved_configurations.create_index("config_id", unique=True)
    await db.dolibarr_product_cache.create_index("dolibarr_product_id", unique=True)


async def seed_admin():
    db = get_db()
    email = os.environ.get("ADMIN_EMAIL", "admin@it-tabelander.at")
    password = os.environ.get("ADMIN_PASSWORD", "changeme123")
    existing = await db.users.find_one({"email": email})
    if existing is None:
        await db.users.insert_one({
            "email": email, "password_hash": hash_password(password),
            "name": "Administrator", "role": "super_admin", "created_at": now_utc(),
        })
    elif not verify_password(password, existing["password_hash"]):
        await db.users.update_one({"email": email},
                                  {"$set": {"password_hash": hash_password(password)}})


async def seed_settings():
    db = get_db()
    existing = await db.settings.find_one({"_id": "site"})
    defaults = {
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
        "google_place_id": os.environ.get("GOOGLE_PLACE_ID", ""),
        "seo_default_title": "IT-Tabelander – IT-Service, Reparatur & Gaming-Hardware in Tirol",
        "seo_default_description": "Professionelle IT-Technik, Reparatur und individuelle Gaming-Hardware aus einer Hand. CompTIA A+ zertifiziert, Ausbildung am WIFI Tirol.",
        "impressum_html": "",
        "datenschutz_html": "",
        "legal_reviewed": False,
        "updated_at": now_utc(),
    }
    if existing is None:
        await db.settings.insert_one(defaults)


SERVICES = [
    {"slug": "pc-bau", "title": "Individueller PC-Bau", "icon": "cpu",
     "short_description": "Maßgeschneiderte Systeme nach deinem Einsatzzweck – vom Office-PC bis zum High-End-Gaming-Rechner.",
     "long_description": "Ob Gaming-PC, Office-PC, Workstation oder Multimedia-System: Ich baue individuelle Rechner exakt nach deinen Anforderungen. Jede Konfiguration wird auf Kompatibilität geprüft und vor der Auslieferung getestet.",
     "bullets": ["Gaming-PC", "Office-PC", "Workstation", "Multimedia-PC", "Individuelle Builds"],
     "sort": 1},
    {"slug": "pc-reparatur", "title": "PC-Reparatur", "icon": "wrench",
     "short_description": "Diagnose und Reparatur von Hardware- und Systemfehlern – strukturiert und transparent.",
     "long_description": "Von der Fehlerdiagnose über defekte Komponenten bis zu Überhitzung, Netzteil-, Mainboard- oder Speicherproblemen. Auch Betriebssystemprobleme und Aufrüstungen gehören dazu.",
     "bullets": ["Diagnose", "Hardwarefehler", "Kühlung / Überhitzung", "Netzteil & Mainboard", "Speicher", "Betriebssystem", "Aufrüstung"],
     "sort": 2},
    {"slug": "notebook-reparatur", "title": "Notebook-Reparatur", "icon": "laptop",
     "short_description": "Fehlersuche, Aufrüstung und Reparatur – abhängig vom Modell und technisch machbar.",
     "long_description": "SSD-Tausch, RAM-Erweiterung, Akkutausch, Lüfter/Kühlung und Wärmeleitpaste. Weitere Reparaturen je nach Modell. Ob eine Reparatur möglich ist, wird vorab geprüft – es wird nichts versprochen, was technisch nicht machbar ist.",
     "bullets": ["Fehlersuche", "SSD-Tausch", "RAM-Erweiterung", "Akkutausch", "Lüfter / Kühlung", "Wärmeleitpaste"],
     "sort": 3},
    {"slug": "pc-aufruestung", "title": "PC- & Notebook-Upgrades", "icon": "arrow-up-circle",
     "short_description": "Mehr Leistung durch gezielte Upgrades – nach Prüfung von Kompatibilität und Sinnhaftigkeit.",
     "long_description": "RAM, SSD/NVMe, Grafikkarte, CPU, Netzteil, Kühler oder WLAN/Netzwerk. Nicht jedes Gerät lässt sich beliebig aufrüsten – ich prüfe vorher Kompatibilität und Sinnhaftigkeit.",
     "bullets": ["RAM", "SSD / NVMe / HDD", "Grafikkarte", "CPU", "Netzteil", "Kühler / Lüfter", "WLAN / Netzwerk"],
     "sort": 4},
    {"slug": "konsolen-reparatur", "title": "Konsolenreparatur", "icon": "gamepad-2",
     "short_description": "Reparatur von PlayStation, Xbox und Nintendo – unterstützte Modelle werden laufend erweitert.",
     "long_description": "PlayStation 5 und weitere PlayStation-Modelle, Xbox Series und weitere Xbox-Modelle sowie Nintendo Switch und weitere Modelle. Welche Geräte konkret unterstützt werden, wird laufend gepflegt.",
     "bullets": ["PlayStation 5", "Xbox Series", "Nintendo Switch", "weitere Modelle auf Anfrage"],
     "sort": 5},
    {"slug": "controller-reparatur", "title": "Controller-Reparatur", "icon": "gamepad",
     "short_description": "Stick Drift, Buttons, Trigger, Ladeprobleme – für PlayStation, Xbox und Nintendo.",
     "long_description": "Reparatur von Analogsticks (Stick Drift), Buttons, Triggern, Bumpern, Gehäuse und Ladeproblemen. Eine Reparatur wird erst nach Prüfung des Geräts zugesagt – nichts wird als garantiert dargestellt.",
     "bullets": ["Stick Drift / Analogsticks", "Buttons", "Trigger & Bumper", "Gehäuse", "Ladeprobleme"],
     "sort": 6},
]


async def seed_services():
    db = get_db()
    if await db.services.count_documents({}) > 0:
        return
    docs = []
    for s in SERVICES:
        d = dict(s)
        d.update({
            "heading": s["title"], "image_url": f"/assets/img/services/service-overview-1200.webp",
            "seo_title": f"{s['title']} in Tirol – IT-Tabelander",
            "seo_description": s["short_description"], "active": True,
            "created_at": now_utc(), "updated_at": now_utc(),
        })
        docs.append(d)
    await db.services.insert_many(docs)


FAQS = [
    {"question": "Wie lange dauert eine Reparatur?", "category": "reparatur",
     "answer": "Die Dauer hängt vom Gerät und Fehlerbild ab. Nach der Diagnose erhältst du eine realistische Einschätzung. Konkrete Bearbeitungszeiten werden erst nach Prüfung genannt."},
    {"question": "Was kostet eine Diagnose?", "category": "reparatur",
     "answer": "Die Diagnosekosten werden transparent vor Beginn kommuniziert. Verbindliche Preise erhältst du im Rahmen der Anfrage."},
    {"question": "Kann jeder Laptop aufgerüstet werden?", "category": "upgrades",
     "answer": "Nein. Nicht jedes Gerät lässt sich beliebig aufrüsten. Ich prüfe vorab Kompatibilität und Sinnhaftigkeit und melde mich mit einer ehrlichen Einschätzung."},
    {"question": "Welche Konsolen repariert IT-Tabelander?", "category": "konsolen",
     "answer": "PlayStation, Xbox und Nintendo. Die konkret unterstützten Modelle werden laufend gepflegt – frag bei deinem Gerät einfach an."},
    {"question": "Werden auch eigene Komponenten verbaut?", "category": "reparatur",
     "answer": "Ja, sofern sie technisch passen und geprüft wurden. Die Kompatibilität wird vor dem Einbau kontrolliert."},
    {"question": "Wie funktioniert der Gaming-PC-Konfigurator?", "category": "konfigurator",
     "answer": "Du wählst Schritt für Schritt deine Komponenten. Der Konfigurator prüft – soweit die Daten es erlauben – die Kompatibilität und erstellt eine übersichtliche Zusammenfassung als Anfrage."},
    {"question": "Wird eine PC-Konfiguration vor Bestellung geprüft?", "category": "konfigurator",
     "answer": "Ja. Jede Konfiguration wird vor einer verbindlichen Bestellung von IT-Tabelander geprüft und freigegeben. Es wird niemals blind eine technische Kompatibilität garantiert."},
    {"question": "Kann ich meinen PS5-Controller individuell gestalten?", "category": "controller",
     "answer": "Ja. Mit dem DualSense-Konfigurator stellst du dein Wunschdesign zusammen. Umbauten wie Hall-Effect-Sticks werden nach Prüfung des Geräts umgesetzt."},
]


async def seed_faqs():
    db = get_db()
    if await db.faqs.count_documents({}) > 0:
        return
    docs = [dict(f, sort=i, active=True, created_at=now_utc()) for i, f in enumerate(FAQS)]
    await db.faqs.insert_many(docs)


# ---- Configurator demo data (clearly flagged as demo) ----
PS5_CATEGORIES = [
    {"key": "shell_front", "name": "Front-Gehäuse", "sort": 1, "required": True},
    {"key": "buttons", "name": "Aktionstasten", "sort": 2, "required": True},
    {"key": "dpad", "name": "D-Pad", "sort": 3},
    {"key": "sticks", "name": "Analogsticks", "sort": 4},
    {"key": "triggers", "name": "Trigger", "sort": 5},
    {"key": "shell_back", "name": "Rückseite", "sort": 6},
    {"key": "special", "name": "Spezialoptionen", "sort": 7, "multi": True},
]

# color_hex drives the live SVG preview in the frontend
PS5_OPTIONS = {
    "shell_front": [("Schwarz", "#1A1D22", 0), ("Weiß", "#F4F5F7", 0), ("Cyber-Orange", "#F26522", 25), ("Midnight Navy", "#111C2D", 25), ("Carbon", "#2B2F36", 30)],
    "buttons": [("Weiß", "#E8EAED", 0), ("Schwarz", "#22262B", 10), ("Orange", "#F26522", 15)],
    "dpad": [("Standard Schwarz", "#22262B", 0), ("Weiß", "#E8EAED", 8)],
    "sticks": [("Standard", "#22262B", 0), ("Weiß", "#E8EAED", 8), ("Hall-Effect Anti-Drift", "#3A3F46", 45)],
    "triggers": [("Standard", "#2B2F36", 0), ("Smart Trigger Stop", "#F26522", 20)],
    "shell_back": [("Schwarz", "#1A1D22", 0), ("Weiß", "#F4F5F7", 0), ("Carbon", "#2B2F36", 25)],
    "special": [("Grip-Textur", "#3A3F46", 18), ("Back-Paddles", "#22262B", 40)],
}


async def seed_ps5_configurator():
    db = get_db()
    if await db.config_categories.count_documents({"configurator": "ps5"}) > 0:
        return
    for c in PS5_CATEGORIES:
        await db.config_categories.insert_one({
            "configurator": "ps5", "key": c["key"], "name": c["name"],
            "description": "", "required": c.get("required", False),
            "multi": c.get("multi", False), "sort": c["sort"], "active": True,
            "created_at": now_utc(),
        })
    sort_i = 0
    for cat, opts in PS5_OPTIONS.items():
        for name, color, price in opts:
            sort_i += 1
            await db.config_options.insert_one({
                "configurator": "ps5", "category_key": cat, "name": name,
                "description": "", "image_url": "", "overlay_image_url": "",
                "color_hex": color, "dolibarr_product_id": "", "sku": "",
                "price": float(price) if price else 0.0,
                "price_on_request": False, "available": True, "active": True,
                "is_demo": True, "sort": sort_i, "specs": {},
                "incompatible_with": [], "depends_on": [], "created_at": now_utc(),
            })


PC_CATEGORIES = [
    {"key": "cpu", "name": "Prozessor (CPU)", "sort": 1, "required": True},
    {"key": "cooler", "name": "CPU-Kühler", "sort": 2},
    {"key": "mainboard", "name": "Mainboard", "sort": 3, "required": True},
    {"key": "ram", "name": "Arbeitsspeicher (RAM)", "sort": 4, "required": True},
    {"key": "gpu", "name": "Grafikkarte (GPU)", "sort": 5},
    {"key": "storage", "name": "SSD / NVMe", "sort": 6, "required": True},
    {"key": "psu", "name": "Netzteil", "sort": 7, "required": True},
    {"key": "case", "name": "Gehäuse", "sort": 8, "required": True},
    {"key": "os", "name": "Betriebssystem", "sort": 9},
]

PC_OPTIONS = {
    "cpu": [
        ("AMD Ryzen 7 7800X3D", 449, {"socket": "AM5", "tdp": 120, "cores": 8}),
        ("Intel Core i5-14600K", 329, {"socket": "LGA1700", "tdp": 125, "cores": 14}),
    ],
    "cooler": [
        ("Luftkühler 240W", 59, {"socket": ["AM5", "LGA1700"], "height_mm": 158, "type": "air"}),
        ("AIO 360mm Wasserkühlung", 149, {"socket": ["AM5", "LGA1700"], "radiator_mm": 360, "type": "aio"}),
    ],
    "mainboard": [
        ("B650 ATX Mainboard", 189, {"socket": "AM5", "form_factor": "ATX", "ram_type": "DDR5"}),
        ("Z790 ATX Mainboard", 219, {"socket": "LGA1700", "form_factor": "ATX", "ram_type": "DDR5"}),
    ],
    "ram": [
        ("32GB DDR5-6000 (2x16)", 119, {"ram_type": "DDR5", "size_gb": 32, "speed": 6000}),
        ("64GB DDR5-6000 (2x32)", 219, {"ram_type": "DDR5", "size_gb": 64, "speed": 6000}),
    ],
    "gpu": [
        ("NVIDIA RTX 4070 Ti", 799, {"length_mm": 305, "recommended_psu_w": 700, "tdp": 285}),
        ("NVIDIA RTX 4080 Super", 1099, {"length_mm": 336, "recommended_psu_w": 850, "tdp": 320}),
    ],
    "storage": [
        ("1TB NVMe Gen4 SSD", 89, {"interface": "NVMe", "capacity_gb": 1000}),
        ("2TB NVMe Gen4 SSD", 159, {"interface": "NVMe", "capacity_gb": 2000}),
    ],
    "psu": [
        ("750W 80+ Gold", 109, {"wattage": 750}),
        ("1000W 80+ Gold", 169, {"wattage": 1000}),
    ],
    "case": [
        ("Midi-Tower ATX", 99, {"form_factors": ["ATX", "mATX"], "max_gpu_mm": 360, "max_cooler_mm": 170, "max_radiator_mm": 360}),
    ],
    "os": [
        ("Windows 11 Home", 145, {}),
        ("Ohne Betriebssystem", 0, {}),
    ],
}


PC_CAT_IMAGES = {
    "cpu": "https://images.pexels.com/photos/2105927/pexels-photo-2105927.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "cooler": "https://images.pexels.com/photos/5327981/pexels-photo-5327981.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "mainboard": "https://images.pexels.com/photos/2588756/pexels-photo-2588756.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "ram": "https://images.pexels.com/photos/31993524/pexels-photo-31993524.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "gpu": "https://images.pexels.com/photos/8622912/pexels-photo-8622912.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "storage": "https://images.pexels.com/photos/28666524/pexels-photo-28666524.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "psu": "https://images.pexels.com/photos/32710071/pexels-photo-32710071.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "case": "https://images.pexels.com/photos/38181602/pexels-photo-38181602.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=400&w=400",
    "os": "",
}


async def seed_pc_configurator():
    db = get_db()
    if await db.config_categories.count_documents({"configurator": "pc"}) > 0:
        return
    for c in PC_CATEGORIES:
        await db.config_categories.insert_one({
            "configurator": "pc", "key": c["key"], "name": c["name"],
            "description": "", "required": c.get("required", False),
            "multi": False, "sort": c["sort"], "active": True, "created_at": now_utc(),
        })
    sort_i = 0
    for cat, opts in PC_OPTIONS.items():
        for name, price, specs in opts:
            sort_i += 1
            await db.config_options.insert_one({
                "configurator": "pc", "category_key": cat, "name": name,
                "description": "", "image_url": PC_CAT_IMAGES.get(cat, ""),
                "overlay_image_url": "", "color_hex": "",
                "dolibarr_product_id": "", "sku": "",
                "price": float(price), "price_on_request": False,
                "available": True, "active": True, "is_demo": True,
                "sort": sort_i, "specs": specs,
                "incompatible_with": [], "depends_on": [], "created_at": now_utc(),
            })


async def run_all_seeds():
    await ensure_indexes()
    await seed_admin()
    await seed_settings()
    await seed_services()
    await seed_faqs()
    await seed_ps5_configurator()
    await seed_pc_configurator()

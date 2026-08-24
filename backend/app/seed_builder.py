"""Idempotent seed for the data-driven PS5 Controller Builder (2 models)."""
from .db import get_db, now_utc

COLORS = [
    ("Black", "#1A1D22", 0), ("White", "#F4F5F7", 0), ("New Hope Gray", "#9AA0A6", 4),
    ("Scarlet Red", "#B3121D", 6), ("Chrome Red", "#C8202B", 12), ("Chrome Silver", "#C7CCD1", 12),
    ("Chrome Gold", "#C9A227", 14), ("Dream Blue", "#2E6BE6", 6), ("Aqua Green", "#17B890", 6),
    ("Purple", "#6B3FA0", 6), ("Clear Black", "#2B2F36", 8),
]
FEW = COLORS[:6]


def _variants(colors, base):
    out = []
    for i, (name, hex_, extra) in enumerate(colors):
        out.append({
            "name": name, "color_hex": hex_, "overlay_image_url": "", "thumb_url": "",
            "price": float(base + extra), "sku": "", "available": True, "active": True,
            "is_demo": True, "sort": i, "layer": {},
        })
    return out


CATS = {
    "dualsense": [
        ("shell", "Front Shell", "shell", "front", True),
        ("trim", "Decorative Trim", "trim", "front", False),
        ("touchpad", "Touchpad Cover", "touchpad", "front", False),
        ("dpad", "D-Pad", "dpad", "front", False),
        ("buttons", "Action Buttons", "buttons", "front", False),
        ("sticks", "Thumbsticks", "sticks", "front", False),
        ("accent", "Accent Rings", "accent_rings", "front", False),
        ("back_shell", "Backplate / Back Shell", "back_shell", "back", False),
        ("grips", "Performance Grips", "grips", "back", False),
        ("paddles", "Back Paddles (RISE)", "paddles", "back", False),
    ],
    "edge": [
        ("shell", "Front Housing Shell", "shell", "front", True),
        ("trim", "Decorative Trim", "trim", "front", False),
        ("touchpad", "Touchpad Cover", "touchpad", "front", False),
        ("dpad", "Split D-Pad", "dpad", "front", False),
        ("buttons", "Full Set Buttons", "buttons", "front", False),
        ("sticks", "Thumbsticks", "sticks", "front", False),
        ("back_shell", "Bottom / Back Shell", "back_shell", "back", False),
        ("grips", "Grips", "grips", "back", False),
        ("paddles", "Metal Back Paddles", "paddles", "back", False),
        ("beyond", "BEYOND Kit", "beyond", "back", False),
    ],
}

# (category_key, product name, colors, base price)
PRODUCTS = {
    "dualsense": [
        ("shell", "Full Set Shell", COLORS, 24.9),
        ("trim", "Decorative Trim", FEW, 9.9),
        ("touchpad", "Touchpad Cover", FEW, 12.9),
        ("dpad", "Split D-Pad", FEW, 9.9),
        ("buttons", "Full Set Buttons", COLORS, 16.9),
        ("sticks", "Thumbsticks", FEW, 12.9),
        ("accent", "Octagonal Accent Rings", FEW, 7.9),
        ("back_shell", "Performance Grip Back Shell", COLORS, 22.9),
        ("grips", "Performance Grips", FEW, 8.9),
        ("paddles", "RISE4 Back Paddle Kit", FEW, 52.9),
    ],
    "edge": [
        ("shell", "Full Set Front Housing", COLORS, 29.9),
        ("trim", "Top/Bottom Decorative Trim", FEW, 11.9),
        ("touchpad", "Touchpad Cover", FEW, 12.9),
        ("dpad", "Split D-Pad", FEW, 10.9),
        ("buttons", "Full Set Buttons", COLORS, 18.9),
        ("sticks", "Thumbsticks", FEW, 13.9),
        ("back_shell", "Back Housing Shell", COLORS, 26.9),
        ("grips", "Grips", FEW, 9.9),
        ("paddles", "Metal Back Paddles (K1-K4)", FEW, 39.9),
        ("beyond", "BEYOND Back Paddle Kit", FEW, 59.9),
    ],
}


PREVIEWS = {
    "dualsense": {
        "preview_front": "/assets/img/controller/controller-dualsense-premium.png",
        "preview_back": "/assets/img/controller/controller-dualsense-back.png",
    },
    "edge": {
        "preview_front": "/assets/img/controller/controller-dualsense-edge-official-front.png",
        "preview_back": "/assets/img/controller/controller-dualsense-edge-official-back.png",
    },
}


async def _ensure_previews(db):
    """Always keep the two known controllers pointing at the real photo assets
    (runs even after the initial seed so previews reach an existing DB)."""
    for key, imgs in PREVIEWS.items():
        await db.controllers.update_one(
            {"key": key},
            {"$set": {"preview_front": imgs["preview_front"], "preview_back": imgs["preview_back"]}},
        )


async def seed_builder():
    db = get_db()
    await _ensure_previews(db)
    if await db.controllers.count_documents({}) > 0:
        return
    versions_ds = [{"code": c, "label": c} for c in ["BDM-010", "BDM-020", "BDM-030", "BDM-040", "BDM-050", "BDM-060"]]
    versions_edge = [{"code": "Edge", "label": "PS5 Edge"}]
    await db.controllers.insert_one({
        "key": "dualsense", "name": "PS5 DualSense", "model": "DualSense",
        "base_price": 74.9, "preview_image": "",
        "preview_front": PREVIEWS["dualsense"]["preview_front"],
        "preview_back": PREVIEWS["dualsense"]["preview_back"],
        "versions": versions_ds,
        "active": True, "sort": 1, "created_at": now_utc(),
    })
    await db.controllers.insert_one({
        "key": "edge", "name": "PS5 DualSense Edge", "model": "DualSense Edge",
        "base_price": 239.0, "preview_image": "",
        "preview_front": PREVIEWS["edge"]["preview_front"],
        "preview_back": PREVIEWS["edge"]["preview_back"],
        "versions": versions_edge,
        "active": True, "sort": 2, "created_at": now_utc(),
    })
    for ck, cats in CATS.items():
        for i, (key, name, region, side, required) in enumerate(cats):
            await db.builder_categories.insert_one({
                "controller_key": ck, "key": key, "name": name, "region_key": region,
                "side": side, "required": required, "multi": False, "sort": i,
                "active": True, "created_at": now_utc(),
            })
    for ck, prods in PRODUCTS.items():
        for i, (cat, name, colors, base) in enumerate(prods):
            await db.builder_products.insert_one({
                "controller_key": ck, "category_key": cat, "name": name,
                "description": "", "sku": "", "dolibarr_product_id": "",
                "compatible_versions": [], "requires": [], "excludes": [],
                "active": True, "is_demo": True, "sort": i,
                "variants": _variants(colors, base), "created_at": now_utc(),
            })

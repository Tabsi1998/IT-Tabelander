const configurator = document.querySelector("[data-controller-configurator]");
const form = configurator?.querySelector("[data-controller-form]");
const stage = configurator?.querySelector("[data-controller-stage]");
const modelInputs = Array.from(configurator?.querySelectorAll('input[name="model"]') || []);
const offerInputs = Array.from(configurator?.querySelectorAll('input[name="offers[]"]') || []);
const extraInputs = Array.from(configurator?.querySelectorAll('input[name="extras[]"]') || []);
const selectionError = configurator?.querySelector("[data-selection-error]");
const offerCards = Array.from(configurator?.querySelectorAll("[data-offer-card]") || []);
const offerPlaceholder = configurator?.querySelector("[data-offer-placeholder]");
const modelBadge = configurator?.querySelector("[data-model-badge]");
const visualCaption = configurator?.querySelector("[data-visual-caption]");
const summaryModel = configurator?.querySelector("[data-summary-model]");
const summaryOffers = configurator?.querySelector("[data-summary-offers]");
const summaryExtras = configurator?.querySelector("[data-summary-extras]");
const summaryPrice = configurator?.querySelector("[data-summary-price]");
const priceKicker = configurator?.querySelector("[data-price-kicker]");
const priceNote = configurator?.querySelector("[data-price-note]");

const selectedInput = (inputs) => inputs.find((input) => input.checked);
const selectedInputs = (inputs) => inputs.filter((input) => input.checked);
const inputLabels = (inputs) => selectedInputs(inputs).map((input) => input.dataset.label || input.value);

const formatPrice = (priceCents) => new Intl.NumberFormat("de-AT", {
    style: "currency",
    currency: "EUR",
}).format(priceCents / 100);

const expandedZones = (zone) => {
    if (zone === "sticks") {
        return ["stick-left", "stick-right"];
    }

    if (zone === "clicky-full") {
        return ["buttons", "dpad", "triggers"];
    }

    if (zone === "buttons") {
        return ["buttons", "dpad"];
    }

    return zone ? [zone] : [];
};

const updateZones = () => {
    const zoneInputs = selectedInputs(offerInputs);
    const activeZones = new Set(zoneInputs.flatMap((input) => expandedZones(input.dataset.zone || "")));

    configurator?.querySelectorAll("[data-controller-zone]").forEach((zone) => {
        zone.classList.toggle("is-active", activeZones.has(zone.dataset.controllerZone || ""));
    });

    stage?.classList.toggle("is-led-active", activeZones.has("led"));
};

const updateOfferVisibility = () => {
    const model = selectedInput(modelInputs)?.value || "";

    offerCards.forEach((card) => {
        const models = String(card.dataset.models || "").split(/\s+/).filter(Boolean);
        const visible = model !== "" && models.includes(model);
        card.hidden = !visible;

        const input = card.querySelector('input[name="offers[]"]');
        if (!visible && input) {
            input.checked = false;
        }
    });

    if (offerPlaceholder) {
        offerPlaceholder.hidden = model !== "";
    }
};

const applyOfferExclusivity = (changedInput) => {
    if (!changedInput.checked) {
        return;
    }

    const group = changedInput.dataset.exclusiveGroup || "";
    if (!group) {
        return;
    }

    offerInputs.forEach((input) => {
        if (input !== changedInput && input.dataset.exclusiveGroup === group) {
            input.checked = false;
        }
    });
};

const updateSummary = () => {
    const model = selectedInput(modelInputs);
    const offers = inputLabels(offerInputs);
    const extras = inputLabels(extraInputs);
    const selectedOffers = selectedInputs(offerInputs);
    const offerTotalCents = selectedOffers.reduce((total, input) => total + Number(input.dataset.priceCents || 0), 0);

    if (stage) {
        stage.dataset.controllerModel = model?.value || "";
        stage.setAttribute(
            "aria-label",
            model
                ? `Live-Vorschau ${model.dataset.label || model.value} mit ${offers.length} gewählten Upgrades`
                : "Live-Vorschau: Noch kein Controller-Modell gewählt",
        );
    }

    if (modelBadge) {
        modelBadge.textContent = model?.dataset.label || "Noch kein Modell gewählt";
    }

    if (visualCaption) {
        visualCaption.textContent = !model
            ? "Modell auswählen und Upgrades live entdecken"
            : offers.length === 0
                ? "Jetzt passende Upgrades auswählen"
                : `${offers.length} ${offers.length === 1 ? "Upgrade" : "Upgrades"} hervorgehoben`;
    }

    if (summaryModel) {
        summaryModel.textContent = model?.dataset.label || "Bitte auswählen";
    }

    if (summaryOffers) {
        summaryOffers.textContent = offers.length > 0 ? offers.join(", ") : "Noch kein Upgrade gewählt";
    }

    if (summaryExtras) {
        summaryExtras.textContent = extras.length > 0 ? extras.join(", ") : "Keine Zusatzangabe";
    }

    if (summaryPrice) {
        summaryPrice.textContent = formatPrice(offerTotalCents);
    }

    if (priceKicker) {
        priceKicker.textContent = "Voraussichtliche Paketsumme";
    }

    if (priceNote) {
        priceNote.textContent = offers.length > 0
            ? "Material, Einbau und Funktionstest laut gewählten Paketen."
            : "Wählen Sie mindestens ein Upgrade-Paket aus.";
    }

    if (selectionError && offers.length > 0) {
        selectionError.hidden = true;
    }

    updateZones();
};

if (form) {
    extraInputs.forEach((input) => {
        input.addEventListener("change", updateSummary);
    });

    modelInputs.forEach((input) => {
        input.addEventListener("change", () => {
            updateOfferVisibility();
            updateSummary();
        });
    });

    offerInputs.forEach((input) => {
        input.addEventListener("change", () => {
            applyOfferExclusivity(input);
            updateSummary();
        });
    });

    form.addEventListener("submit", (event) => {
        const model = selectedInput(modelInputs);
        const offers = selectedInputs(offerInputs);

        if (!model) {
            event.preventDefault();
            modelInputs[0]?.focus();
            modelInputs[0]?.reportValidity();
            return;
        }

        if (offers.length === 0) {
            event.preventDefault();
            if (selectionError) {
                selectionError.hidden = false;
            }
            const focusTarget = offerInputs.find((input) => !input.closest("[hidden]"));
            focusTarget?.focus();
        }
    });

    updateOfferVisibility();
    updateSummary();
}

const configurator = document.querySelector("[data-controller-configurator]");
const form = configurator?.querySelector("[data-controller-form]");
const stage = configurator?.querySelector("[data-controller-stage]");
const modelInputs = Array.from(configurator?.querySelectorAll('input[name="model"]') || []);
const sourceInputs = Array.from(configurator?.querySelectorAll('input[name="source"]') || []);
const shellInputs = Array.from(configurator?.querySelectorAll('input[name="shell"]') || []);
const offerInputs = Array.from(configurator?.querySelectorAll('input[name="offers[]"]') || []);
const extraInputs = Array.from(configurator?.querySelectorAll('input[name="extras[]"]') || []);
const selectionError = configurator?.querySelector("[data-selection-error]");
const offerCards = Array.from(configurator?.querySelectorAll("[data-offer-card]") || []);
const shellCards = Array.from(configurator?.querySelectorAll("[data-shell-card]") || []);
const offerPlaceholder = configurator?.querySelector("[data-offer-placeholder]");
const shellPlaceholder = configurator?.querySelector("[data-shell-placeholder]");
const modelBadge = configurator?.querySelector("[data-model-badge]");
const visualCaption = configurator?.querySelector("[data-visual-caption]");
const viewToggle = configurator?.querySelector("[data-controller-view-toggle]");
const viewLabel = configurator?.querySelector("[data-controller-view-label]");
const summaryModel = configurator?.querySelector("[data-summary-model]");
const summarySource = configurator?.querySelector("[data-summary-source]");
const summaryShell = configurator?.querySelector("[data-summary-shell]");
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

const sourcePriceForModel = (source, model) => {
    if (!source || !model) {
        return 0;
    }

    return Number(model === "dualsense-edge"
        ? source.dataset.priceDualsenseEdge || 0
        : source.dataset.priceDualsense || 0);
};

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

const setControllerView = (showBack) => {
    stage?.classList.toggle("is-showing-back", showBack);
    viewToggle?.setAttribute("aria-pressed", showBack ? "true" : "false");

    if (viewLabel) {
        viewLabel.textContent = showBack ? "Vorderseite ansehen" : "Rückseite ansehen";
    }
};

const updateZones = () => {
    const activeZones = new Set(selectedInputs(offerInputs)
        .flatMap((input) => expandedZones(input.dataset.zone || "")));

    configurator?.querySelectorAll("[data-controller-zone]").forEach((zone) => {
        zone.classList.toggle("is-active", activeZones.has(zone.dataset.controllerZone || ""));
    });

    stage?.classList.toggle("is-led-active", activeZones.has("led"));
    stage?.classList.toggle("has-paddle-module", activeZones.has("back-paddles"));
};

const ensureVisibleShell = () => {
    const current = selectedInput(shellInputs);
    if (current && !current.closest("[hidden]")) {
        return;
    }

    const original = shellInputs.find((input) => input.value === "original" && !input.closest("[hidden]"));
    if (original) {
        original.checked = true;
    }
};

const updateOptionVisibility = () => {
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

    shellCards.forEach((card) => {
        const models = String(card.dataset.models || "").split(/\s+/).filter(Boolean);
        card.hidden = model === "" || !models.includes(model);
    });
    ensureVisibleShell();

    if (offerPlaceholder) {
        offerPlaceholder.hidden = model !== "";
    }
    if (shellPlaceholder) {
        shellPlaceholder.hidden = model !== "";
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
    const source = selectedInput(sourceInputs);
    const shell = selectedInput(shellInputs);
    const offers = inputLabels(offerInputs);
    const extras = inputLabels(extraInputs);
    const selectedOffers = selectedInputs(offerInputs);
    const offerTotalCents = selectedOffers.reduce((total, input) => total + Number(input.dataset.priceCents || 0), 0);
    const sourcePriceCents = sourcePriceForModel(source, model?.value || "");
    const shellPriceCents = Number(shell?.dataset.priceCents || 0);
    const totalCents = sourcePriceCents + shellPriceCents + offerTotalCents;

    if (stage) {
        stage.dataset.controllerModel = model?.value || "";
        stage.dataset.shellVisual = shell?.dataset.shellVisual || "original";
        stage.style.setProperty("--controller-shell-color", shell?.dataset.shellColor || "#f5f5f2");
        stage.setAttribute(
            "aria-label",
            model
                ? `Drehbare Live-Vorschau ${model.dataset.label || model.value} mit ${offers.length} gewählten Upgrades`
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
                ? "Vorder- und Rückseite drehen, Optik und Upgrades wählen"
                : `${offers.length} ${offers.length === 1 ? "Upgrade" : "Upgrades"} dynamisch dargestellt`;
    }

    if (summaryModel) {
        summaryModel.textContent = model?.dataset.label || "Bitte auswählen";
    }
    if (summarySource) {
        const price = sourcePriceCents > 0 ? ` · ${formatPrice(sourcePriceCents)}` : "";
        summarySource.textContent = source ? `${source.dataset.label || source.value}${price}` : "Bitte auswählen";
    }
    if (summaryShell) {
        const price = shellPriceCents > 0 ? ` · +${formatPrice(shellPriceCents)}` : "";
        summaryShell.textContent = shell ? `${shell.dataset.label || shell.value}${price}` : "Bitte auswählen";
    }
    if (summaryOffers) {
        summaryOffers.textContent = offers.length > 0 ? offers.join(", ") : "Noch kein Upgrade gewählt";
    }
    if (summaryExtras) {
        summaryExtras.textContent = extras.length > 0 ? extras.join(", ") : "Keine Zusatzangabe";
    }
    if (summaryPrice) {
        summaryPrice.textContent = formatPrice(totalCents);
    }
    if (priceKicker) {
        priceKicker.textContent = "Voraussichtliche Gesamtsumme";
    }
    if (priceNote) {
        priceNote.textContent = offers.length > 0
            ? "0 % USt. · Controller, Gehäuse, Material, Einbau und Test laut Auswahl."
            : "Wählen Sie mindestens ein Upgrade-Paket aus.";
    }
    if (selectionError && offers.length > 0) {
        selectionError.hidden = true;
    }

    updateZones();
};

if (form) {
    extraInputs.forEach((input) => input.addEventListener("change", updateSummary));
    sourceInputs.forEach((input) => input.addEventListener("change", updateSummary));
    shellInputs.forEach((input) => input.addEventListener("change", updateSummary));

    modelInputs.forEach((input) => {
        input.addEventListener("change", () => {
            updateOptionVisibility();
            updateSummary();
        });
    });

    offerInputs.forEach((input) => {
        input.addEventListener("change", () => {
            applyOfferExclusivity(input);
            if (input.checked && input.dataset.zone === "back-paddles") {
                setControllerView(true);
            }
            updateSummary();
        });
    });

    viewToggle?.addEventListener("click", () => {
        setControllerView(!stage?.classList.contains("is-showing-back"));
    });

    form.addEventListener("submit", (event) => {
        const model = selectedInput(modelInputs);
        const source = selectedInput(sourceInputs);
        const offers = selectedInputs(offerInputs);

        if (!model) {
            event.preventDefault();
            modelInputs[0]?.focus();
            modelInputs[0]?.reportValidity();
            return;
        }
        if (!source) {
            event.preventDefault();
            sourceInputs[0]?.focus();
            sourceInputs[0]?.reportValidity();
            return;
        }
        if (offers.length === 0) {
            event.preventDefault();
            if (selectionError) {
                selectionError.hidden = false;
            }
            offerInputs.find((input) => !input.closest("[hidden]"))?.focus();
            return;
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            const firstInvalid = form.querySelector(":invalid");
            firstInvalid?.focus();
            firstInvalid?.reportValidity();
        }
    });

    updateOptionVisibility();
    updateSummary();
}

const configurator = document.querySelector("[data-controller-configurator]");
const form = configurator?.querySelector("[data-controller-form]");
const stage = configurator?.querySelector("[data-controller-stage]");
const modelInputs = Array.from(configurator?.querySelectorAll('input[name="model"]') || []);
const issueInputs = Array.from(configurator?.querySelectorAll('input[name="issues[]"]') || []);
const extraInputs = Array.from(configurator?.querySelectorAll('input[name="extras[]"]') || []);
const issueError = configurator?.querySelector("[data-issues-error]");
const modelBadge = configurator?.querySelector("[data-model-badge]");
const summaryModel = configurator?.querySelector("[data-summary-model]");
const summaryIssues = configurator?.querySelector("[data-summary-issues]");
const summaryExtras = configurator?.querySelector("[data-summary-extras]");

const selectedInput = (inputs) => inputs.find((input) => input.checked);
const selectedInputs = (inputs) => inputs.filter((input) => input.checked);
const inputLabels = (inputs) => selectedInputs(inputs).map((input) => input.dataset.label || input.value);

const updateZones = () => {
    const activeZones = new Set(selectedInputs(issueInputs).map((input) => input.dataset.zone || ""));

    configurator?.querySelectorAll("[data-controller-zone]").forEach((zone) => {
        zone.classList.toggle("is-active", activeZones.has(zone.dataset.controllerZone || ""));
    });
};

const updateSummary = () => {
    const model = selectedInput(modelInputs);
    const issues = inputLabels(issueInputs);
    const extras = inputLabels(extraInputs);

    if (stage) {
        stage.dataset.controllerModel = model?.value || "";
    }

    if (modelBadge) {
        modelBadge.textContent = model?.dataset.label || "Noch kein Modell gewählt";
    }

    if (summaryModel) {
        summaryModel.textContent = model?.dataset.label || "Bitte auswählen";
    }

    if (summaryIssues) {
        summaryIssues.textContent = issues.length > 0 ? issues.join(", ") : "Noch nichts markiert";
    }

    if (summaryExtras) {
        summaryExtras.textContent = extras.length > 0 ? extras.join(", ") : "Keine Zusatzangabe";
    }

    if (issueError && issues.length > 0) {
        issueError.hidden = true;
    }

    updateZones();
};

if (form) {
    [...modelInputs, ...issueInputs, ...extraInputs].forEach((input) => {
        input.addEventListener("change", updateSummary);
    });

    form.addEventListener("submit", (event) => {
        const model = selectedInput(modelInputs);
        const issues = selectedInputs(issueInputs);

        if (!model) {
            event.preventDefault();
            modelInputs[0]?.focus();
            modelInputs[0]?.reportValidity();
            return;
        }

        if (issues.length === 0) {
            event.preventDefault();
            if (issueError) {
                issueError.hidden = false;
            }
            issueInputs[0]?.focus();
        }
    });

    updateSummary();
}

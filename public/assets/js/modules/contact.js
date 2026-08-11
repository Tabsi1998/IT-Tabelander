const contactTopicSelect = document.querySelector('select[name="audience"]');
const contactServiceSelect = document.querySelector('select[name="service"]');

const contactTopicGroups = {
    "Reparatur und Diagnose": "reparatur",
    "Einrichtung und Systempflege": "systeme",
    "Netzwerk und WLAN": "netzwerk",
    "Sicherheit und Virenprüfung": "sicherheit",
    "Gaming-Hardware": "gaming",
};

const updateContactServiceOptions = () => {
    if (!contactTopicSelect || !contactServiceSelect) {
        return;
    }

    const activeGroup = contactTopicGroups[contactTopicSelect.value] || "";
    let selectedOptionHidden = false;

    Array.from(contactServiceSelect.options).forEach((option) => {
        if (option.value === "") {
            option.hidden = false;
            return;
        }

        const groups = String(option.dataset.serviceGroups || "").split(/\s+/);
        const isVisible = activeGroup === "" || groups.includes(activeGroup);
        option.hidden = !isVisible;

        if (option.selected && !isVisible) {
            selectedOptionHidden = true;
        }
    });

    if (selectedOptionHidden) {
        contactServiceSelect.value = "";
    }
};

contactTopicSelect?.addEventListener("change", updateContactServiceOptions);
updateContactServiceOptions();


document.querySelector('[aria-invalid="true"]')?.focus();

const accordion = document.querySelector("[data-service-accordion]");
const visual = document.querySelector("[data-service-visual]");
const visualImage = visual?.querySelector("img");
const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

const updateVisual = (detail) => {
    if (!visual || !visualImage || !detail.open) {
        return;
    }

    const applySource = () => {
        if (detail.dataset.imageSrc) {
            visualImage.src = detail.dataset.imageSrc;
        }

        if (detail.dataset.imageSrcset) {
            visualImage.srcset = detail.dataset.imageSrcset;
        }

        visualImage.alt = detail.dataset.imageAlt || "";
        visual.classList.remove("is-switching");
    };

    if (reducedMotion.matches) {
        applySource();
        return;
    }

    visual.classList.add("is-switching");
    window.setTimeout(applySource, 140);
};

accordion?.querySelectorAll("[data-service-detail]").forEach((detail) => {
    detail.addEventListener("toggle", () => updateVisual(detail));
});

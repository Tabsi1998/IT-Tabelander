const reducedMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");

const serviceCarousel = document.querySelector("[data-service-carousel]");
const serviceInteractiveRegion = serviceCarousel?.closest(".services-carousel-shell");
const serviceTrack = document.querySelector("[data-service-track]");
const serviceCards = serviceTrack ? Array.from(serviceTrack.querySelectorAll("[data-service-card]")) : [];
const serviceSliderButtons = document.querySelectorAll("[data-service-slide]");
const serviceFilterButtons = document.querySelectorAll("[data-service-filter]");
const serviceAutoplayButton = document.querySelector("[data-service-autoplay]");

let serviceIndex = 0;
let serviceCardsPerView = 4;
let serviceSliderTimer = null;
let serviceAutoplayPaused = reducedMotionQuery.matches;

const updateServiceAutoplayButton = () => {
    if (!serviceAutoplayButton) {
        return;
    }

    serviceAutoplayButton.setAttribute("aria-pressed", String(serviceAutoplayPaused));
    serviceAutoplayButton.textContent = serviceAutoplayPaused ? "Start" : "Pause";
    serviceAutoplayButton.setAttribute("aria-label", serviceAutoplayPaused ? "Automatischen Leistungswechsel starten" : "Automatischen Leistungswechsel pausieren");
    serviceAutoplayButton.disabled = reducedMotionQuery.matches || serviceMaxIndex() <= 0;
    serviceCarousel?.setAttribute("aria-live", serviceAutoplayPaused ? "polite" : "off");
};

const getServiceCardsPerView = () => {
    if (window.matchMedia("(max-width: 700px)").matches) {
        return 1;
    }

    if (window.matchMedia("(max-width: 1100px)").matches) {
        return 2;
    }

    return 4;
};

const visibleServiceCards = () => serviceCards.filter((card) => !card.hidden);

const serviceMaxIndex = () => Math.max(0, visibleServiceCards().length - serviceCardsPerView);

const updateServiceSlider = () => {
    const visibleCards = visibleServiceCards();

    if (!serviceTrack || visibleCards.length === 0) {
        return;
    }

    serviceCardsPerView = getServiceCardsPerView();
    serviceCardsPerView = Math.min(serviceCardsPerView, visibleCards.length);
    serviceTrack.style.setProperty("--cards-per-view", String(serviceCardsPerView));

    const maxIndex = serviceMaxIndex();
    if (serviceIndex > maxIndex) {
        serviceIndex = 0;
    }

    const firstCard = visibleCards[0];
    const trackStyles = window.getComputedStyle(serviceTrack);
    const gap = Number.parseFloat(trackStyles.columnGap || trackStyles.gap || "16");
    const cardWidth = firstCard.getBoundingClientRect().width;
    const translate = serviceIndex * (cardWidth + gap);
    serviceTrack.style.transform = `translateX(-${translate}px)`;

    visibleCards.forEach((card, index) => {
        const isInView = index >= serviceIndex && index < serviceIndex + serviceCardsPerView;
        card.tabIndex = isInView ? 0 : -1;
        card.setAttribute("aria-hidden", String(!isInView));
    });

    serviceSliderButtons.forEach((button) => {
        button.disabled = maxIndex === 0;
    });
};

const applyServiceFilter = (filter) => {
    serviceCards.forEach((card) => {
        const groups = String(card.dataset.serviceGroups || "all").split(/\s+/);
        const isVisible = filter === "all" || groups.includes(filter);
        card.hidden = !isVisible;
        card.tabIndex = isVisible ? 0 : -1;
    });

    serviceFilterButtons.forEach((button) => {
        const isActive = button.dataset.serviceFilter === filter;
        button.classList.toggle("is-active", isActive);
        button.setAttribute("aria-pressed", String(isActive));
    });

    serviceIndex = 0;
    updateServiceSlider();
};

const stepServiceSlider = (direction) => {
    const maxIndex = serviceMaxIndex();
    if (maxIndex <= 0) {
        return;
    }

    if (direction > 0) {
        serviceIndex = serviceIndex >= maxIndex ? 0 : serviceIndex + 1;
    } else {
        serviceIndex = serviceIndex <= 0 ? maxIndex : serviceIndex - 1;
    }

    updateServiceSlider();
};

const stopServiceSlider = () => {
    if (serviceSliderTimer) {
        window.clearInterval(serviceSliderTimer);
        serviceSliderTimer = null;
    }
};

const startServiceSlider = () => {
    if (!serviceCarousel || reducedMotionQuery.matches || serviceAutoplayPaused || serviceMaxIndex() <= 0) {
        return;
    }

    stopServiceSlider();
    serviceSliderTimer = window.setInterval(() => stepServiceSlider(1), 4600);
};

if (serviceCarousel && serviceTrack && serviceCards.length > 0) {
    updateServiceSlider();
    updateServiceAutoplayButton();
    startServiceSlider();

    serviceAutoplayButton?.addEventListener("click", () => {
        serviceAutoplayPaused = !serviceAutoplayPaused;
        updateServiceAutoplayButton();

        if (serviceAutoplayPaused) {
            stopServiceSlider();
        } else {
            startServiceSlider();
        }
    });

    serviceSliderButtons.forEach((button) => {
        button.addEventListener("click", () => {
            stopServiceSlider();
            stepServiceSlider(button.dataset.serviceSlide === "next" ? 1 : -1);
            startServiceSlider();
        });
    });

    serviceFilterButtons.forEach((button) => {
        button.addEventListener("click", () => {
            stopServiceSlider();
            applyServiceFilter(button.dataset.serviceFilter || "all");
            startServiceSlider();
        });
    });

    serviceInteractiveRegion?.addEventListener("mouseenter", stopServiceSlider);
    serviceInteractiveRegion?.addEventListener("mouseleave", startServiceSlider);
    serviceInteractiveRegion?.addEventListener("focusin", stopServiceSlider);
    serviceInteractiveRegion?.addEventListener("focusout", (event) => {
        if (!serviceInteractiveRegion.contains(event.relatedTarget)) {
            startServiceSlider();
        }
    });

    let serviceResizeFrame = 0;
    window.addEventListener("resize", () => {
        if (serviceResizeFrame) {
            window.cancelAnimationFrame(serviceResizeFrame);
        }

        serviceResizeFrame = window.requestAnimationFrame(() => {
            updateServiceSlider();
            startServiceSlider();
        });
    });
}


const handleServiceReducedMotionChange = () => {
    if (reducedMotionQuery.matches) {
        serviceAutoplayPaused = true;
        stopServiceSlider();
    }

    updateServiceAutoplayButton();
};

if (typeof reducedMotionQuery.addEventListener === "function") {
    reducedMotionQuery.addEventListener("change", handleServiceReducedMotionChange);
} else if (typeof reducedMotionQuery.addListener === "function") {
    reducedMotionQuery.addListener(handleServiceReducedMotionChange);
}

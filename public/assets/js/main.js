const navToggle = document.querySelector(".nav-toggle");
const siteNav = document.querySelector(".site-nav");
const themeRoot = document.documentElement;
const themeToggle = document.querySelector("[data-theme-toggle]");
const themeMeta = document.querySelector('meta[name="theme-color"]');
const themeLogos = document.querySelectorAll("[data-theme-logo]");
const themeStorageKey = "it-tabelander-theme";
const darkThemeQuery = window.matchMedia("(prefers-color-scheme: dark)");
const cookieNotice = document.querySelector("[data-cookie-notice]");
const cookiePanel = document.querySelector("[data-cookie-panel]");
const cookieAcceptButton = document.querySelector("[data-cookie-accept]");
const cookieRejectButton = document.querySelector("[data-cookie-reject]");
const cookieSaveButton = document.querySelector("[data-cookie-save]");
const cookieResetButtons = document.querySelectorAll("[data-cookie-reset]");
const cookieAnalyticsCheckbox = document.querySelector("[data-cookie-analytics]");
const analyticsConsentStorageKey = "it-tabelander-analytics-consent";
const legacyCookieNoticeStorageKey = "it-tabelander-cookie-notice";
const analyticsMeasurementId = window.IT_TABELANDER_ANALYTICS_ID || "";
const consentDurationDays = 30;
const reducedMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");
const contactTopicSelect = document.querySelector('select[name="audience"]');
const contactServiceSelect = document.querySelector('select[name="service"]');

const resolvedTheme = (choice) => {
    if (choice === "light" || choice === "dark") {
        return choice;
    }

    return darkThemeQuery.matches ? "dark" : "light";
};

const updateThemeUi = (choice) => {
    themeRoot.dataset.themeChoice = choice;
    const resolved = resolvedTheme(choice);
    themeRoot.dataset.resolvedTheme = resolved;

    if (themeMeta) {
        themeMeta.setAttribute("content", resolved === "dark" ? "#08141d" : "#f4f7fb");
    }

    themeLogos.forEach((logo) => {
        const nextSource = resolved === "dark" ? logo.dataset.logoDarkSrc : logo.dataset.logoLightSrc;
        if (nextSource && logo.getAttribute("src") !== nextSource) {
            logo.setAttribute("src", nextSource);
        }
    });

    if (themeToggle) {
        const nextMode = resolved === "dark" ? "helles" : "dunkles";
        const autoSuffix = choice === "auto"
            ? " Standard ist automatisch nach System."
            : " Doppelklick setzt wieder auf automatisch.";
        themeToggle.setAttribute("aria-label", `Zum ${nextMode} Design wechseln.${autoSuffix}`);
        themeToggle.setAttribute("title", `Zum ${nextMode} Design wechseln.${autoSuffix}`);
    }
};

const applyThemeChoice = (choice, persist = false) => {
    if (choice === "light" || choice === "dark") {
        themeRoot.dataset.theme = choice;
    } else {
        delete themeRoot.dataset.theme;
    }

    updateThemeUi(choice);

    if (!persist) {
        return;
    }

    try {
        if (choice === "auto") {
            localStorage.removeItem(themeStorageKey);
        } else {
            localStorage.setItem(themeStorageKey, choice);
        }
    } catch (error) {
        // Ignore storage errors and keep the active theme for the current session.
    }
};

const initialThemeChoice = themeRoot.dataset.themeChoice || "auto";
applyThemeChoice(initialThemeChoice);

if (themeToggle) {
    themeToggle.addEventListener("click", () => {
        const currentResolvedTheme = themeRoot.dataset.resolvedTheme || resolvedTheme(themeRoot.dataset.themeChoice || "auto");
        const nextChoice = currentResolvedTheme === "dark" ? "light" : "dark";
        applyThemeChoice(nextChoice, true);
    });

    themeToggle.addEventListener("dblclick", () => {
        applyThemeChoice("auto", true);
    });
}

const handleThemeModeChange = () => {
    if ((themeRoot.dataset.themeChoice || "auto") === "auto") {
        applyThemeChoice("auto");
    }
};

if (typeof darkThemeQuery.addEventListener === "function") {
    darkThemeQuery.addEventListener("change", handleThemeModeChange);
} else if (typeof darkThemeQuery.addListener === "function") {
    darkThemeQuery.addListener(handleThemeModeChange);
}

if (navToggle && siteNav) {
    const closeNavigation = (restoreFocus = false) => {
        navToggle.setAttribute("aria-expanded", "false");
        navToggle.setAttribute("aria-label", "Navigation öffnen");
        siteNav.classList.remove("is-open");

        if (restoreFocus) {
            navToggle.focus();
        }
    };

    navToggle.addEventListener("click", () => {
        const isExpanded = navToggle.getAttribute("aria-expanded") === "true";
        navToggle.setAttribute("aria-expanded", String(!isExpanded));
        navToggle.setAttribute("aria-label", isExpanded ? "Navigation öffnen" : "Navigation schließen");
        siteNav.classList.toggle("is-open", !isExpanded);

        if (!isExpanded) {
            siteNav.querySelector("a")?.focus();
        }
    });

    siteNav.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", () => closeNavigation());
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && navToggle.getAttribute("aria-expanded") === "true") {
            event.preventDefault();
            closeNavigation(true);
        }
    });
}

const revealItems = document.querySelectorAll("[data-reveal]");

if (revealItems.length > 0 && !reducedMotionQuery.matches) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add("is-visible");
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.18,
    });

    revealItems.forEach((item, index) => {
        item.style.transitionDelay = `${(index % 4) * 70}ms`;
        observer.observe(item);
    });
} else {
    revealItems.forEach((item) => item.classList.add("is-visible"));
}

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

const reviewTrack = document.getElementById("reviews-track");
const reviewFootnote = document.getElementById("reviews-footnote");
const sliderButtons = document.querySelectorAll("[data-slide]");
const reviewAutoplayButton = document.querySelector("[data-review-autoplay]");
const reviewEndpoint = document.body?.dataset.reviewsUrl || "/reviews.php";

let currentSlide = 0;
let slideCount = reviewTrack ? reviewTrack.children.length : 0;
let slideTimer = null;
let reviewAutoplayPaused = reducedMotionQuery.matches;

const updateReviewAutoplayButton = () => {
    if (!reviewAutoplayButton) {
        return;
    }

    reviewAutoplayButton.setAttribute("aria-pressed", String(reviewAutoplayPaused));
    reviewAutoplayButton.textContent = reviewAutoplayPaused ? "Start" : "Pause";
    reviewAutoplayButton.setAttribute("aria-label", reviewAutoplayPaused ? "Automatischen Bewertungswechsel starten" : "Automatischen Bewertungswechsel pausieren");
    reviewAutoplayButton.disabled = reducedMotionQuery.matches || slideCount <= 1;
    reviewTrack?.parentElement?.setAttribute("aria-live", reviewAutoplayPaused ? "polite" : "off");
};

const updateSlider = () => {
    if (!reviewTrack || slideCount === 0) {
        return;
    }

    reviewTrack.style.transform = `translateX(-${currentSlide * 100}%)`;
    Array.from(reviewTrack.children).forEach((slide, index) => {
        const isActive = index === currentSlide;
        slide.setAttribute("aria-hidden", String(!isActive));
        slide.toggleAttribute("inert", !isActive);
    });
};

const changeSlide = (direction) => {
    if (slideCount <= 1) {
        return;
    }

    currentSlide = (currentSlide + direction + slideCount) % slideCount;
    updateSlider();
};

const startSlider = () => {
    if (slideCount <= 1 || reducedMotionQuery.matches || reviewAutoplayPaused) {
        return;
    }

    stopSlider();
    slideTimer = window.setInterval(() => changeSlide(1), 7000);
};

const stopSlider = () => {
    if (slideTimer) {
        window.clearInterval(slideTimer);
        slideTimer = null;
    }
};

sliderButtons.forEach((button) => {
    button.addEventListener("click", () => {
        stopSlider();
        changeSlide(button.dataset.slide === "next" ? 1 : -1);
        startSlider();
    });
});

updateReviewAutoplayButton();

reviewAutoplayButton?.addEventListener("click", () => {
    reviewAutoplayPaused = !reviewAutoplayPaused;
    updateReviewAutoplayButton();

    if (reviewAutoplayPaused) {
        stopSlider();
    } else {
        startSlider();
    }
});

const reviewInteractiveRegion = reviewTrack?.closest(".reviews-shell");
reviewInteractiveRegion?.addEventListener("mouseenter", stopSlider);
reviewInteractiveRegion?.addEventListener("mouseleave", startSlider);
reviewInteractiveRegion?.addEventListener("focusin", stopSlider);
reviewInteractiveRegion?.addEventListener("focusout", (event) => {
    if (!reviewInteractiveRegion.contains(event.relatedTarget)) {
        startSlider();
    }
});

const escapeHtml = (value) => String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");

const renderReviewSlides = (reviews) => {
    if (!reviewTrack || !Array.isArray(reviews) || reviews.length === 0) {
        return;
    }

    reviewTrack.innerHTML = reviews.map((review) => {
        const author = escapeHtml(review.author || "Google-Bewertung");
        const rating = escapeHtml(review.rating ? `${review.rating} / 5` : "Bewertung");
        const published = escapeHtml(review.relativeTime || review.source || "");
        const reviewText = escapeHtml(review.text || "Keine Beschreibung vorhanden.");
        const reviewUrl = typeof review.url === "string" ? review.url : "";
        const link = reviewUrl ? `<a href="${encodeURI(reviewUrl)}" target="_blank" rel="noreferrer">Auf Google ansehen</a>` : "";

        return `
            <article class="review-slide">
                <p class="review-rating">${rating}</p>
                <h3>${author}</h3>
                <p>${reviewText}</p>
                <div class="review-meta">
                    <span>${published}</span>
                    ${link}
                </div>
            </article>
        `;
    }).join("");

    slideCount = reviewTrack.children.length;
    currentSlide = 0;
    updateSlider();
    updateReviewAutoplayButton();
    stopSlider();
    startSlider();
};

if (reviewTrack) {
    fetch(reviewEndpoint, {
        headers: {
            Accept: "application/json",
        },
    }).then((response) => {
        if (!response.ok) {
            throw new Error("reviews-unavailable");
        }

        return response.json();
    }).then((payload) => {
        if (payload?.message && reviewFootnote) {
            reviewFootnote.textContent = payload.message;
        }

        const hasReviews = Array.isArray(payload?.reviews) && payload.reviews.length > 0;
        document.querySelectorAll("[data-slide]").forEach((button) => {
            button.disabled = !hasReviews;
        });

        if (hasReviews) {
            renderReviewSlides(payload.reviews);
        }
    }).catch(() => {
        if (reviewFootnote) {
            reviewFootnote.textContent = "Bewertungen konnten aktuell nicht geladen werden. Die restliche Website bleibt davon unberührt.";
        }
    });
}

const contactTopicGroups = {
    "Reparatur und Diagnose": "reparatur",
    "Einrichtung und Systempflege": "systeme",
    "Netzwerk und WLAN": "netzwerk",
    "Sicherheit und Virenprüfung": "sicherheit",
    "Server und Betreuung": "systeme",
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

let cookieDialogOpener = null;

const cookieFocusableElements = () => cookiePanel
    ? Array.from(cookiePanel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'))
    : [];

const setCookieNoticeVisibility = (visible) => {
    if (!cookieNotice) {
        return;
    }

    if (visible) {
        cookieDialogOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }

    cookieNotice.hidden = !visible;
    cookieNotice.classList.toggle("is-visible", visible);

    if (visible) {
        window.setTimeout(() => {
            const focusTarget = cookieRejectButton || cookieSaveButton || cookieAcceptButton || cookiePanel;
            focusTarget?.focus?.();
        }, 80);
    } else if (cookieDialogOpener?.isConnected) {
        cookieDialogOpener.focus();
        cookieDialogOpener = null;
    }
};

let analyticsLoaded = false;

const consentModeUpdate = (analyticsStorage) => {
    if (typeof window.gtag !== "function") {
        return;
    }

    window.gtag("consent", "update", {
        analytics_storage: analyticsStorage,
        ad_storage: "denied",
        ad_user_data: "denied",
        ad_personalization: "denied",
        functionality_storage: "granted",
        security_storage: "granted",
    });
};

const loadGoogleAnalytics = () => {
    if (!analyticsMeasurementId || analyticsLoaded) {
        return;
    }

    analyticsLoaded = true;
    consentModeUpdate("granted");

    const script = document.createElement("script");
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(analyticsMeasurementId)}`;
    document.head.appendChild(script);

    if (typeof window.gtag === "function") {
        window.gtag("js", new Date());
        window.gtag("config", analyticsMeasurementId, {
            anonymize_ip: true,
        });
    }
};

const consentExpiresAt = () => Date.now() + consentDurationDays * 24 * 60 * 60 * 1000;

const normalizeConsentChoice = (value) => {
    if (value === "accepted") {
        return {
            analytics: true,
            expiresAt: consentExpiresAt(),
        };
    }

    if (value === "declined") {
        return {
            analytics: false,
            expiresAt: consentExpiresAt(),
        };
    }

    try {
        const parsed = JSON.parse(value || "{}");
        const expiresAt = Number(parsed.expiresAt || 0);

        if (!Number.isFinite(expiresAt) || expiresAt <= Date.now()) {
            return null;
        }

        return {
            analytics: parsed.analytics === true,
            expiresAt,
        };
    } catch (error) {
        return null;
    }
};

const getStoredConsentChoice = () => {
    try {
        const rawChoice = localStorage.getItem(analyticsConsentStorageKey) || "";
        const normalizedChoice = normalizeConsentChoice(rawChoice);

        if (normalizedChoice && (rawChoice === "accepted" || rawChoice === "declined")) {
            localStorage.setItem(analyticsConsentStorageKey, JSON.stringify({
                essential: true,
                analytics: normalizedChoice.analytics,
                expiresAt: normalizedChoice.expiresAt,
            }));
        }

        return normalizedChoice;
    } catch (error) {
        return null;
    }
};

const setAnalyticsConsent = (choice) => {
    const nextChoice = {
        essential: true,
        analytics: choice === "accepted",
        expiresAt: consentExpiresAt(),
    };

    try {
        localStorage.setItem(analyticsConsentStorageKey, JSON.stringify(nextChoice));
        localStorage.removeItem(legacyCookieNoticeStorageKey);
    } catch (error) {
        // Keep the choice for the current page even if localStorage is unavailable.
    }

    if (nextChoice.analytics) {
        loadGoogleAnalytics();
    } else {
        consentModeUpdate("denied");
    }

    setCookieNoticeVisibility(false);
};

if (cookieNotice) {
    const storedConsent = getStoredConsentChoice();

    if (storedConsent?.analytics === true) {
        loadGoogleAnalytics();
    } else if (storedConsent?.analytics === false) {
        consentModeUpdate("denied");
    } else {
        window.requestAnimationFrame(() => setCookieNoticeVisibility(true));
    }

    cookieAcceptButton?.addEventListener("click", () => {
        setAnalyticsConsent("accepted");
    });

    cookieRejectButton?.addEventListener("click", () => {
        setAnalyticsConsent("declined");
    });

    cookieSaveButton?.addEventListener("click", () => {
        setAnalyticsConsent(cookieAnalyticsCheckbox?.checked ? "accepted" : "declined");
    });

    cookiePanel?.addEventListener("click", (event) => {
        event.stopPropagation();
    });

    cookieNotice.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            event.preventDefault();
            setAnalyticsConsent("declined");
            return;
        }

        if (event.key !== "Tab") {
            return;
        }

        const focusable = cookieFocusableElements();
        if (focusable.length === 0) {
            event.preventDefault();
            cookiePanel?.focus?.();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}

cookieResetButtons.forEach((button) => {
    button.addEventListener("click", () => {
        try {
            localStorage.removeItem(analyticsConsentStorageKey);
            localStorage.removeItem(legacyCookieNoticeStorageKey);
        } catch (error) {
            // Ignore storage errors and show the notice for the current page.
        }

        consentModeUpdate("denied");
        if (cookieAnalyticsCheckbox) {
            cookieAnalyticsCheckbox.checked = false;
        }
        setCookieNoticeVisibility(true);
    });
});

if (window.location.hash === "#kontakt" && siteNav) {
    siteNav.classList.remove("is-open");
}

const handleReducedMotionChange = () => {
    if (reducedMotionQuery.matches) {
        serviceAutoplayPaused = true;
        reviewAutoplayPaused = true;
        stopServiceSlider();
        stopSlider();
    }

    updateServiceAutoplayButton();
    updateReviewAutoplayButton();
};

if (typeof reducedMotionQuery.addEventListener === "function") {
    reducedMotionQuery.addEventListener("change", handleReducedMotionChange);
} else if (typeof reducedMotionQuery.addListener === "function") {
    reducedMotionQuery.addListener(handleReducedMotionChange);
}

document.querySelector('[aria-invalid="true"]')?.focus();

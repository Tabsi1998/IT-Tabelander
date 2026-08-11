const cookieNotice = document.querySelector("[data-cookie-notice]");
const cookiePanel = document.querySelector("[data-cookie-panel]");
const cookieAcceptButton = document.querySelector("[data-cookie-accept]");
const cookieRejectButton = document.querySelector("[data-cookie-reject]");
const cookieSaveButton = document.querySelector("[data-cookie-save]");
const cookieResetButtons = document.querySelectorAll("[data-cookie-reset]");
const cookieAnalyticsCheckbox = document.querySelector("[data-cookie-analytics]");
const analyticsConsentStorageKey = "it-tabelander-analytics-consent";
const legacyCookieNoticeStorageKey = "it-tabelander-cookie-notice";
const analyticsMeasurementId = document.querySelector('meta[name="it-tabelander-analytics-id"]')?.content || "";
const consentDurationDays = 30;

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

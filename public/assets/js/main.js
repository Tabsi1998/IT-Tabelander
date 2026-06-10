const navToggle = document.querySelector(".nav-toggle");
const siteNav = document.querySelector(".site-nav");
const themeRoot = document.documentElement;
const themeToggle = document.querySelector("[data-theme-toggle]");
const themeMeta = document.querySelector('meta[name="theme-color"]');
const themeLogos = document.querySelectorAll("[data-theme-logo]");
const themeStorageKey = "it-tabelander-theme";
const darkThemeQuery = window.matchMedia("(prefers-color-scheme: dark)");
const cookieNotice = document.querySelector("[data-cookie-notice]");
const cookieAcknowledgeButton = document.querySelector("[data-cookie-ack]");
const cookieNoticeStorageKey = "it-tabelander-cookie-notice";
const reducedMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");

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
    navToggle.addEventListener("click", () => {
        const isExpanded = navToggle.getAttribute("aria-expanded") === "true";
        navToggle.setAttribute("aria-expanded", String(!isExpanded));
        siteNav.classList.toggle("is-open", !isExpanded);
    });

    siteNav.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", () => {
            navToggle.setAttribute("aria-expanded", "false");
            siteNav.classList.remove("is-open");
        });
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
const serviceTrack = document.querySelector("[data-service-track]");
const serviceCards = serviceTrack ? Array.from(serviceTrack.querySelectorAll("[data-service-card]")) : [];
const serviceSliderButtons = document.querySelectorAll("[data-service-slide]");
const serviceFilterButtons = document.querySelectorAll("[data-service-filter]");

let serviceIndex = 0;
let serviceCardsPerView = 4;
let serviceSliderTimer = null;

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
    if (!serviceCarousel || reducedMotionQuery.matches || serviceMaxIndex() <= 0) {
        return;
    }

    stopServiceSlider();
    serviceSliderTimer = window.setInterval(() => stepServiceSlider(1), 4600);
};

if (serviceCarousel && serviceTrack && serviceCards.length > 0) {
    updateServiceSlider();
    startServiceSlider();

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

    serviceCarousel.addEventListener("mouseenter", stopServiceSlider);
    serviceCarousel.addEventListener("mouseleave", startServiceSlider);
    serviceCarousel.addEventListener("focusin", stopServiceSlider);
    serviceCarousel.addEventListener("focusout", (event) => {
        if (!serviceCarousel.contains(event.relatedTarget)) {
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
const reviewEndpoint = document.body?.dataset.reviewsUrl || "/reviews.php";

let currentSlide = 0;
let slideCount = reviewTrack ? reviewTrack.children.length : 0;
let slideTimer = null;

const updateSlider = () => {
    if (!reviewTrack || slideCount === 0) {
        return;
    }

    reviewTrack.style.transform = `translateX(-${currentSlide * 100}%)`;
};

const changeSlide = (direction) => {
    if (slideCount <= 1) {
        return;
    }

    currentSlide = (currentSlide + direction + slideCount) % slideCount;
    updateSlider();
};

const startSlider = () => {
    if (slideCount <= 1 || reducedMotionQuery.matches) {
        return;
    }

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

reviewTrack?.addEventListener("mouseenter", stopSlider);
reviewTrack?.addEventListener("mouseleave", startSlider);

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

        if (Array.isArray(payload?.reviews) && payload.reviews.length > 0) {
            renderReviewSlides(payload.reviews);
        }
    }).catch(() => {
        if (reviewFootnote) {
            reviewFootnote.textContent = "Bewertungen konnten aktuell nicht geladen werden. Die restliche Website bleibt davon unberührt.";
        }
    });
}

const setCookieNoticeVisibility = (visible) => {
    if (!cookieNotice) {
        return;
    }

    cookieNotice.hidden = !visible;
    cookieNotice.classList.toggle("is-visible", visible);
};

if (cookieNotice) {
    let isAcknowledged = false;

    try {
        isAcknowledged = localStorage.getItem(cookieNoticeStorageKey) === "acknowledged";
    } catch (error) {
        isAcknowledged = false;
    }

    if (!isAcknowledged) {
        window.requestAnimationFrame(() => setCookieNoticeVisibility(true));
    }

    cookieAcknowledgeButton?.addEventListener("click", () => {
        try {
            localStorage.setItem(cookieNoticeStorageKey, "acknowledged");
        } catch (error) {
            // Ignore storage errors and only hide the notice for the current page.
        }

        setCookieNoticeVisibility(false);
    });
}

if (window.location.hash === "#kontakt" && siteNav) {
    siteNav.classList.remove("is-open");
}

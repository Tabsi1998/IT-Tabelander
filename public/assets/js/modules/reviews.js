const reducedMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");

const reviewTrack = document.getElementById("reviews-track");
const reviewFootnote = document.getElementById("reviews-footnote");
const sliderButtons = document.querySelectorAll("[data-slide]");
const reviewAutoplayButton = document.querySelector("[data-review-autoplay]");
const reviewEndpoint = document.body?.dataset.reviewsUrl || "/reviews.php";

let currentSlide = 0;
let slideCount = reviewTrack ? reviewTrack.children.length : 0;
let slideTimer = null;
let reviewAutoplayPaused = reducedMotionQuery.matches || !reviewAutoplayButton;

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

const reviewInteractiveRegion = reviewTrack?.closest(".reviews-shell, .home-reviews");
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


const handleReviewReducedMotionChange = () => {
    if (reducedMotionQuery.matches) {
        reviewAutoplayPaused = true;
        stopSlider();
    }

    updateReviewAutoplayButton();
};

if (typeof reducedMotionQuery.addEventListener === "function") {
    reducedMotionQuery.addEventListener("change", handleReviewReducedMotionChange);
} else if (typeof reducedMotionQuery.addListener === "function") {
    reducedMotionQuery.addListener(handleReviewReducedMotionChange);
}

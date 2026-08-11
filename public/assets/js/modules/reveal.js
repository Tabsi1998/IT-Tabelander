const reducedMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");

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

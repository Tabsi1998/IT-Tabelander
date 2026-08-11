const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
const counters = document.querySelectorAll("[data-count-up]");
const formatter = new Intl.NumberFormat("de-AT");

const finishCounter = (counter) => {
    const target = Number.parseInt(counter.dataset.countUp || "0", 10);
    counter.textContent = formatter.format(Number.isFinite(target) ? target : 0);
};

const animateCounter = (counter) => {
    const target = Number.parseInt(counter.dataset.countUp || "0", 10);
    if (!Number.isFinite(target) || target <= 0 || reducedMotion.matches) {
        finishCounter(counter);
        return;
    }

    const duration = 900;
    let startTime = null;

    const tick = (timestamp) => {
        startTime ??= timestamp;
        const progress = Math.min((timestamp - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        counter.textContent = formatter.format(Math.round(target * eased));

        if (progress < 1) {
            window.requestAnimationFrame(tick);
        }
    };

    window.requestAnimationFrame(tick);
};

if (reducedMotion.matches || !("IntersectionObserver" in window)) {
    counters.forEach(finishCounter);
} else {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            animateCounter(entry.target);
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.45 });

    counters.forEach((counter) => observer.observe(counter));
}

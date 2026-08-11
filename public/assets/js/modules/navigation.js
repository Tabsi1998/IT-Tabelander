const navToggle = document.querySelector(".nav-toggle");
const siteNav = document.querySelector(".site-nav");

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


if (window.location.hash === "#kontakt" && siteNav) {
    siteNav.classList.remove("is-open");
}

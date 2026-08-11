(() => {
    const root = document.documentElement;

    try {
        const stored = localStorage.getItem("it-tabelander-theme");
        const choice = stored === "light" || stored === "dark" ? stored : "auto";

        if (choice === "light" || choice === "dark") {
            root.dataset.theme = choice;
        } else {
            delete root.dataset.theme;
        }

        root.dataset.themeChoice = choice;
        const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
        const resolved = choice === "auto" ? (prefersDark ? "dark" : "light") : choice;
        root.dataset.resolvedTheme = resolved;
        document.querySelector('meta[name="theme-color"]')?.setAttribute("content", resolved === "dark" ? "#08141d" : "#f4f7fb");
    } catch (error) {
        root.dataset.themeChoice = "auto";
    }

    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function gtag() {
        window.dataLayer.push(arguments);
    };
    window.gtag("consent", "default", {
        analytics_storage: "denied",
        ad_storage: "denied",
        ad_user_data: "denied",
        ad_personalization: "denied",
        functionality_storage: "granted",
        security_storage: "granted",
    });
})();

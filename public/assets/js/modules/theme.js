const themeRoot = document.documentElement;
const themeToggle = document.querySelector("[data-theme-toggle]");
const themeMeta = document.querySelector('meta[name="theme-color"]');
const themeLogos = document.querySelectorAll("[data-theme-logo]");
const themeStorageKey = "it-tabelander-theme";
const darkThemeQuery = window.matchMedia("(prefers-color-scheme: dark)");

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

// The services as shown until the backend answers, and when it has none yet.
// Texts from Design A; no prices - every job gets its own offer (#46).
export const DEFAULT_SERVICES = [
  {
    slug: "pc-reparatur", tab: "PC-Reparatur", image: "/assets/img/services/pc-laptop-reparatur-1200.webp",
    heading: "Strukturierte Diagnose statt Rätselraten",
    intro: "Wenn dein PC nicht mehr startet, überhitzt, abstürzt oder zu langsam geworden ist, gehe ich der Ursache systematisch auf den Grund. Erst die saubere Diagnose, dann die passende Reparatur.",
    bullets: ["Fehlerdiagnose und Hardware-Analyse", "Defekte Komponenten tauschen", "Kühlung, Überhitzung, Wärmeleitpaste",
      "Netzteil, Mainboard, Speicher", "Betriebssystemprobleme beheben", "Sinnvolle Aufrüstung nach Prüfung"],
    note: "Ob eine Reparatur wirtschaftlich sinnvoll ist, bespreche ich vorher ehrlich mit dir.",
    requestType: "repair",
  },
  {
    slug: "notebook-reparatur", tab: "Notebook", image: "/assets/img/services/pc-laptop-reparatur-1200.webp",
    heading: "Abhängig vom Modell, immer ehrlich",
    intro: "Notebooks sind kompakt gebaut, nicht alles lässt sich bei jedem Modell reparieren oder aufrüsten. Ich prüfe dein Gerät und sage dir klar, was möglich und sinnvoll ist.",
    bullets: ["Fehlersuche und Diagnose", "SSD-Tausch und RAM-Erweiterung", "Akkutausch", "Lüfter und Kühlung",
      "Wärmeleitpaste erneuern", "Weitere Reparaturen je nach Modell"],
    note: "Es wird keine Reparatur versprochen, die bei deinem Notebook technisch nicht möglich ist.",
    requestType: "repair",
  },
  {
    slug: "pc-aufruestung", tab: "Upgrades", image: "/assets/img/services/upgrades-systempflege-1200.webp",
    heading: "Upgrades, die wirklich etwas bringen",
    intro: "Ich prüfe vorab Kompatibilität und Sinnhaftigkeit und empfehle nur Upgrades, die für dein System spürbar etwas bringen.",
    bullets: ["RAM-Erweiterung", "SSD, NVMe, HDD", "Grafikkarte", "CPU-Upgrade", "Netzteil, Kühler, Lüfter", "WLAN und Netzwerk"],
    note: "Nicht jedes Gerät lässt sich beliebig aufrüsten.",
    requestType: "pc_upgrade",
  },
  {
    slug: "pc-bau", tab: "PC nach Wunsch", image: "/assets/img/services/service-overview-1200.webp",
    heading: "Individuelle PCs nach deinem Einsatzzweck",
    intro: "Ob Gaming, Office oder Workstation: dein System wird passend zusammengestellt, sauber gebaut und getestet.",
    bullets: ["Gaming-PC: hohe FPS, leiser Betrieb", "Office-PC: zuverlässig und sparsam", "Workstation für Profi-Software",
      "Multimedia-PC für das Wohnzimmer", "Kompatibilität vor Bestellung geprüft", "Sauberer Zusammenbau und Stresstest"],
    note: "Einsatzzweck und Budget nennen, den Rest stimmen wir gemeinsam ab.",
    requestType: "pc_build",
  },
  {
    slug: "konsolen-reparatur", tab: "Konsolen", image: "/assets/img/services/controller-konsolen-service-1200.webp",
    heading: "PlayStation, Xbox und Nintendo",
    intro: "Ich prüfe deine Konsole und melde mich mit einer ehrlichen Einschätzung. Welche Modelle unterstützt werden, wird laufend erweitert.",
    bullets: ["PlayStation 5 und weitere Modelle", "Xbox Series und weitere Modelle", "Nintendo Switch und weitere Modelle",
      "Reinigung und Wartung", "Fehlerdiagnose", "Weitere Reparaturen auf Anfrage"],
    note: "Eine Reparatur wird erst nach Prüfung des Geräts zugesagt.",
    requestType: "repair",
  },
  {
    slug: "controller-reparatur", tab: "Controller", image: "/assets/img/services/controller-konsolen-service-1200.webp",
    heading: "Controller-Reparatur und Umbau",
    intro: "Stick Drift, klemmende Tasten oder Ladeprobleme? Ich repariere Controller von PlayStation, Xbox und Nintendo und baue auf Wunsch Upgrades wie Hall-Effect-Sticks ein.",
    bullets: ["Stick Drift und Analogsticks", "Tasten und Steuerkreuz", "Trigger und Bumper", "Gehäuse und Schalen",
      "Ladeprobleme", "Umbauten, z. B. Hall-Effect"],
    note: "Keine Reparatur gilt als garantiert, bevor das Gerät geprüft wurde.",
    requestType: "controller_custom",
  },
];

const REQUEST_TYPES_BY_SLUG = Object.fromEntries(DEFAULT_SERVICES.map((item) => [item.slug, item.requestType]));

/** A service from the admin (#46), in the shape of the tabs. */
export function fromApi(service) {
  const fallback = DEFAULT_SERVICES.find((item) => item.slug === service.slug);
  const title = String(service.title || "").trim();
  const heading = String(service.heading || "").trim();
  return {
    slug: service.slug || title.toLowerCase(),
    tab: title,
    image: service.image_url || fallback?.image || "/assets/img/services/service-overview-1200.webp",
    heading: heading && heading !== title ? heading : (service.short_description || title),
    intro: service.long_description || service.short_description || "",
    bullets: Array.isArray(service.bullets) ? service.bullets.filter(Boolean) : [],
    note: "",
    requestType: REQUEST_TYPES_BY_SLUG[service.slug] || "other",
  };
}

export function servicesFrom(apiServices) {
  const list = Array.isArray(apiServices) ? apiServices.filter((item) => item && item.active !== false && item.title) : [];
  return list.length ? list.map(fromApi) : DEFAULT_SERVICES;
}

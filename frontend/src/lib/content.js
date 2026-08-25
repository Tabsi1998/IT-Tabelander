export const TRUST = [
  "CompTIA A+ zertifiziert",
  "Ausbildung am WIFI Tirol",
  "Netzwerkadministrator",
  "Systemadministrator",
  "Individuelle Beratung",
  "Transparente Abwicklung",
];

export const IMAGES = {
  hero: "/assets/img/hero-it-tabelander-1440.webp",
  network: "/assets/img/network-it-tabelander-1024.webp",
  pcRepair: "/assets/img/services/pc-laptop-reparatur-1200.webp",
  upgrades: "/assets/img/services/upgrades-systempflege-1200.webp",
  console: "/assets/img/services/controller-konsolen-service-1200.webp",
  network2: "/assets/img/services/netzwerk-wlan-1200.webp",
  overview: "/assets/img/services/service-overview-1200.webp",
  controllerFront: "/assets/img/controller/controller-dualsense-edge-official-front.png",
};
// Rich, natural German content for the SEO landing pages.
export const LANDING = {
  "pc-reparatur": {
    title: "PC-Reparatur in Tirol",
    seoTitle: "PC-Reparatur in Tirol – Diagnose & Reparatur | IT-Tabelander",
    seoDesc:
      "PC-Reparatur vom CompTIA A+ zertifizierten Techniker: Diagnose, Hardwarefehler, Kühlung, Netzteil, Mainboard, Speicher und Betriebssystem. Ehrlich und transparent.",
    h1: "PC-Reparatur – strukturierte Diagnose statt Rätselraten",
    image: "/assets/img/services/pc-laptop-reparatur-1200.webp",
    intro:
      "Wenn dein PC nicht mehr startet, überhitzt, abstürzt oder einfach zu langsam geworden ist, gehe ich der Ursache systematisch auf den Grund. Erst die saubere Diagnose, dann die passende Reparatur – ohne teure Rätselei.",
    bullets: [
      "Fehlerdiagnose & Hardware-Analyse",
      "Defekte Komponenten identifizieren & tauschen",
      "Kühlung & Überhitzung, Wärmeleitpaste erneuern",
      "Netzteil-, Mainboard- & Speicherprobleme",
      "Betriebssystemprobleme beheben",
      "Sinnvolle Aufrüstung nach Prüfung",
    ],
    note: "Ob eine Reparatur wirtschaftlich sinnvoll ist, bespreche ich vorher ehrlich mit dir.",
    faqCategory: "reparatur",
    related: [["Notebook-Reparatur", "/notebook-reparatur"], ["PC-Aufrüstung", "/pc-aufruestung"], ["Gaming-PC anfragen", "/anfrage?type=pc_build"]],
  },
  "notebook-reparatur": {
    title: "Notebook-Reparatur in Tirol",
    seoTitle: "Notebook-Reparatur in Tirol – SSD, Akku, Kühlung | IT-Tabelander",
    seoDesc:
      "Notebook-Reparatur: Fehlersuche, SSD-Tausch, RAM-Erweiterung, Akkutausch, Lüfter & Wärmeleitpaste. Was machbar ist, hängt vom Modell ab – ehrliche Einschätzung.",
    h1: "Notebook-Reparatur – abhängig vom Modell, immer ehrlich",
    image: "/assets/img/services/pc-laptop-reparatur-1200.webp",
    intro:
      "Notebooks sind kompakt gebaut – nicht alles lässt sich bei jedem Modell reparieren oder aufrüsten. Ich prüfe dein Gerät und sage dir klar, was technisch möglich und sinnvoll ist.",
    bullets: [
      "Fehlersuche & Diagnose",
      "SSD-Tausch & RAM-Erweiterung (modellabhängig)",
      "Akkutausch",
      "Lüfter / Kühlung reinigen & tauschen",
      "Wärmeleitpaste erneuern",
      "Weitere Reparaturen je nach Modell",
    ],
    note: "Es wird keine Reparatur versprochen, die bei deinem konkreten Notebook technisch nicht möglich ist.",
    faqCategory: "upgrades",
    related: [["PC-Reparatur", "/pc-reparatur"], ["PC-Aufrüstung", "/pc-aufruestung"], ["Kontakt", "/kontakt"]],
  },
  "pc-aufruestung": {
    title: "PC- & Notebook-Aufrüstung",
    seoTitle: "PC- & Notebook-Aufrüstung in Tirol – RAM, SSD, GPU | IT-Tabelander",
    seoDesc:
      "Mehr Leistung durch gezielte Upgrades: RAM, SSD/NVMe, Grafikkarte, CPU, Netzteil, Kühlung. Kompatibilität & Sinnhaftigkeit werden vorab geprüft.",
    h1: "Upgrades, die wirklich etwas bringen",
    image: "/assets/img/services/upgrades-systempflege-1200.webp",
    intro:
      "Nicht jedes Gerät lässt sich beliebig aufrüsten. Ich prüfe vorab Kompatibilität und Sinnhaftigkeit und empfehle nur Upgrades, die für dein System spürbar etwas bringen.",
    bullets: [
      "RAM-Erweiterung",
      "SSD / NVMe / HDD",
      "Grafikkarte (GPU)",
      "CPU-Upgrade",
      "Netzteil, Kühler & Lüfter",
      "WLAN & Netzwerk",
    ],
    note: "Nicht jedes Gerät lässt sich beliebig aufrüsten. IT-Tabelander prüft Kompatibilität und Sinnhaftigkeit vor dem Umbau.",
    faqCategory: "upgrades",
    related: [["PC-Reparatur", "/pc-reparatur"], ["Gaming-PC anfragen", "/anfrage?type=pc_build"], ["Notebook-Reparatur", "/notebook-reparatur"]],
  },
  "konsolen-reparatur": {
    title: "Konsolenreparatur (PlayStation, Xbox, Nintendo)",
    seoTitle: "Konsolenreparatur in Tirol – PS5, Xbox, Switch | IT-Tabelander",
    seoDesc:
      "Reparatur von PlayStation, Xbox und Nintendo. Unterstützte Modelle werden laufend gepflegt – frag einfach für dein Gerät an. Keine Reparatur wird blind garantiert.",
    h1: "Konsolenreparatur für PlayStation, Xbox & Nintendo",
    image: "/assets/img/services/controller-konsolen-service-1200.webp",
    intro:
      "Ob PlayStation, Xbox oder Nintendo Switch – ich prüfe deine Konsole und melde mich mit einer ehrlichen Einschätzung. Welche Modelle konkret unterstützt werden, wird laufend gepflegt.",
    bullets: [
      "PlayStation 5 & weitere PlayStation-Modelle",
      "Xbox Series & weitere Xbox-Modelle",
      "Nintendo Switch & weitere Modelle",
      "Reinigung & Wartung",
      "Fehlerdiagnose",
      "Weitere Reparaturen auf Anfrage",
    ],
    note: "Die konkret unterstützten Geräte werden laufend gepflegt. Eine Reparatur wird erst nach Prüfung des Geräts zugesagt.",
    faqCategory: "konsolen",
    related: [["Controller-Reparatur", "/controller-reparatur"], ["Controller-Umbau anfragen", "/anfrage?type=controller_custom"], ["Anfrage senden", "/anfrage"]],
  },
  "controller-reparatur": {
    title: "Controller-Reparatur",
    seoTitle: "Controller-Reparatur in Tirol – Stick Drift & mehr | IT-Tabelander",
    seoDesc:
      "Controller-Reparatur für PlayStation, Xbox und Nintendo: Stick Drift, Analogsticks, Buttons, Trigger, Gehäuse und Ladeprobleme. Prüfung vor jeder Zusage.",
    h1: "Controller-Reparatur & Customizing",
    image: "/assets/img/services/controller-konsolen-service-1200.webp",
    intro:
      "Stick Drift, klemmende Buttons oder Ladeprobleme? Ich repariere Controller von PlayStation, Xbox und Nintendo – und baue auf Wunsch individuelle Upgrades wie Hall-Effect-Sticks ein.",
    bullets: [
      "Stick Drift & Analogsticks",
      "Buttons & D-Pad",
      "Trigger & Bumper",
      "Gehäuse & Shell",
      "Ladeprobleme",
      "Individuelle Umbauten (z. B. Hall Effect)",
    ],
    note: "Keine Reparatur wird als garantiert dargestellt, bevor das Gerät geprüft wurde.",
    faqCategory: "controller",
    related: [["Controller-Umbau anfragen", "/anfrage?type=controller_custom"], ["Konsolen-Reparatur", "/konsolen-reparatur"], ["Anfrage starten", "/anfrage"]],
  },
};
// End of landing-page content.

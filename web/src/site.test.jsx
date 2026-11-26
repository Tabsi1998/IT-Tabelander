import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import Tabs from "./components/Tabs.jsx";
import { DEFAULT_SERVICES, servicesFrom } from "./content/services.js";
import { STEPS, TIMELINE, stepTone } from "./portal/steps.js";
import Reviews from "./sections/Reviews.jsx";
import Workshop from "./sections/Workshop.jsx";

describe("services", () => {
  it("fall back to Design A without admin services and never show prices", () => {
    expect(servicesFrom([])).toBe(DEFAULT_SERVICES);
    expect(servicesFrom(null)).toBe(DEFAULT_SERVICES);
    const text = JSON.stringify(DEFAULT_SERVICES);
    expect(text).not.toMatch(/€|EUR|Preis ab/);
  });

  it("take the admin's services, skipping inactive ones", () => {
    const list = servicesFrom([
      { slug: "pc-reparatur", title: "PC-Reparatur", heading: "PC-Reparatur", short_description: "Kurz",
        long_description: "Lang", bullets: ["A", ""], active: true },
      { slug: "alt", title: "Alt", active: false },
    ]);
    expect(list).toHaveLength(1);
    expect(list[0]).toMatchObject({ tab: "PC-Reparatur", heading: "Kurz", intro: "Lang", bullets: ["A"], requestType: "repair" });
  });
});

function TabsHarness() {
  const [selected, setSelected] = useState("a");
  return (
    <Tabs label="Test" selected={selected} onSelect={setSelected}
      items={[{ key: "a", label: "Eins" }, { key: "b", label: "Zwei" }, { key: "c", label: "Drei" }]}>
      Inhalt {selected}
    </Tabs>
  );
}

describe("tabs", () => {
  it("move with the arrow keys and keep only the chosen tab in the tab order", () => {
    render(<TabsHarness />);
    const first = screen.getByRole("tab", { name: "Eins" });
    expect(first).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("tab", { name: "Zwei" })).toHaveAttribute("tabindex", "-1");
    fireEvent.keyDown(first, { key: "ArrowLeft" });
    expect(screen.getByRole("tab", { name: "Drei" })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("tabpanel")).toHaveTextContent("Inhalt c");
    expect(screen.getByRole("tabpanel")).toHaveAttribute("aria-labelledby", screen.getByRole("tab", { name: "Drei" }).id);
  });
});

describe("reviews", () => {
  const review = (id, extra = {}) => ({ id, author: `Kunde ${id}`, text: "Top", rating: 5, ...extra });

  it("are hidden without reviews and never show demo ones", () => {
    const { container } = render(<Reviews data={{ reviews: [review("1", { is_demo: true })], average: null }} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("show three first, then all, and link a Google review", () => {
    const many = Array.from({ length: 5 }, (_, index) => review(String(index)));
    many[0] = review("0", { source: "Google", source_url: "https://g.page/r/x" });
    render(<Reviews data={{ reviews: many, average: 4.8 }} googleUrl="https://g.page/r/review" />);
    expect(screen.getAllByRole("img", { name: "5 von 5 Sternen" })).toHaveLength(3);
    expect(screen.getByText("4,8 von 5 Sternen · 5 Bewertungen")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Google" })).toHaveAttribute("href", "https://g.page/r/x");
    expect(screen.getByRole("link", { name: "Auf Google bewerten" })).toHaveAttribute("href", "https://g.page/r/review");
    fireEvent.click(screen.getByRole("button", { name: "Alle 5 Bewertungen anzeigen" }));
    expect(screen.getAllByRole("img", { name: "5 von 5 Sternen" })).toHaveLength(5);
  });
});

describe("workshop gallery", () => {
  const photo = (index, category = "pc_build", label = "PC-Bau") => ({
    id: String(index), image_url: `/api/media/${index}.webp`, caption: `Projekt ${index}`, category, category_label: label,
  });

  it("is hidden without photos", () => {
    const { container } = render(<Workshop items={[]} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows eight at a time and filters by area", () => {
    const items = [...Array.from({ length: 12 }, (_, index) => photo(index)), photo(20, "repair", "Reparatur")];
    render(<Workshop items={items} />);
    expect(screen.getAllByRole("listitem")).toHaveLength(8);
    fireEvent.click(screen.getByRole("button", { name: "Mehr Fotos anzeigen" }));
    expect(screen.getAllByRole("listitem")).toHaveLength(13);
    fireEvent.click(screen.getByRole("button", { name: "Reparatur" }));
    expect(screen.getAllByRole("listitem")).toHaveLength(1);
    expect(screen.getByRole("img", { name: "Projekt 20" })).toBeInTheDocument();
  });
});

describe("inquiry steps (#122)", () => {
  it("has a text and a place on the timeline for every step the customer area sends", () => {
    for (const step of ["eingegangen", "angebot_bereit", "in_arbeit", "wartet_auf_dich", "pausiert", "abholbereit",
      "abgeschlossen", "abgebrochen"]) {
      expect(STEPS[step].text).toBeTruthy();
      expect(STEPS[step].bar).toBeLessThanOrEqual(TIMELINE.length);
    }
    expect(stepTone({ step: "wartet_auf_dich" })).toBe("warn");
    expect(stepTone({ step: "abgeschlossen" })).toBe("good");
    expect(stepTone({ step: "in_arbeit" })).toBe("plain");
  });

  it("never sends the customer to the phone or to a status page", () => {
    const text = Object.values(STEPS).map((step) => step.text).join(" ");
    expect(text).not.toMatch(/ruf|Status-Seite|Status prüfen/i);
  });
});

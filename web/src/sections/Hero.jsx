import { ContactLink } from "../lib/contact.jsx";
import { useSite } from "../lib/site.jsx";
import { ArrowRight, Check, TraceUnderline } from "../components/Icons.jsx";

const TRUST = ["CompTIA A+ zertifiziert", "Ausbildung am WIFI Tirol", "Netzwerk- & Systemadministrator",
  "Angebot vor jeder Reparatur"];

const EXAMPLE_STEPS = [
  { label: "Anfrage eingegangen", state: "done" },
  { label: "Diagnose abgeschlossen", state: "done" },
  { label: "Angebot wartet auf dich", state: "now" },
  { label: "Reparatur", state: "open" },
  { label: "Fertig zur Abholung", state: "open" },
];

export default function Hero() {
  const { company } = useSite();
  const place = ["IT-Service", company?.town, "Tirol"].filter(Boolean).join(" · ");
  return (
    <section id="start" aria-labelledby="start-title" className="bg-surface">
      <div className="mx-auto grid max-w-page items-center gap-10 px-5 pb-12 pt-10 md:px-6 lg:grid-cols-[1.1fr_0.9fr] lg:gap-[72px] lg:pb-24 lg:pt-[88px]">
        <div className="flex flex-col gap-6 lg:gap-7">
          <div className="kicker">{place}</div>
          <h1 id="start-title" className="m-0 text-[40px] font-bold leading-[1.08] tracking-[-0.02em] md:text-[56px] lg:text-[68px] lg:leading-[1.04]">
            IT-Technik, die<br />
            <span className="relative inline-block">funktioniert.<TraceUnderline /></span>
          </h1>
          <p className="m-0 mt-3 max-w-[34em] text-lg leading-normal text-body lg:mt-[18px] lg:text-[21px]">
            Reparatur, Aufrüstung und PCs nach Wunsch. Für Notebooks, PCs, Konsolen und Controller.
            Du schickst eine Anfrage, bekommst ein klares Angebot und siehst jederzeit, wie weit dein Gerät ist.
          </p>
          <div className="flex flex-col gap-3 sm:flex-row sm:gap-3.5">
            <ContactLink tab="anfrage" className="btn-primary min-h-[56px] px-7 text-lg">
              Anfrage starten<ArrowRight />
            </ContactLink>
            <ContactLink tab="status" className="btn-outline min-h-[56px] px-[26px] text-lg">Status prüfen</ContactLink>
          </div>
          <ul className="m-0 mt-2 grid list-none gap-x-6 gap-y-2.5 p-0 text-base text-body sm:grid-cols-2">
            {TRUST.map((item) => (
              <li key={item} className="flex items-center gap-2.5"><Check size={18} className="shrink-0 text-ink" />{item}</li>
            ))}
          </ul>
        </div>
        <StatusExample />
      </div>
    </section>
  );
}

/** How the status looks - an example, clearly labelled as one. */
function StatusExample() {
  return (
    <figure className="m-0 flex flex-col gap-5 rounded-2xl border border-line bg-page p-6 md:p-7" aria-label="Beispiel einer Statusanzeige">
      <div className="flex items-center justify-between gap-3">
        <div className="kicker text-[10px] md:text-[11px]">So sieht dein Status aus</div>
        <span className="shrink-0 whitespace-nowrap rounded-full bg-badge px-2.5 py-1 text-[13px] font-semibold text-badge-ink">Angebot bereit</span>
      </div>
      <div>
        <div className="font-display text-2xl font-bold">Notebook-Reparatur</div>
        <div className="mt-1 text-[15px] text-muted">ANF-7K2M9QXD · Beispiel</div>
      </div>
      <ol className="m-0 flex list-none flex-col gap-4 p-0 text-base">
        {EXAMPLE_STEPS.map((step) => (
          <li key={step.label} className={`flex items-center gap-3.5 ${step.state === "open" ? "text-muted" : ""}`}>
            <span aria-hidden="true" className={`h-[22px] w-[22px] shrink-0 rounded-full ${
              step.state === "done" ? "bg-ink" : step.state === "now" ? "border-[5px] border-brand" : "border-2 border-field"}`} />
            {step.state === "open" ? step.label : <b>{step.label}</b>}
          </li>
        ))}
      </ol>
    </figure>
  );
}

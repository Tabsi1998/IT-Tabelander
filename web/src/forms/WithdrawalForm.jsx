import { useEffect, useId, useRef, useState } from "react";
import { useLocation, useSearchParams } from "react-router";
import { postJson, requestId } from "../lib/api.js";
import { Field, FormError, Honeypot, explain } from "./fields.jsx";

const SCOPES = [
  { value: "all", label: "Den ganzen Vertrag" },
  { value: "part", label: "Nur einen Teil davon" },
];

/** The online withdrawal function (§ 13a FAGG, #142): open to everyone,
 *  no sign-in. "Vertrag widerrufen" opens the form, "Widerruf bestätigen"
 *  sends it; the confirmation of receipt goes out by mail at once. */
export default function WithdrawalForm() {
  const [params] = useSearchParams();
  const location = useLocation();
  const preset = (params.get("vertrag") || "").slice(0, 40);
  const linked = Boolean(preset) || location.hash === "#vertrag-widerrufen";
  const [open, setOpen] = useState(linked);
  const [form, setForm] = useState({ name: "", email: "", contract: preset, scope: "all", parts: "", honeypot: "" });
  const [id] = useState(() => requestId("widerruf"));
  const [state, setState] = useState({ sending: false, error: "", done: null });
  const section = useRef(null);
  const first = useRef(null);
  const heading = useId();
  const formId = useId();
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  useEffect(() => {
    if (linked) section.current?.scrollIntoView({ block: "start" });
  }, [linked]);

  const start = () => {
    setOpen(true);
    // Focus the first field once it is there, so keyboard users land in the form.
    requestAnimationFrame(() => first.current?.focus());
  };

  const submit = async (event) => {
    event.preventDefault();
    setState({ sending: true, error: "", done: null });
    try {
      const answer = await postJson("/withdrawals", { ...form, request_id: id, parts: form.scope === "part" ? form.parts : "" });
      setState({ sending: false, error: "", done: { ...answer, ...form } });
    } catch (error) {
      setState({ sending: false, error: explain(error), done: null });
    }
  };

  return (
    <section id="vertrag-widerrufen" ref={section} aria-labelledby={heading}
      className="card flex scroll-mt-6 flex-col gap-4 p-5 md:gap-5 md:px-12 md:py-10 print:hidden">
      <h2 id={heading} className="m-0 text-2xl font-bold md:text-[30px]">Vertrag online widerrufen</h2>
      {state.done ? <Done done={state.done} /> : (
        <>
          <p className="m-0 text-[17px] leading-normal text-body">
            Hast du einen Vertrag online oder außerhalb meiner Werkstatt geschlossen, kannst du hier innerhalb der
            Rücktrittsfrist zurücktreten. Eine Anmeldung brauchst du dafür nicht. Du bekommst sofort eine
            Eingangsbestätigung per E-Mail.
          </p>
          {!open && (
            <button type="button" onClick={start} aria-expanded="false" aria-controls={formId}
              className="btn-primary min-h-[52px] self-stretch text-[17px] sm:self-start">
              Vertrag widerrufen
            </button>
          )}
          {open && (
            <form id={formId} onSubmit={submit} className="relative flex flex-col gap-4">
              <div className="grid gap-4 md:grid-cols-2">
                <Field ref={first} label="Dein Name" autoComplete="name" required minLength={2} maxLength={128}
                  value={form.name} onChange={set("name")} />
                <Field label="E-Mail für die Bestätigung" type="email" autoComplete="email" required
                  value={form.email} onChange={set("email")} />
              </div>
              <Field label="Nummer des Vertrags" hint="(Angebot, Auftrag oder Rechnung)" required
                placeholder="z. B. AN202611-0001" pattern="[A-Za-z0-9][A-Za-z0-9/_.\-]{1,39}" maxLength={40}
                value={form.contract} onChange={set("contract")} />
              <fieldset className="m-0 flex flex-col gap-2 border-0 p-0">
                <legend className="mb-1.5 p-0 text-[15px] font-semibold">Was widerrufst du?</legend>
                <div className="flex flex-col gap-2 sm:flex-row">
                  {SCOPES.map((item) => (
                    <label key={item.value}
                      className={`flex min-h-[48px] flex-1 cursor-pointer items-center gap-3 rounded-[10px] border-2 px-3.5 text-[15px] ${
                        form.scope === item.value ? "border-ink bg-chip font-semibold" : "border-line bg-surface"}`}>
                      <input type="radio" name="scope" value={item.value} checked={form.scope === item.value}
                        onChange={set("scope")} className="h-5 w-5 shrink-0 accent-[var(--c-ink)]" />
                      {item.label}
                    </label>
                  ))}
                </div>
              </fieldset>
              {form.scope === "part" && (
                <Field label="Welchen Teil?" as="textarea" rows={3} required minLength={3} maxLength={1000}
                  placeholder="z. B. Position 2, die Webseiten-Wartung" value={form.parts} onChange={set("parts")} />
              )}
              <Honeypot value={form.honeypot} onChange={(value) => setForm((current) => ({ ...current, honeypot: value }))} />
              <p className="m-0 text-[15px] leading-snug text-muted">
                Deine Angaben verwende ich nur für deinen Widerruf. Details in der{" "}
                <a href="/rechtliches/datenschutz" className="underline">Datenschutzerklärung</a>.
              </p>
              <FormError message={state.error} />
              <button type="submit" disabled={state.sending} className="btn-primary min-h-[52px] self-stretch text-[17px] sm:self-start">
                {state.sending ? "Wird gesendet …" : "Widerruf bestätigen"}
              </button>
            </form>
          )}
        </>
      )}
    </section>
  );
}

function Done({ done }) {
  return (
    <div role="status" className="flex flex-col gap-3">
      <p className="m-0 text-xl font-bold">Dein Widerruf ist eingegangen.</p>
      <p className="m-0 text-[17px] leading-normal text-body">
        Eingegangen am <b>{done.received_local} Uhr</b>, deine Nummer: <b className="font-display">{done.receipt}</b>.
      </p>
      <ul className="m-0 flex flex-col gap-1 pl-5 text-[15px] text-body">
        <li>{done.scope === "part" ? "Teil" : "Ganzer Vertrag"} mit der Nummer {done.contract}</li>
        {done.scope === "part" && <li>Widerrufener Teil: {done.parts}</li>}
        <li>Name: {done.name}</li>
        <li>Bestätigung an: {done.email}</li>
      </ul>
      <p className="m-0 text-[15px] leading-normal text-body">
        {done.mail_sent
          ? "Die Eingangsbestätigung ist per E-Mail unterwegs. Ich melde mich mit den nächsten Schritten."
          : "Die Bestätigung per E-Mail ist gerade nicht hinausgegangen. Dein Widerruf ist trotzdem da; bitte speichere diese Seite, ich bestätige dir den Eingang selbst per E-Mail."}
      </p>
    </div>
  );
}

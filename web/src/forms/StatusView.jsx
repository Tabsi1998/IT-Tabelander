import { useEffect, useState } from "react";
import { getJson, postJson } from "../lib/api.js";
import { Field, FormError, explain } from "./fields.jsx";

// The steps of the status API (#43, #78) in the customer's words.
export const STEPS = {
  eingegangen: { label: "Eingegangen", text: "Deine Anfrage ist da. Ich melde mich mit einer ersten Einschätzung.", bar: 1 },
  angebot_bereit: { label: "Angebot bereit", text: "Dein Angebot ist fertig. Du hast es per E-Mail bekommen; ohne deine Zusage passiert nichts.", bar: 2, badge: true },
  in_arbeit: { label: "In Arbeit", text: "Ich arbeite an deinem Gerät.", bar: 3 },
  wartet_auf_dich: { label: "Wartet auf dich", text: "Ich brauche noch eine Information von dir. Schau bitte in dein E-Mail-Postfach.", bar: 2, badge: true },
  pausiert: { label: "Pausiert", text: "Kurz pausiert, zum Beispiel weil ein Ersatzteil unterwegs ist.", bar: 3 },
  abholbereit: { label: "Abholbereit", text: "Dein Gerät ist fertig und kann abgeholt werden.", bar: 4, badge: true },
  abgeschlossen: { label: "Abgeschlossen", text: "Erledigt. Danke für dein Vertrauen!", bar: 5 },
  abgebrochen: { label: "Beendet", text: "Diese Anfrage wurde beendet. Bei Fragen melde dich gern.", bar: 0 },
};

function dateText(value) {
  if (!value) return "";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "" : date.toLocaleString("de-AT", { dateStyle: "medium", timeStyle: "short" });
}

export function StatusCard({ status }) {
  const step = STEPS[status.step] || STEPS.eingegangen;
  return (
    <div className="flex flex-col gap-3.5 rounded-xl border border-line p-5" role="status" aria-live="polite">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <b className="font-display text-[19px]">{status.request_type_label || "Anfrage"}</b>
        <span className={`rounded-full px-2.5 py-1 text-[13px] font-semibold ${step.badge ? "bg-badge text-badge-ink" : "bg-line-soft text-ink"}`}>{step.label}</span>
      </div>
      <div className="grid grid-cols-5 gap-2" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((index) => (
          <span key={index} className={`h-2 rounded-full ${index < step.bar ? "bg-ink" : index === step.bar ? "bg-brand" : "bg-line-soft"}`} />
        ))}
      </div>
      <p className="m-0 text-[15px] leading-normal text-body">{step.text}</p>
      <p className="m-0 text-sm text-muted">
        {status.ref}{dateText(status.updated_at) && ` · zuletzt aktualisiert ${dateText(status.updated_at)}`}
      </p>
    </div>
  );
}

/** Status by number and e-mail, or straight from the confirmation mail's
 *  link (/status/view.php?track_id=…, #48). Shows no personal data. */
export default function StatusView({ initial, trackId }) {
  const [form, setForm] = useState({ ref: initial?.ref || "", email: initial?.email || "" });
  const [state, setState] = useState({ busy: Boolean(trackId), error: "", status: null });

  useEffect(() => {
    if (!trackId) return undefined;
    const controller = new AbortController();
    getJson(`/inquiries/status/track/${encodeURIComponent(trackId)}`, { signal: controller.signal })
      .then((status) => setState({ busy: false, error: "", status }))
      .catch((error) => {
        if (error.name === "AbortError") return;
        setState({ busy: false, status: null, error: error.status === 404
          ? "Zu diesem Link gibt es keine Anfrage. Du kannst unten mit Nummer und E-Mail nachsehen."
          : explain(error) });
      });
    return () => controller.abort();
  }, [trackId]);

  useEffect(() => {
    if (initial?.ref) setForm({ ref: initial.ref, email: initial.email || "" });
  }, [initial]);

  const submit = async (event) => {
    event.preventDefault();
    setState({ busy: true, error: "", status: null });
    try {
      const status = await postJson("/inquiries/status", { ref: form.ref.trim().toUpperCase(), email: form.email.trim() });
      setState({ busy: false, error: "", status });
    } catch (error) {
      setState({ busy: false, status: null, error: error.status === 404
        ? "Keine Anfrage mit dieser Nummer und E-Mail gefunden. Bitte beides noch einmal prüfen."
        : error.status === 422 ? "Die Nummer hat die Form ANF-XXXXXXXX." : explain(error) });
    }
  };

  return (
    <div className="grid items-start gap-6 lg:grid-cols-[300px_minmax(0,1fr)] lg:gap-8">
      <form onSubmit={submit} className="flex flex-col gap-3.5">
        <Field label="Anfrage-Nummer" placeholder="ANF-…" autoComplete="off" required maxLength={12}
          inputClassName="font-semibold uppercase" value={form.ref}
          onChange={(event) => setForm((current) => ({ ...current, ref: event.target.value }))} />
        <Field label="E-Mail aus der Anfrage" type="email" autoComplete="email" required value={form.email}
          onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} />
        <button type="submit" disabled={state.busy} className="btn-primary min-h-[52px] text-[17px]">
          {state.busy ? "Wird geladen …" : "Status anzeigen"}
        </button>
      </form>
      <div className="flex flex-col gap-3">
        <FormError message={state.error} />
        {state.status ? <StatusCard status={state.status} /> : !state.error && (
          <p className="m-0 text-[15px] leading-normal text-muted">
            Die Nummer steht in deiner Bestätigung per E-Mail. Mit dem Link darin geht es auch ohne Eingabe.
          </p>
        )}
      </div>
    </div>
  );
}

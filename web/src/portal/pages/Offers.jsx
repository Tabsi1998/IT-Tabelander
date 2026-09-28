import { useState } from "react";
import { Link, useParams } from "react-router";
import { Field, FormError } from "../../forms/fields.jsx";
import { day, money, portalRequest, usePortalData } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { Badge, Empty, Loading, PageTitle, Problem } from "./parts.jsx";

const STATES = { 1: ["wartet auf dich", "warn"], 2: ["angenommen", "good"], 3: ["abgelehnt", "plain"], 4: ["abgerechnet", "good"] };

function State({ offer }) {
  if (offer.status === 1 && offer.expired) return <Badge>abgelaufen</Badge>;
  const [label, tone] = STATES[offer.status] || STATES[1];
  return <Badge tone={tone}>{label}</Badge>;
}

export function Offers() {
  const { overview } = usePortal();
  if (overview.loading && !overview.data) return <Loading />;
  if (overview.error) return <Problem error={overview.error} onRetry={overview.reload} />;
  const offers = overview.data.offers;
  return (
    <>
      <PageTitle text="Angebote, die ich dir geschickt habe. Offene kannst du hier ansehen und annehmen.">Angebote</PageTitle>
      {offers.length === 0 ? <Empty>Noch keine Angebote.</Empty> : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0">
          {offers.map((item) => (
            <li key={item.id}>
              <Link to={`/kundenbereich/angebote/${item.id}`} className="card flex flex-wrap items-center justify-between gap-3 p-5 no-underline hover:border-ink">
                <span className="flex flex-col">
                  <b className="font-display text-lg">Angebot {item.ref}</b>
                  <span className="text-sm text-muted">{[day(item.date), item.valid_until && `gültig bis ${day(item.valid_until)}`].filter(Boolean).join(" · ")}</span>
                </span>
                <span className="flex items-center gap-3"><b>{money(item.total)}</b><State offer={item} /></span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

function Lines({ item }) {
  return (
    <table className="w-full border-collapse text-[15px] md:text-base">
      <thead>
        <tr className="text-left text-sm text-muted">
          <th scope="col" className="border-b border-line py-2 font-semibold">Position</th>
          <th scope="col" className="border-b border-line py-2 text-right font-semibold">Betrag</th>
        </tr>
      </thead>
      <tbody>
        {item.lines.map((line, index) => (
          <tr key={`${item.id}-${index}`}>
            <td className="border-b border-line-soft py-3 pr-3 align-top">
              {Number(line.qty) > 1 ? `${Number(line.qty)} × ` : ""}{line.text}
              {line.details && <span className="block text-sm text-muted">{line.details}</span>}
            </td>
            <td className="whitespace-nowrap border-b border-line-soft py-3 text-right align-top">{money(line.total)}</td>
          </tr>
        ))}
        <tr>
          <td className="pt-3 text-sm text-muted">davon Umsatzsteuer</td>
          <td className="pt-3 text-right text-sm text-muted">{money(item.vat)}</td>
        </tr>
        <tr>
          <th scope="row" className="pt-2 text-left font-bold">Gesamt</th>
          <td className="pt-2 text-right font-display text-xl font-bold">{money(item.total)}</td>
        </tr>
      </tbody>
    </table>
  );
}

/** Yes or no to an offer (#65). The law for contracts at a distance (FAGG):
 *  the withdrawal right before the yes, starting at once only on request,
 *  the price right above a button that says it costs money. */
function Answer({ offer, onDone }) {
  const [mode, setMode] = useState("yes");
  const [form, setForm] = useState({ name: "", startNow: false, reason: "" });
  const [state, setState] = useState({ busy: false, error: "" });

  const send = async (accept) => {
    if (accept && form.name.trim().length < 2) {
      setState({ busy: false, error: "Bitte gib zur Bestätigung deinen Namen an." });
      return;
    }
    setState({ busy: true, error: "" });
    try {
      const updated = await portalRequest("POST", `/offers/${offer.id}/answer`, {
        accept, name: form.name.trim(), start_now: accept && form.startNow, reason: accept ? "" : form.reason.trim(),
      });
      onDone(updated, accept);
    } catch (error) {
      setState({ busy: false, error: error.message });
    }
  };

  if (mode === "no") {
    return (
      <section aria-labelledby="antwort" className="card flex flex-col gap-4 p-5 md:p-7">
        <h2 id="antwort" className="m-0 text-xl font-bold">Angebot ablehnen</h2>
        <Field label="Grund" hint="(freiwillig – hilft mir, es beim nächsten Mal besser zu machen)" as="textarea" rows={3} maxLength={1000}
          value={form.reason} onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))} />
        <FormError message={state.error} />
        <div className="flex flex-wrap gap-3">
          <button type="button" disabled={state.busy} onClick={() => send(false)} className="btn-outline border-line disabled:opacity-60">
            {state.busy ? "Wird gesendet …" : "Angebot ablehnen"}
          </button>
          <button type="button" onClick={() => setMode("yes")} className="btn-outline border-transparent">Zurück</button>
        </div>
      </section>
    );
  }
  return (
    <section aria-labelledby="antwort" className="flex flex-col gap-4 rounded-2xl border-2 border-accent bg-surface p-5 md:p-7">
      <h2 id="antwort" className="m-0 text-xl font-bold">Angebot annehmen</h2>
      <div className="rounded-[10px] bg-subtle p-4 text-[15px] leading-normal text-body">
        <b className="text-ink">Dein Rücktrittsrecht:</b> Nimmst du das Angebot hier an, kannst du als Verbraucherin oder Verbraucher
        innerhalb von 14 Tagen ohne Angabe von Gründen zurücktreten. Wie das geht, samt Muster-Formular, steht in den{" "}
        <a href="/rechtliches/nutzungsbedingungen" className="underline">Nutzungsbedingungen</a>; nach deiner Zusage bekommst du es auch per E-Mail.
      </div>
      <label className="flex items-start gap-3 text-[15px] leading-snug">
        <input type="checkbox" checked={form.startNow} onChange={(event) => setForm((current) => ({ ...current, startNow: event.target.checked }))}
          className="mt-0.5 h-5 w-5 shrink-0 accent-[var(--c-ink)]" />
        <span>
          <b>Bitte sofort beginnen.</b> Mir ist klar: Trete ich zurück, bevor die Arbeit fertig ist, zahle ich den Teil, der schon gemacht ist.
          Ist sie fertig, kann ich nicht mehr zurücktreten.
        </span>
      </label>
      <Field label="Dein Name zur Bestätigung" autoComplete="name" maxLength={128} value={form.name}
        onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} />
      <p className="m-0 text-[17px]">Gesamtpreis: <b className="font-display">{money(offer.total)}</b></p>
      <FormError message={state.error} />
      <div className="flex flex-col gap-3 sm:flex-row">
        <button type="button" disabled={state.busy} onClick={() => send(true)} className="btn-primary min-h-[54px] text-[17px] disabled:opacity-60">
          {state.busy ? "Wird gesendet …" : "Angebot kostenpflichtig annehmen"}
        </button>
        <button type="button" onClick={() => setMode("no")} className="btn-outline min-h-[54px] border-line">Ablehnen</button>
      </div>
    </section>
  );
}

export function OfferDetail() {
  const { id } = useParams();
  const { overview } = usePortal();
  const offer = usePortalData(`/offers/${encodeURIComponent(id)}`);
  const [answered, setAnswered] = useState(null);
  if (offer.loading && !offer.data) return <Loading />;
  if (offer.error) return <Problem error={offer.error} onRetry={offer.error.status >= 500 ? offer.reload : null} />;
  const item = answered?.offer || offer.data;
  return (
    <>
      <Link to="/kundenbereich/angebote" className="self-start text-[15px] font-semibold no-underline">← Alle Angebote</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <PageTitle text={[day(item.date), item.valid_until && `gültig bis ${day(item.valid_until)}`].filter(Boolean).join(" · ")}>
          Angebot {item.ref}
        </PageTitle>
        <State offer={item} />
      </div>
      {answered?.accepted && (
        <div role="status" className="card flex flex-col gap-2 p-5">
          <b className="font-display text-xl">Angebot angenommen – danke!</b>
          <p className="m-0 text-[15px] text-body">
            Die Bestätigung mit deinem Rücktrittsrecht ist per E-Mail unterwegs. Den Stand siehst du hier im Kundenbereich.
          </p>
        </div>
      )}
      {answered && !answered.accepted && (
        <p role="status" className="card m-0 p-5 text-body">Schade – ich habe deine Absage bekommen. Danke für die Rückmeldung.</p>
      )}
      <section className="card flex flex-col gap-4 p-5 md:p-7" aria-label="Positionen">
        <Lines item={item} />
        {item.has_pdf && (
          <a href={`/api/portal/offers/${item.id}/pdf`} target="_blank" rel="noopener noreferrer"
            className="btn-outline self-start border-line">Angebot als PDF</a>
        )}
      </section>
      {item.can_answer && !answered && (
        <Answer offer={item} onDone={(updated, accepted) => { setAnswered({ offer: updated, accepted }); overview.reload(); }} />
      )}
      {item.status === 1 && item.expired && (
        <p className="card m-0 p-5 text-body">Dieses Angebot ist abgelaufen. Schreib mir einfach, dann schicke ich dir ein neues.</p>
      )}
    </>
  );
}

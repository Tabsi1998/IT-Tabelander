import { useEffect, useRef, useState } from "react";
import { Link, useParams } from "react-router";
import { Field, FormError } from "../../forms/fields.jsx";
import { day, portalRequest, usePortalData } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { STEPS, TIMELINE, stepOf, stepTone } from "../steps.js";
import { Badge, Loading, PageTitle, Problem } from "./parts.jsx";

// Where a new inquiry starts; the customer area gets its own form with #123.
export const NEW_INQUIRY = "/?kontakt=anfrage#kontakt";

function when(value) {
  const date = value ? new Date(value) : null;
  return date && !Number.isNaN(date.getTime())
    ? date.toLocaleString("de-AT", { dateStyle: "medium", timeStyle: "short" }) : "";
}

/** Eingegangen → Angebot oder Rückfrage → In Arbeit → Fertig → Abgeschlossen. */
function Timeline({ item }) {
  const step = stepOf(item);
  if (!step.bar) return null;
  return (
    <ol aria-label="Zeitstrahl" className="m-0 grid list-none gap-3 p-0 md:grid-cols-5 md:gap-2">
      {TIMELINE.map((label, index) => {
        const place = index + 1;
        const state = place < step.bar ? "done" : place === step.bar ? "now" : "open";
        // The current place says what it really is: "Wartet auf dich", "Pausiert", "Abholbereit".
        const name = state === "now" && place > 1 ? step.label : label;
        return (
          <li key={label} aria-current={state === "now" ? "step" : undefined}
            className={`flex items-center gap-3 md:flex-col md:items-start md:gap-2 ${state === "open" ? "text-muted" : "text-ink"}`}>
            <span aria-hidden="true" className={`block h-[18px] w-[18px] shrink-0 rounded-full md:h-1.5 md:w-full ${
              state === "done" ? "bg-ink" : state === "now" ? "border-[4px] border-brand md:border-0 md:bg-brand" : "border-2 border-field md:border-0 md:bg-line-soft"}`} />
            <span className={`text-[15px] leading-tight ${state === "now" ? "font-bold" : ""}`}>
              {name}
              {state === "done" && <span className="sr-only"> (erledigt)</span>}
              {state === "now" && <span className="sr-only"> (aktuell)</span>}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

function Message({ message }) {
  const mine = message.from === "customer";
  const who = mine ? (message.author || "Du") : "IT-Tabelander";
  const how = message.via === "email" ? "per E-Mail" : message.via === "portal" ? "im Kundenbereich" : "";
  return (
    <li className={`flex flex-col gap-2 rounded-2xl p-4 md:max-w-[88%] md:p-5 ${
      mine ? "border border-line bg-subtle md:ml-auto" : "border border-line bg-surface md:mr-auto"}`}>
      <p className="m-0 flex flex-wrap items-baseline gap-x-2 text-sm text-muted">
        <b className={`text-[15px] ${mine ? "text-ink" : "text-link-hover"}`}>{who}</b>
        {how && <span>{how}</span>}
        {message.at && <time dateTime={message.at}>{when(message.at)}</time>}
      </p>
      <p className="m-0 whitespace-pre-line break-words text-base leading-relaxed text-body">{message.text}</p>
    </li>
  );
}

function Reply({ item, onSent }) {
  const [text, setText] = useState("");
  const [state, setState] = useState({ busy: false, error: "", sent: false });

  const send = async (event) => {
    event.preventDefault();
    if (text.trim().length < 2) {
      setState({ busy: false, error: "Bitte schreib kurz, was du mitteilen möchtest.", sent: false });
      return;
    }
    setState({ busy: true, error: "", sent: false });
    try {
      const updated = await portalRequest("POST", `/inquiries/${item.id}/messages`, { message: text.trim() });
      setText("");
      setState({ busy: false, error: "", sent: true });
      onSent(updated);
    } catch (error) {
      setState({ busy: false, error: error.message, sent: false });
    }
  };

  return (
    <form onSubmit={send} aria-labelledby="antworten" noValidate
      className={`flex flex-col gap-4 rounded-2xl bg-surface p-5 md:p-7 ${item.step === "wartet_auf_dich" ? "border-2 border-accent" : "border border-line"}`}>
      <h2 id="antworten" className="m-0 text-xl font-bold">Antworten</h2>
      <Field label="Deine Nachricht" as="textarea" rows={5} maxLength={5000} value={text}
        hint="(ich bekomme sie sofort und antworte hier oder per E-Mail)"
        onChange={(event) => { setText(event.target.value); if (state.sent || state.error) setState({ busy: false, error: "", sent: false }); }} />
      <FormError message={state.error} />
      {state.sent && <p role="status" className="m-0 text-[15px] font-semibold text-ink">Danke, deine Nachricht ist da. Du siehst sie oben im Verlauf.</p>}
      <button type="submit" disabled={state.busy} className="btn-primary min-h-[52px] self-stretch text-[17px] disabled:opacity-60 sm:self-start">
        {state.busy ? "Wird gesendet …" : "Nachricht senden"}
      </button>
    </form>
  );
}

/** "Hat sich erledigt" with a question first; closed stays readable (#122). */
function Close({ item, onClosed }) {
  const [asking, setAsking] = useState(false);
  const [state, setState] = useState({ busy: false, error: "" });
  const question = useRef(null);
  useEffect(() => { if (asking) question.current?.focus(); }, [asking]);

  const close = async () => {
    setState({ busy: true, error: "" });
    try {
      onClosed(await portalRequest("POST", `/inquiries/${item.id}/close`));
    } catch (error) {
      setState({ busy: false, error: error.message });
    }
  };

  if (!asking) {
    return (
      <div className="flex flex-col items-start gap-2">
        <button type="button" onClick={() => setAsking(true)} className="btn-outline min-h-[48px] border-line">Hat sich erledigt</button>
        <span className="text-sm text-muted">Brauchst du nichts mehr? Dann schließ die Anfrage hier.</span>
      </div>
    );
  }
  return (
    <div role="group" aria-labelledby="erledigt-frage" className="card flex flex-col gap-3 p-5">
      <p id="erledigt-frage" ref={question} tabIndex={-1} className="m-0 font-semibold text-ink outline-none">
        Anfrage wirklich schließen? Du kannst danach alles nachlesen, aber nicht mehr antworten.
      </p>
      <FormError message={state.error} />
      <div className="flex flex-col gap-3 sm:flex-row">
        <button type="button" disabled={state.busy} onClick={close} className="btn-primary min-h-[48px] disabled:opacity-60">
          {state.busy ? "Wird geschlossen …" : "Ja, Anfrage schließen"}
        </button>
        <button type="button" onClick={() => setAsking(false)} className="btn-outline min-h-[48px] border-line">Nein, offen lassen</button>
      </div>
    </div>
  );
}

/** One inquiry: where it stands, everything said so far, and the answer (#122). */
export default function InquiryDetail() {
  const { id } = useParams();
  const { overview } = usePortal();
  const inquiry = usePortalData(`/inquiries/${encodeURIComponent(id)}`);
  const [fresh, setFresh] = useState(null);
  const [closedNow, setClosedNow] = useState(false);
  useEffect(() => { setFresh(null); setClosedNow(false); }, [id]);

  if (inquiry.loading && !inquiry.data) return <Loading />;
  if (inquiry.error) {
    return (
      <>
        <Link to="/kundenbereich/anfragen" className="self-start text-[15px] font-semibold no-underline">← Alle Anfragen</Link>
        <Problem error={inquiry.error} onRetry={inquiry.error.status >= 500 ? inquiry.reload : null} />
      </>
    );
  }
  const item = fresh || inquiry.data;
  const step = stepOf(item);
  const updated = (next) => { setFresh(next); overview.reload(); };
  return (
    <>
      <Link to="/kundenbereich/anfragen" className="self-start text-[15px] font-semibold no-underline">← Alle Anfragen</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <PageTitle text={[item.inquiry_ref, item.ref && `Ticket ${item.ref}`, item.created_at && `seit ${day(item.created_at)}`].filter(Boolean).join(" · ")}>
          <span className="break-words">{item.title || "Anfrage"}</span>
        </PageTitle>
        <Badge tone={stepTone(item)}>{step.label}</Badge>
      </div>

      <section aria-labelledby="stand" className="card flex flex-col gap-5 p-5 md:p-7">
        <div className="flex flex-col gap-1.5">
          <h2 id="stand" className="m-0 text-xl font-bold">Stand</h2>
          <p className="m-0 max-w-[60ch] text-base leading-normal text-body">{step.text}</p>
          {item.step === "angebot_bereit" && (
            <Link to="/kundenbereich/angebote" className="self-start text-[15px] font-bold">Zum Angebot →</Link>
          )}
        </div>
        <Timeline item={item} />
        <p className="m-0 text-sm text-muted">
          {[item.updated_at && `Zuletzt geändert ${when(item.updated_at)}`, item.closed_at && `geschlossen ${when(item.closed_at)}`]
            .filter(Boolean).join(" · ")}
        </p>
      </section>

      <section aria-labelledby="verlauf" className="flex flex-col gap-3">
        <h2 id="verlauf" className="m-0 text-xl font-bold">Verlauf</h2>
        {item.messages.length === 0 ? (
          <p className="card m-0 p-5 text-body">Noch keine Nachrichten.</p>
        ) : (
          <ol className="m-0 flex list-none flex-col gap-3 p-0">
            {item.messages.map((message) => <Message key={message.id} message={message} />)}
          </ol>
        )}
      </section>

      {closedNow && <p role="status" className="card m-0 p-5 font-semibold text-ink">Erledigt – die Anfrage ist geschlossen. Danke für die Rückmeldung!</p>}
      {item.can_reply ? (
        <>
          <Reply item={item} onSent={updated} />
          <Close item={item} onClosed={(next) => { setClosedNow(true); updated(next); }} />
        </>
      ) : (
        <div className="card flex flex-col items-start gap-3 p-5">
          <p className="m-0 text-body">
            {item.step === "abgebrochen" ? STEPS.abgebrochen.text : "Diese Anfrage ist abgeschlossen. Du kannst alles nachlesen; antworten geht hier nicht mehr."}
          </p>
          <a href={NEW_INQUIRY} className="btn-outline min-h-[48px] border-line">Neue Anfrage starten</a>
        </div>
      )}
    </>
  );
}

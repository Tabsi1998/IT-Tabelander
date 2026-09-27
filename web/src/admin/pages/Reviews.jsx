import { useState } from "react";
import { Link } from "react-router";
import { Field } from "../../forms/fields.jsx";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { Chips, ConfirmButton, LoadState, Notice, PageHeader, Section, StarInput, Toggle } from "../ui.jsx";

const SOURCES = [["Google", "Google"], ["persönlich", "persönlich"], ["E-Mail", "E-Mail"]];
const SOURCE_NAMES = { Website: "über den Link nach dem Auftrag" };
const EMPTY = { author: "", rating: 5, text: "", source: "Google", source_url: "", review_date: "", visible: true };

function sourceOf(review) {
  if (review.source === "manuell" || !review.source) return "persönlich";
  return review.source;
}

/** The mails after a closed ticket (#71): what waits, what went out. */
function Invites() {
  const state = useAdminData("/admin/review-invites");
  const [run, setRun] = useState({ busy: false, text: "", error: "" });

  const check = async () => {
    setRun({ busy: true, text: "", error: "" });
    try {
      const result = await adminRequest("POST", "/admin/review-invites/run");
      state.reload();
      if (result.problem) { setRun({ busy: false, text: "", error: result.problem }); return; }
      setRun({ busy: false, error: "", text: result.sent
        ? `${result.sent} ${result.sent === 1 ? "Mail" : "Mails"} verschickt.`
        : "Geprüft – gerade ist kein weiteres Ticket abgeschlossen." });
    } catch (error) {
      setRun({ busy: false, text: "", error: errorText(error) });
    }
  };

  return (
    <Section title="Bewertungsbitten"
      description="Wer bei der Anfrage Ja gesagt hat, bekommt nach dem Schließen des Tickets in Dolibarr eine einzige Mail mit einem persönlichen Link. Die Website prüft das alle 30 Minuten.">
      <LoadState state={state}>
        {(data) => (
          <>
            <dl className="m-0 grid grid-cols-3 gap-3">
              {[["warten auf Abschluss", data.waiting], ["verschickt", data.sent], ["beantwortet", data.answered]].map(([label, value]) => (
                <div key={label} className="flex flex-col rounded-xl bg-subtle p-3">
                  <dt className="order-2 text-sm text-muted">{label}</dt>
                  <dd className="m-0 font-display text-2xl font-bold">{value}</dd>
                </div>
              ))}
            </dl>
            {data.last_error && <Notice tone="warning">Zuletzt nicht verschickt: {data.last_error}</Notice>}
          </>
        )}
      </LoadState>
      <button type="button" onClick={check} disabled={run.busy} className="btn-outline self-start border-line">
        {run.busy ? "Wird geprüft …" : "Jetzt prüfen"}
      </button>
      <Notice tone="success">{run.text}</Notice>
      <Notice tone="error">{run.error}</Notice>
    </Section>
  );
}

function dateText(value) {
  if (!value) return "–";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "–" : date.toLocaleDateString("de-AT");
}

/** Enter a review as the customer wrote it (#59). Never invent one. */
function ReviewForm({ review, onSaved, onCancel }) {
  const [form, setForm] = useState({ ...EMPTY, ...review, source: review ? sourceOf(review) : "Google" });
  const [state, setState] = useState({ busy: false, error: "" });
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const save = async (event) => {
    event.preventDefault();
    setState({ busy: true, error: "" });
    const body = {
      author: form.author.trim(), rating: form.rating, text: form.text.trim(), source: form.source,
      source_url: form.source === "Google" ? form.source_url.trim() : "", review_date: form.review_date || "",
      visible: form.visible, is_demo: false,
    };
    try {
      const saved = review?.id
        ? await adminRequest("PUT", `/admin/reviews/${review.id}`, body)
        : await adminRequest("POST", "/admin/reviews", body);
      onSaved(saved);
      setState({ busy: false, error: "" });
      if (!review?.id) setForm(EMPTY);
    } catch (error) {
      setState({ busy: false, error: errorText(error) });
    }
  };

  return (
    <form onSubmit={save} className="flex flex-col gap-4">
      <div className="flex flex-col gap-2">
        <span className="text-[15px] font-semibold">Quelle</span>
        <Chips label="Quelle" options={SOURCES} value={form.source} onChange={(source) => setForm((current) => ({ ...current, source }))} />
      </div>
      {form.source === "Google" && (
        <Field label="Link zur Original-Bewertung" hint="(optional)" type="url" placeholder="https://g.co/…" value={form.source_url} onChange={set("source_url")} />
      )}
      <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_170px]">
        <Field label="Name" required maxLength={80} value={form.author} onChange={set("author")} />
        <Field label="Datum" type="date" value={form.review_date || ""} onChange={set("review_date")} />
      </div>
      <div className="flex flex-col gap-1">
        <span className="text-[15px] font-semibold">Sterne</span>
        <StarInput value={form.rating} onChange={(rating) => setForm((current) => ({ ...current, rating }))} />
      </div>
      <Field label="Text" hint="(wie vom Kunden geschrieben)" as="textarea" rows={4} required maxLength={2000} value={form.text} onChange={set("text")} />
      <Toggle checked={form.visible} onChange={(visible) => setForm((current) => ({ ...current, visible }))} label="Auf der Website zeigen" />
      <Notice tone="error">{state.error}</Notice>
      <div className="flex flex-wrap gap-3">
        <button type="submit" disabled={state.busy} className="btn-primary">{state.busy ? "Wird gespeichert …" : "Speichern"}</button>
        {onCancel && <button type="button" onClick={onCancel} className="btn-outline border-line">Abbrechen</button>}
      </div>
    </form>
  );
}

export default function Reviews() {
  const state = useAdminData("/admin/reviews");
  const settings = useAdminData("/admin/settings");
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState("");

  const saved = (review) => {
    state.setData((current) => {
      const list = current || [];
      return list.some((item) => item.id === review.id)
        ? list.map((item) => (item.id === review.id ? review : item)) : [review, ...list];
    });
    setEditing(null);
  };

  const run = async (change, request) => {
    setError("");
    const before = state.data;
    state.setData(change);
    try {
      await request();
    } catch (failure) {
      state.setData(before);
      setError(errorText(failure));
    }
  };

  return (
    <>
      <PageHeader title="Bewertungen" description="Echte Bewertungen, wie Kunden sie geschrieben haben – auch von Google, mit Link zum Original."
        actions={settings.data && (
          <span className="text-[15px] text-body">
            Google-Profil: {settings.data.google_review_url
              ? <b className="text-ink">hinterlegt</b>
              : <Link to="/admin/technik">noch nicht hinterlegt</Link>}
          </span>
        )} />
      <Notice tone="error">{error}</Notice>
      <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_440px]">
        <LoadState state={state}>
          {(reviews) => (reviews.length === 0 ? <p className="card m-0 p-5 text-body">Noch keine Bewertungen. Ohne Bewertungen ist der Bereich auf der Website ausgeblendet.</p> : (
            <ul className="m-0 flex list-none flex-col gap-3 p-0">
              {reviews.map((review) => (
                <li key={review.id} className="card flex flex-col gap-2 p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <b>{review.author}</b>
                    <span aria-label={`${review.rating} von 5 Sternen`} className="tracking-[1px] text-accent">{"★".repeat(review.rating)}</span>
                  </div>
                  <p className="m-0 line-clamp-3 text-[15px] text-body">{review.text}</p>
                  <span className="text-sm text-muted">
                    {review.is_demo ? "Beispiel – erscheint nie auf der Website"
                      : `${SOURCE_NAMES[sourceOf(review)] || sourceOf(review)} · ${dateText(review.review_date || review.created_at)}${review.inquiry_ref ? ` · ${review.inquiry_ref}` : ""}`}
                  </span>
                  {review.pending && editing !== review.id && (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="rounded-full bg-badge px-2.5 py-1 text-[13px] font-semibold text-badge-ink">Wartet auf deine Freigabe</span>
                      <button type="button" className="btn-primary min-h-[44px] px-4 text-[15px]"
                        onClick={() => run(
                          (current) => current.map((item) => (item.id === review.id ? { ...item, visible: true, pending: false } : item)),
                          () => adminRequest("PUT", `/admin/reviews/${review.id}`, {
                            author: review.author, rating: review.rating, text: review.text, visible: true }))}>
                        Freigeben
                      </button>
                    </div>
                  )}
                  {editing === review.id ? (
                    <ReviewForm review={review} onSaved={saved} onCancel={() => setEditing(null)} />
                  ) : (
                    <div className="flex flex-wrap items-center gap-2">
                      {!review.pending && <Toggle checked={Boolean(review.visible) && !review.is_demo} disabled={review.is_demo} label={`Bewertung von ${review.author} sichtbar`} hideLabel
                        onChange={(visible) => run(
                          (current) => current.map((item) => (item.id === review.id ? { ...item, visible } : item)),
                          () => adminRequest("PUT", `/admin/reviews/${review.id}`, {
                            author: review.author, rating: review.rating, text: review.text, visible }))} />}
                      <button type="button" className="btn-outline min-h-[44px] border-line px-4 text-[15px]" onClick={() => setEditing(review.id)}>Bearbeiten</button>
                      <ConfirmButton label="Löschen" confirm="Bewertung löschen" className="ml-auto"
                        onConfirm={() => run((current) => current.filter((item) => item.id !== review.id),
                          () => adminRequest("DELETE", `/admin/reviews/${review.id}`))} />
                    </div>
                  )}
                </li>
              ))}
            </ul>
          ))}
        </LoadState>
        <div className="flex flex-col gap-6">
          <Section title="Bewertung eintragen">
            <ReviewForm onSaved={saved} />
          </Section>
          <Invites />
        </div>
      </div>
    </>
  );
}

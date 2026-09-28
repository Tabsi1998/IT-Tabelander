import { useState } from "react";
import { Link } from "react-router";
import { Field } from "../../forms/fields.jsx";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { Chips, ConfirmButton, LoadState, Notice, PageHeader, Section, StarInput, Toggle } from "../ui.jsx";

const SOURCES = [["Google", "Google"], ["persönlich", "persönlich"], ["E-Mail", "E-Mail"]];
const EMPTY = { author: "", rating: 5, text: "", source: "Google", source_url: "", review_date: "", visible: true };

function sourceOf(review) {
  if (review.source === "manuell" || !review.source) return "persönlich";
  return review.source;
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
                    {review.is_demo ? "Beispiel – erscheint nie auf der Website" : `${sourceOf(review)} · ${dateText(review.review_date || review.created_at)}`}
                  </span>
                  {editing === review.id ? (
                    <ReviewForm review={review} onSaved={saved} onCancel={() => setEditing(null)} />
                  ) : (
                    <div className="flex flex-wrap items-center gap-2">
                      <Toggle checked={Boolean(review.visible) && !review.is_demo} disabled={review.is_demo} label={`Bewertung von ${review.author} sichtbar`} hideLabel
                        onChange={(visible) => run(
                          (current) => current.map((item) => (item.id === review.id ? { ...item, visible } : item)),
                          () => adminRequest("PUT", `/admin/reviews/${review.id}`, {
                            author: review.author, rating: review.rating, text: review.text, visible }))} />
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
        <Section title="Bewertung eintragen">
          <ReviewForm onSaved={saved} />
        </Section>
      </div>
    </>
  );
}

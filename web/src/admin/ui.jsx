import { useId, useState } from "react";
import { adminRequest, errorText } from "./api.js";

export function PageHeader({ title, description, actions }) {
  return (
    <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="m-0 text-[26px] font-bold md:text-[30px]">{title}</h1>
        {description && <p className="m-0 max-w-[48em] text-[15px] text-body">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}

export function Section({ title, description, children, className = "" }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`card flex flex-col gap-4 p-5 md:p-6 ${className}`}>
      <div className="flex flex-col gap-1">
        <h2 id={id} className="m-0 text-xl font-bold">{title}</h2>
        {description && <p className="m-0 text-[15px] text-body">{description}</p>}
      </div>
      {children}
    </section>
  );
}

/** An on/off switch, announced as one by screen readers. */
export function Toggle({ checked, onChange, label, disabled = false, hideLabel = false }) {
  return (
    <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)}
      className="flex min-h-[44px] items-center gap-3 text-left text-[15px] font-semibold disabled:opacity-50">
      <span aria-hidden="true" className={`flex h-7 w-12 shrink-0 rounded-full p-[3px] transition-colors ${checked ? "justify-end bg-ink" : "justify-start bg-field"}`}>
        <span className="h-[22px] w-[22px] rounded-full bg-surface" />
      </span>
      <span className={hideLabel ? "sr-only" : ""}>{label}</span>
    </button>
  );
}

const TONES = {
  success: "border-[#9BD3B4] bg-[#E3F1EA] text-[#1E6B45]",
  error: "border-[#F5B8A5] bg-badge text-badge-ink",
  warning: "border-[#FFD2B3] bg-badge text-badge-ink",
  info: "border-line bg-subtle text-ink",
};

export function Notice({ tone = "info", children }) {
  if (!children) return null;
  return (
    <p role={tone === "error" ? "alert" : "status"} className={`m-0 rounded-[10px] border p-3.5 text-[15px] font-semibold [&_a]:underline ${TONES[tone]}`}>
      {children}
    </p>
  );
}

/** While loading, a quiet placeholder; after a failure the reason and a way
 *  to try again. Forms render only after a successful load (#57). */
export function LoadState({ state, children }) {
  if (state.loading && !state.data) {
    return <div aria-busy="true" className="card h-48 animate-pulse" aria-label="Wird geladen" />;
  }
  if (state.error) {
    return (
      <div className="card flex flex-col items-start gap-3 p-5" role="alert">
        <p className="m-0 font-semibold">Konnte nicht geladen werden: {errorText(state.error)}</p>
        <p className="m-0 text-[15px] text-body">Solange nichts geladen ist, kann hier auch nichts gespeichert werden – so geht nichts verloren.</p>
        <button type="button" className="btn-outline min-h-[44px]" onClick={state.reload}>Noch einmal laden</button>
      </div>
    );
  }
  return children(state.data);
}

/** Buttons that behave like radio buttons (sources, areas). */
export function Chips({ label, options, value, onChange }) {
  return (
    <div role="group" aria-label={label} className="flex flex-wrap gap-2">
      {options.map(([key, text]) => (
        <button key={key} type="button" aria-pressed={value === key} onClick={() => onChange(key)}
          className={`min-h-[44px] rounded-full border-[1.5px] px-4 text-[15px] font-semibold ${
            value === key ? "border-ink bg-ink text-page" : "border-line bg-surface text-ink"}`}>
          {text}
        </button>
      ))}
    </div>
  );
}

export function StarInput({ value, onChange }) {
  return (
    <div role="radiogroup" aria-label="Sterne" className="flex gap-1">
      {[1, 2, 3, 4, 5].map((star) => (
        <button key={star} type="button" role="radio" aria-checked={value === star} aria-label={`${star} von 5 Sternen`}
          onClick={() => onChange(star)}
          className={`flex h-11 w-11 items-center justify-center rounded-lg text-[26px] leading-none ${star <= value ? "text-accent" : "text-field"}`}>
          ★
        </button>
      ))}
    </div>
  );
}

/** Delete in two clicks instead of a browser dialog. */
export function ConfirmButton({ label, confirm, onConfirm, className = "" }) {
  const [asking, setAsking] = useState(false);
  if (!asking) {
    return <button type="button" onClick={() => setAsking(true)} className={`btn-outline min-h-[44px] border-line px-4 text-[15px] ${className}`}>{label}</button>;
  }
  return (
    <span className="flex flex-wrap items-center gap-2">
      <button type="button" onClick={() => { setAsking(false); onConfirm(); }}
        className="min-h-[44px] rounded-[10px] bg-[#B42318] px-4 text-[15px] font-bold text-white">{confirm}</button>
      <button type="button" onClick={() => setAsking(false)} className="btn-outline min-h-[44px] border-line px-4 text-[15px]">Abbrechen</button>
    </span>
  );
}

/** Upload one picture to the media store and hand back its address. */
export function ImagePicker({ url, onChange, label, hint }) {
  const [state, setState] = useState({ busy: false, error: "" });
  const id = useId();
  const upload = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    setState({ busy: true, error: "" });
    try {
      const body = new FormData();
      body.append("file", file);
      const stored = await adminRequest("POST", "/admin/media", body);
      onChange(stored.url);
      setState({ busy: false, error: "" });
    } catch (error) {
      setState({ busy: false, error: errorText(error) });
    }
  };
  return (
    <div className="flex flex-col gap-2">
      <span className="text-[15px] font-semibold">{label}{hint && <span className="font-normal text-muted"> {hint}</span>}</span>
      {url && <img src={url} alt="" className="h-40 w-full max-w-[320px] rounded-xl bg-page object-cover" />}
      <div className="flex flex-wrap gap-2">
        <label htmlFor={id} className="btn-outline min-h-[44px] cursor-pointer px-4 text-[15px]">
          {state.busy ? "Wird hochgeladen …" : url ? "Bild ersetzen" : "Bild hochladen"}
        </label>
        <input id={id} type="file" accept="image/*" className="sr-only" onChange={upload} disabled={state.busy} />
        {url && <button type="button" className="btn-outline min-h-[44px] border-line px-4 text-[15px]" onClick={() => onChange("")}>Bild entfernen</button>}
      </div>
      <Notice tone="error">{state.error}</Notice>
    </div>
  );
}

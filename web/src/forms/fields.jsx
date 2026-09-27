import { useId } from "react";

/** A labelled input; `hint` shows as "(optional)" style text next to the label. */
export function Field({ label, hint, as = "input", className = "", inputClassName = "", ...props }) {
  const id = useId();
  const Input = as;
  return (
    <label htmlFor={id} className={`flex flex-col gap-1.5 text-[15px] font-semibold ${className}`}>
      <span>{label}{hint && <span className="font-normal text-muted"> {hint}</span>}</span>
      <Input id={id} className={`field ${as === "textarea" ? "py-3" : ""} ${inputClassName}`} {...props} />
    </label>
  );
}

/** Big choice buttons (request type, device) that behave like radio buttons. */
export function Choice({ pressed, onClick, className = "", children }) {
  return (
    <button type="button" aria-pressed={pressed} onClick={onClick}
      className={`border-2 text-ink ${pressed ? "border-ink bg-chip" : "border-line bg-surface"} ${className}`}>
      {children}
    </button>
  );
}

export function Consent({ checked, onChange, children }) {
  const id = useId();
  return (
    <label htmlFor={id} className="flex items-start gap-3 rounded-[10px] border-[1.5px] border-line bg-subtle p-3.5 text-[15px] leading-snug text-body">
      <input id={id} type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)}
        className="mt-0.5 h-5 w-5 shrink-0 accent-[var(--c-ink)]" required />
      <span>{children}</span>
    </label>
  );
}

/** Invisible to people, filled by spam bots; the server rejects those (#42). */
export function Honeypot({ value, onChange }) {
  return (
    <div aria-hidden="true" className="absolute left-[-10000px] top-auto h-px w-px overflow-hidden">
      <label>Website<input tabIndex={-1} autoComplete="off" value={value} onChange={(event) => onChange(event.target.value)} /></label>
    </div>
  );
}

/** One to five stars, announced as a choice of one. */
export function StarInput({ value, onChange, label = "Sterne" }) {
  return (
    <div role="radiogroup" aria-label={label} className="flex gap-1">
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

export function FormError({ message }) {
  if (!message) return null;
  return <p role="alert" className="m-0 rounded-[10px] border border-[#F5B8A5] bg-badge p-3.5 text-[15px] font-semibold text-badge-ink">{message}</p>;
}

/** The server's answer in words a customer understands. */
export function explain(error) {
  if (error?.status === 429) return "Zu viele Versuche in kurzer Zeit. Bitte in einer Stunde noch einmal, oder ruf einfach an.";
  if (error?.status === 413) return "Das ist zu groß. Bitte ein kleineres Foto wählen.";
  if (error?.status >= 500) return "Da ist gerade etwas schiefgegangen. Bitte gleich noch einmal versuchen, oder ruf einfach an.";
  if (error?.name === "TypeError") return "Keine Verbindung. Bitte die Internetverbindung prüfen und noch einmal versuchen.";
  return error?.message || "Das hat nicht geklappt. Bitte die Angaben prüfen.";
}

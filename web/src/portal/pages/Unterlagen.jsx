import { useEffect, useId, useRef, useState } from "react";
import { Field, FormError } from "../../forms/fields.jsx";
import { day, portalRequest } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { Badge, Empty, Loading, PageTitle, Problem } from "./parts.jsx";

/** What waits for the customer: something to confirm or to sign (#187). */
export const waitsForYou = (item) => item.can_confirm || item.can_sign;

function stand(item) {
  return item.stand ? `Stand ${day(`${item.stand}T12:00:00`)}` : "";
}

/** The state in the customer's words; the colour says whether something is to do. */
function State({ item }) {
  if (waitsForYou(item)) return <Badge tone="warn">{item.state === "outdated" ? "neue Fassung" : "wartet auf dich"}</Badge>;
  if (item.state === "done") return <Badge tone="good">erledigt</Badge>;
  if (item.waiting_countersign) return <Badge>Gegenzeichnung folgt</Badge>;
  if (item.action === "handover" && item.has_pdf) return <Badge>zum Nachlesen</Badge>;
  return <Badge>in Vorbereitung</Badge>;
}

function status(item) {
  if (item.state === "done") return item.done_at ? `Erledigt am ${day(`${item.done_at}T12:00:00`)}.` : "Erledigt.";
  if (item.waiting_countersign) return "Du hast unterschrieben. Ich unterschreibe gegen, dann findest du das fertige Dokument hier.";
  if (item.can_confirm && item.state === "outdated") return "Es gibt eine neue Fassung. Bitte lies sie und bestätige sie noch einmal.";
  if (item.can_confirm) return "Bitte lies die Unterlage und bestätige sie.";
  if (item.can_sign) return "Bitte lies die Unterlage und unterschreib sie online.";
  if (item.action === "handover" && item.has_pdf) return "Zum Nachlesen, für dich ist nichts zu tun.";
  return "Ich bereite die Unterlage für dich vor und melde mich.";
}

function pdfLink(item, several) {
  const query = several ? `?kunde=${encodeURIComponent(item.customer_id)}` : "";
  return `/api/portal/unterlagen/${encodeURIComponent(item.slug)}/pdf${query}`;
}

/** AGB, Nutzungsbedingungen: the tick and the name (#187). */
function Confirm({ item, several, onDone, onCancel }) {
  const [form, setForm] = useState({ read: false, name: "" });
  const [state, setState] = useState({ busy: false, error: "" });
  const send = async (event) => {
    event.preventDefault();
    if (!form.read) return setState({ busy: false, error: "Bitte bestätige, dass du die Unterlage gelesen hast." });
    if (form.name.trim().length < 2) return setState({ busy: false, error: "Bitte gib zur Bestätigung deinen Namen an." });
    setState({ busy: true, error: "" });
    try {
      onDone(await portalRequest("POST", `/unterlagen/${encodeURIComponent(item.slug)}/bestaetigen`, {
        name: form.name.trim(), accepted: true, version: item.stand, ...(several ? { customer_id: item.customer_id } : {}),
      }), "confirm");
    } catch (error) {
      setState({ busy: false, error: error.message });
    }
    return undefined;
  };
  return (
    <form onSubmit={send} noValidate className="flex flex-col gap-4 border-t border-line-soft pt-4" aria-label={`${item.title} bestätigen`}>
      <label className="flex items-start gap-3 text-[15px] leading-snug">
        <input type="checkbox" checked={form.read} onChange={(event) => setForm((current) => ({ ...current, read: event.target.checked }))}
          className="mt-0.5 h-5 w-5 shrink-0 accent-[var(--c-ink)]" />
        <span>Ich habe „{item.title}“{item.stand && ` (${stand(item)})`} gelesen und bin einverstanden.</span>
      </label>
      <Field label="Dein Name zur Bestätigung" autoComplete="name" maxLength={128} value={form.name}
        onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} />
      <FormError message={state.error} />
      <div className="flex flex-col gap-3 sm:flex-row">
        <button type="submit" disabled={state.busy} className="btn-primary min-h-[54px] text-[17px] disabled:opacity-60">
          {state.busy ? "Wird gesendet …" : "Verbindlich bestätigen"}
        </button>
        <button type="button" onClick={onCancel} className="btn-outline min-h-[54px] border-line">Abbrechen</button>
      </div>
    </form>
  );
}

/** A pad for a finger, pen or mouse, as js/sign.js of the Dolibarr module:
 *  the drawn part cropped, as PNG in base64 without "data:" and "=" (#187). */
function usePad() {
  const canvas = useRef(null);
  const pad = useRef({ strokes: 0, bounds: null, ratio: 1 });
  const [inked, setInked] = useState(false);

  useEffect(() => {
    const node = canvas.current;
    if (!node) return undefined;
    const state = pad.current;
    let drawing = false;
    let last = null;
    const size = () => {
      const rect = node.getBoundingClientRect();
      state.ratio = Math.max(window.devicePixelRatio || 1, 1);
      node.width = Math.round(rect.width * state.ratio);
      node.height = Math.round(rect.height * state.ratio);
      const context = node.getContext("2d");
      context.setTransform(state.ratio, 0, 0, state.ratio, 0, 0);
      context.lineCap = "round";
      context.lineJoin = "round";
      context.strokeStyle = "#1B3348";
      context.fillStyle = "#1B3348";
      state.strokes = 0;
      state.bounds = null;
      setInked(false);
    };
    const point = (event) => {
      const rect = node.getBoundingClientRect();
      return { x: event.clientX - rect.left, y: event.clientY - rect.top, p: event.pressure || 0.5 };
    };
    const grow = (p) => {
      const b = state.bounds || { x1: p.x, y1: p.y, x2: p.x, y2: p.y };
      state.bounds = { x1: Math.min(b.x1, p.x), y1: Math.min(b.y1, p.y), x2: Math.max(b.x2, p.x), y2: Math.max(b.y2, p.y) };
    };
    const down = (event) => {
      event.preventDefault();
      node.setPointerCapture?.(event.pointerId);
      drawing = true;
      last = point(event);
      grow(last);
      const context = node.getContext("2d");
      context.beginPath();
      context.arc(last.x, last.y, 1.2, 0, Math.PI * 2);
      context.fill();
      state.strokes += 1;
      setInked(true);
    };
    const move = (event) => {
      if (!drawing) return;
      event.preventDefault();
      const next = point(event);
      const context = node.getContext("2d");
      context.lineWidth = event.pointerType === "pen" ? 1.4 + next.p * 2 : 2.4;
      const mid = { x: (last.x + next.x) / 2, y: (last.y + next.y) / 2 };
      context.beginPath();
      context.moveTo(last.mid ? last.mid.x : last.x, last.mid ? last.mid.y : last.y);
      context.quadraticCurveTo(last.x, last.y, mid.x, mid.y);
      context.stroke();
      next.mid = mid;
      last = next;
      grow(next);
    };
    const up = () => { drawing = false; };
    const resize = () => { if (!state.strokes) size(); };
    size();
    state.clear = size;
    node.addEventListener("pointerdown", down);
    node.addEventListener("pointermove", move);
    ["pointerup", "pointercancel", "pointerleave"].forEach((type) => node.addEventListener(type, up));
    window.addEventListener("resize", resize);
    return () => {
      node.removeEventListener("pointerdown", down);
      node.removeEventListener("pointermove", move);
      ["pointerup", "pointercancel", "pointerleave"].forEach((type) => node.removeEventListener(type, up));
      window.removeEventListener("resize", resize);
    };
  }, []);

  const image = () => {
    const { strokes, bounds, ratio } = pad.current;
    const node = canvas.current;
    if (!strokes || !bounds || !node) return "";
    // Big enough to be a signature, not a slip of the finger.
    if (bounds.x2 - bounds.x1 < 30 && bounds.y2 - bounds.y1 < 15) return "";
    const margin = 8;
    const x = Math.max(bounds.x1 - margin, 0);
    const y = Math.max(bounds.y1 - margin, 0);
    const w = Math.min(bounds.x2 + margin, node.width / ratio) - x;
    const h = Math.min(bounds.y2 + margin, node.height / ratio) - y;
    const out = document.createElement("canvas");
    out.width = Math.max(Math.round(w * ratio), 20);
    out.height = Math.max(Math.round(h * ratio), 10);
    out.getContext("2d").drawImage(node, x * ratio, y * ratio, w * ratio, h * ratio, 0, 0, out.width, out.height);
    return out.toDataURL("image/png").split(",")[1].replace(/=+$/, "");
  };
  return { canvas, inked, image, clear: () => pad.current.clear?.() };
}

/** AVV, Fernwartung: signed on the screen; the counter signature follows (#187). */
function Sign({ item, several, onDone, onCancel }) {
  const { canvas, inked, image, clear } = usePad();
  const hint = useId();
  const [name, setName] = useState("");
  const [state, setState] = useState({ busy: false, error: "" });
  const send = async (event) => {
    event.preventDefault();
    const png = image();
    if (!png) return setState({ busy: false, error: "Bitte unterschreib im Feld – mit dem Finger, einem Stift oder der Maus." });
    if (name.trim().length < 2) return setState({ busy: false, error: "Bitte gib deinen Namen in Druckbuchstaben an." });
    setState({ busy: true, error: "" });
    try {
      onDone(await portalRequest("POST", `/unterlagen/${encodeURIComponent(item.slug)}/unterschreiben`, {
        name: name.trim(), png, version: item.stand, ...(several ? { customer_id: item.customer_id } : {}),
      }), "sign");
    } catch (error) {
      setState({ busy: false, error: error.message });
    }
    return undefined;
  };
  return (
    <form onSubmit={send} noValidate className="flex flex-col gap-4 border-t border-line-soft pt-4" aria-label={`${item.title} unterschreiben`}>
      <div className="flex flex-col gap-1.5">
        <span className="text-[15px] font-semibold">Deine Unterschrift</span>
        <span id={hint} className="text-[15px] text-muted">Mit dem Finger, einem Stift oder der Maus ins Feld.</span>
        <div className="relative mt-1 overflow-hidden rounded-[10px] border-[1.5px] border-field bg-white">
          <canvas ref={canvas} role="img" aria-label="Feld für deine Unterschrift" aria-describedby={hint}
            className="block h-[170px] w-full cursor-crosshair touch-none md:h-[190px]" />
          {/* The line to sign on, as on paper; it is not part of the image. */}
          <span aria-hidden="true" className="pointer-events-none absolute inset-x-5 bottom-10 border-b border-dashed border-[#9AA9BA]" />
          {!inked && (
            <span aria-hidden="true" className="pointer-events-none absolute bottom-3 left-5 text-[13px] text-[#4A5B6E]">Hier unterschreiben</span>
          )}
        </div>
        <button type="button" onClick={clear} disabled={!inked}
          className="self-end text-[15px] font-semibold text-ink underline underline-offset-4 disabled:no-underline disabled:opacity-50">
          Unterschrift löschen
        </button>
      </div>
      <Field label="Dein Name in Druckbuchstaben" autoComplete="name" maxLength={128} value={name}
        onChange={(event) => setName(event.target.value)} />
      <p className="m-0 text-[15px] leading-normal text-body">
        Mit dem Abschicken unterschreibst du „{item.title}“{item.stand && ` (${stand(item)})`}. Danach unterschreibe ich
        gegen; das fertige Dokument findest du dann hier.
      </p>
      <FormError message={state.error} />
      <div className="flex flex-col gap-3 sm:flex-row">
        <button type="submit" disabled={state.busy} className="btn-primary min-h-[54px] text-[17px] disabled:opacity-60">
          {state.busy ? "Wird gesendet …" : "Unterschrift abschicken"}
        </button>
        <button type="button" onClick={onCancel} className="btn-outline min-h-[54px] border-line">Abbrechen</button>
      </div>
    </form>
  );
}

function Item({ item, several, open, onOpen, onClose, onDone }) {
  const heading = useId();
  return (
    <li>
      <article aria-labelledby={heading} className={`flex flex-col gap-4 rounded-2xl bg-surface p-5 md:p-6 ${
        waitsForYou(item) ? "border-2 border-accent" : "border border-line"}`}>
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
          <div className="flex min-w-0 flex-col gap-0.5">
            <h2 id={heading} className="m-0 break-words font-display text-lg font-bold">{item.title}</h2>
            <span className="text-sm text-muted">
              {[stand(item), several && `für ${item.customer}`, !item.required && "freiwillig"].filter(Boolean).join(" · ")}
            </span>
          </div>
          <State item={item} />
        </div>
        <p className="m-0 text-[15px] leading-normal text-body">{status(item)}</p>
        {!open && (
          <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
            {item.has_pdf && (
              <a href={pdfLink(item, several)} target="_blank" rel="noopener noreferrer"
                className="btn-outline min-h-[48px] border-line">{item.signed_pdf ? "Unterschriebenes PDF" : "PDF lesen"}</a>
            )}
            {item.can_confirm && <button type="button" onClick={onOpen} className="btn-primary min-h-[48px]">Bestätigen</button>}
            {item.can_sign && <button type="button" onClick={onOpen} className="btn-primary min-h-[48px]">Online unterschreiben</button>}
          </div>
        )}
        {open && item.has_pdf && (
          <a href={pdfLink(item, several)} target="_blank" rel="noopener noreferrer" className="self-start text-[15px] font-semibold underline underline-offset-4">
            „{item.title}“ als PDF lesen
          </a>
        )}
        {open && item.can_confirm && <Confirm item={item} several={several} onDone={onDone} onCancel={onClose} />}
        {open && item.can_sign && <Sign item={item} several={several} onDone={onDone} onCancel={onClose} />}
      </article>
    </li>
  );
}

const ORDER = (item) => (waitsForYou(item) ? 0 : item.waiting_countersign ? 1 : item.state === "done" ? 3 : 2);

/** AGB, AVV, Fernwartung and the other documents of the customer (#187):
 *  read as PDF, confirm with the name and a tick, or sign on the screen. */
export default function Unterlagen() {
  const { overview, documents } = usePortal();
  const [open, setOpen] = useState(null);
  const [notice, setNotice] = useState("");
  if (documents.loading && !documents.data) return <Loading />;
  if (documents.error) return <Problem error={documents.error} onRetry={documents.reload} />;
  const items = [...documents.data].sort((a, b) => ORDER(a) - ORDER(b));
  const several = new Set(items.map((item) => item.customer_id)).size > 1 || (overview.data?.accounts?.length || 0) > 1;
  const key = (item) => `${item.customer_id}-${item.slug}`;
  const done = (updated, what) => {
    setOpen(null);
    setNotice(what === "sign"
      ? `Danke! Deine Unterschrift für „${updated.title}“ ist angekommen. Ich unterschreibe gegen, dann liegt das fertige Dokument hier.`
      : `Danke! „${updated.title}“ ist bestätigt.`);
    documents.reload();
  };
  return (
    <>
      <PageTitle text="Bedingungen und Vereinbarungen für unsere Zusammenarbeit. Was auf dich wartet, liest, bestätigst oder unterschreibst du gleich hier.">
        Unterlagen
      </PageTitle>
      {notice && <p role="status" className="card m-0 p-5 font-semibold text-ink">{notice}</p>}
      {items.length === 0 ? <Empty>Gerade gibt es keine Unterlagen für dich.</Empty> : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0">
          {items.map((item) => (
            <Item key={key(item)} item={item} several={several} open={open === key(item)}
              onOpen={() => { setNotice(""); setOpen(key(item)); }} onClose={() => setOpen(null)} onDone={done} />
          ))}
        </ul>
      )}
    </>
  );
}

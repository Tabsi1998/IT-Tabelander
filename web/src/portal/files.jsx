import { useId, useRef } from "react";
import { ApiError } from "../lib/api.js";

// What the server takes (#123); checked here first so nobody waits for a refusal.
export const MAX_FILES = 5;
const IMAGE_MAX = 15 * 1024 * 1024;
const PDF_MAX = 5 * 1024 * 1024;
const PHOTO = /^image\/(jpeg|png|webp)$/;

export function size(bytes) {
  const value = Number(bytes) || 0;
  if (value >= 1024 * 1024) return `${(value / 1024 / 1024).toLocaleString("de-AT", { maximumFractionDigits: 1 })} MB`;
  return `${Math.max(1, Math.round(value / 1024))} KB`;
}

/** Why a picked file cannot go, or "". */
export function refusal(file) {
  const pdf = file.type === "application/pdf" || /\.pdf$/i.test(file.name);
  if (!pdf && !PHOTO.test(file.type)) {
    return /hei[cf]/i.test(file.type || file.name)
      ? `${file.name}: HEIC-Fotos kann ich nicht öffnen. Stell die Kamera auf „Maximale Kompatibilität“ oder schick ein JPG.`
      : `${file.name}: nur Fotos (JPG, PNG, WebP) oder PDF-Dateien.`;
  }
  if (pdf && file.size > PDF_MAX) return `${file.name}: PDF-Dateien gehen bis 5 MB.`;
  if (!pdf && file.size > IMAGE_MAX) return `${file.name}: Fotos gehen bis 15 MB.`;
  return "";
}

/** One file to the ticket, with the progress of the upload (fetch cannot report it). */
export function uploadFile(ticketId, file, onProgress) {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open("POST", `/api/portal/inquiries/${ticketId}/files`);
    request.setRequestHeader("Accept", "application/json");
    request.upload.onprogress = (event) => { if (event.lengthComputable) onProgress(event.loaded / event.total); };
    request.onload = () => {
      let body = null;
      try { body = JSON.parse(request.responseText); } catch { /* no JSON */ }
      if (request.status >= 200 && request.status < 300) { resolve(body); return; }
      if (request.status === 401) window.dispatchEvent(new Event("portal-signed-out"));
      const detail = typeof body?.detail === "string" ? body.detail
        : request.status === 413 ? "Die Datei ist zu groß." : "Die Datei kam nicht an. Bitte versuch es noch einmal.";
      reject(new ApiError(request.status, detail));
    };
    request.onerror = () => reject(new ApiError(0, "Keine Verbindung. Bitte die Internetverbindung prüfen und noch einmal versuchen."));
    const form = new FormData();
    form.append("file", file, file.name);
    request.send(form);
  });
}

/** Upload what is not up yet, one after the other; a failure stops and keeps what arrived. */
export async function uploadAll(ticketId, picked, setPicked) {
  const names = [];
  for (const item of picked) {
    if (item.name) { names.push(item.name); continue; }
    const update = (patch) => setPicked((current) => current.map((entry) => (entry.key === item.key ? { ...entry, ...patch } : entry)));
    update({ progress: 0, error: "" });
    try {
      const stored = await uploadFile(ticketId, item.file, (progress) => update({ progress }));
      update({ name: stored.name, progress: 1 });
      names.push(stored.name);
    } catch (error) {
      update({ progress: null, error: error.message });
      throw error;
    }
  }
  return names;
}

const ICON = { strokeWidth: 2, stroke: "currentColor", fill: "none", strokeLinecap: "round", strokeLinejoin: "round" };

function PaperclipIcon() {
  return <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" {...ICON}><path d="M21 12.5l-8.5 8.5a5 5 0 01-7-7L14 5.5a3.3 3.3 0 014.7 4.7L10.2 18.7a1.7 1.7 0 01-2.4-2.4L15.5 8.6" /></svg>;
}

function CameraIcon() {
  return <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" {...ICON}><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>;
}

export function DocumentIcon({ className = "" }) {
  return <svg aria-hidden="true" className={className} width="28" height="28" viewBox="0 0 24 24" {...ICON}><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v4h4M9 13h6M9 17h6" /></svg>;
}

/** Pick photos and PDFs; on a phone also straight from the camera. Keeps a list
 *  with size, progress and a way to take one out again. */
export function FilePicker({ picked, setPicked, onRefuse, busy }) {
  const listId = useId();
  const counter = useRef(0);

  const add = (event) => {
    const chosen = [...(event.target.files || [])];
    event.target.value = "";
    const problems = [];
    const room = MAX_FILES - picked.length;
    const fresh = [];
    for (const file of chosen) {
      const problem = refusal(file);
      if (problem) problems.push(problem);
      else if (fresh.length < room) fresh.push({ key: `f${(counter.current += 1)}`, file, progress: null, error: "", name: "" });
      else problems.push(`Höchstens ${MAX_FILES} Dateien auf einmal.`);
    }
    if (fresh.length) setPicked((current) => [...current, ...fresh]);
    onRefuse([...new Set(problems)].join(" "));
  };

  return (
    <div className="flex flex-col gap-2.5">
      <span className="text-[15px] font-semibold">
        Fotos oder PDF <span className="font-normal text-muted">(optional, bis zu {MAX_FILES} – Fotos bis 15 MB, PDF bis 5 MB)</span>
      </span>
      {picked.length < MAX_FILES && (
        <div className="flex flex-wrap gap-2.5">
          <label className={`btn-outline min-h-[48px] cursor-pointer gap-2 border-line focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-ink ${busy ? "pointer-events-none opacity-60" : ""}`}>
            <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple className="sr-only"
              disabled={busy} onChange={add} aria-describedby={picked.length ? listId : undefined} />
            <PaperclipIcon />Datei hinzufügen
          </label>
          {/* The camera of a phone; shown only where the pointer is a finger. */}
          <label className={`btn-outline hidden min-h-[48px] cursor-pointer gap-2 border-line focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-ink [@media(pointer:coarse)]:inline-flex ${busy ? "pointer-events-none opacity-60" : ""}`}>
            <input type="file" accept="image/*" capture="environment" className="sr-only" disabled={busy} onChange={add} />
            <CameraIcon />Foto aufnehmen
          </label>
        </div>
      )}
      {picked.length > 0 && (
        <ul id={listId} aria-label="Ausgewählte Dateien" className="m-0 flex list-none flex-col gap-2 p-0">
          {picked.map((item) => (
            <li key={item.key} className="flex flex-col gap-1.5 rounded-[10px] border border-line bg-subtle px-3.5 py-2.5">
              <div className="flex items-center gap-3">
                <span className="min-w-0 flex-1 truncate text-[15px] font-semibold text-ink">{item.file.name}</span>
                <span className="shrink-0 text-sm text-muted">{size(item.file.size)}</span>
                {item.name ? (
                  <span className="shrink-0 text-sm font-semibold text-ink">angekommen</span>
                ) : (
                  <button type="button" disabled={busy} onClick={() => setPicked((current) => current.filter((entry) => entry.key !== item.key))}
                    aria-label={`${item.file.name} entfernen`}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xl leading-none text-ink hover:bg-line-soft disabled:opacity-40">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" {...ICON}><path d="M6 6l12 12M18 6L6 18" /></svg>
                  </button>
                )}
              </div>
              {item.progress !== null && !item.name && (
                <div role="progressbar" aria-label={`${item.file.name} wird hochgeladen`} aria-valuemin={0} aria-valuemax={100}
                  aria-valuenow={Math.round(item.progress * 100)} className="h-1.5 overflow-hidden rounded-full bg-line-soft">
                  <span className="block h-full rounded-full bg-brand transition-[width] duration-200 ease-out" style={{ width: `${Math.round(item.progress * 100)}%` }} />
                </div>
              )}
              {item.error && <span className="text-sm font-semibold text-badge-ink">{item.error}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

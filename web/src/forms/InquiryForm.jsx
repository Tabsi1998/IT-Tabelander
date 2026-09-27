import { useEffect, useRef, useState } from "react";
import { deletePhoto, postJson, requestId, uploadPhoto } from "../lib/api.js";
import { ArrowRight } from "../components/Icons.jsx";
import { Choice, Consent, Field, FormError, Honeypot, explain } from "./fields.jsx";

export const REQUEST_TYPES = [
  { key: "repair", label: "Reparatur", text: "Etwas ist kaputt oder spinnt." },
  { key: "pc_build", label: "PC-Neubau", text: "Ein PC, passend zu dir." },
  { key: "pc_upgrade", label: "Upgrade", text: "Mehr Leistung oder Speicher." },
  { key: "controller_custom", label: "Controller-Umbau", text: "Design, Sticks, Paddles." },
  { key: "consulting", label: "Beratung", text: "Ehrliche Empfehlung vorab." },
  { key: "other", label: "Sonstiges", text: "Passt nirgends dazu." },
];
const DEVICES = [
  ["pc", "Desktop-PC"], ["notebook", "Notebook"], ["playstation", "PlayStation"], ["xbox", "Xbox"],
  ["switch", "Switch"], ["controller", "Controller"], ["other", "Sonstiges"],
];
const SOURCES = [["new_controller", "Neuen Controller mitbestellen"], ["send_in", "Eigenen einsenden"], ["unsure", "Noch unsicher"]];
const NEEDS_DEVICE = new Set(["repair", "pc_upgrade", "other"]);
const WITH_BUDGET = new Set(["pc_build", "pc_upgrade", "controller_custom"]);
const STEPS = ["Anliegen", "Gerät & Fotos", "Kontakt"];
const MAX_PHOTOS = 5;
const SLOTS = ["09:00", "10:00", "11:00", "12:00", "13:00", "14:00", "15:00", "16:00", "17:00", "18:00"];

/** "2026-10-02" + "10:30" as ISO time with this browser's UTC offset, so the
 *  workshop sees the time the customer meant (#73). */
export function localIso(date, time) {
  const moment = new Date(`${date}T${time}:00`);
  const offset = -moment.getTimezoneOffset();
  const sign = offset >= 0 ? "+" : "-";
  const pad = (value) => String(Math.floor(Math.abs(value))).padStart(2, "0");
  return `${date}T${time}:00${sign}${pad(offset / 60)}:${pad(offset % 60)}`;
}

function tomorrow() {
  const date = new Date(Date.now() + 86_400_000);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** The inquiry in three steps (#47): what, which device with photos, contact. */
export default function InquiryForm({ preset, onShowStatus }) {
  const [draftId, setDraftId] = useState(() => requestId("anfrage"));
  const [step, setStep] = useState(0);
  const [type, setType] = useState(preset?.requestType || "");
  const [device, setDevice] = useState({ device_type: "", device_source: "", manufacturer: "", model: "", description: "", budget: "", timeframe: "" });
  const [contact, setContact] = useState({ name: "", email: "", phone: "", preferred_contact: "email", contact_type: "private", company_name: "" });
  const [callback, setCallback] = useState({ wanted: false, date: "", time: "10:00" });
  const [photos, setPhotos] = useState([]);
  const [consent, setConsent] = useState(false);
  const [honeypot, setHoneypot] = useState("");
  const [state, setState] = useState({ busy: false, error: "", done: null });
  const headingRef = useRef(null);
  const photosRef = useRef(photos);
  photosRef.current = photos;

  useEffect(() => { if (preset?.requestType) setType(preset.requestType); }, [preset]);
  useEffect(() => { if (step > 0) headingRef.current?.focus(); }, [step]);
  // Local previews live until they are removed or the form goes away.
  useEffect(() => () => photosRef.current.forEach((photo) => URL.revokeObjectURL(photo.preview)), []);

  const setD = (key) => (event) => setDevice((current) => ({ ...current, [key]: event.target.value }));
  const setC = (key) => (event) => setContact((current) => ({ ...current, [key]: event.target.value }));

  const stepError = () => {
    if (step === 0 && !type) return "Bitte wähle, worum es geht.";
    if (step === 1) {
      if (NEEDS_DEVICE.has(type) && !device.device_type) return "Bitte wähle das Gerät.";
      if (type === "controller_custom" && (!device.device_source || !device.manufacturer || !device.model)) {
        return "Für einen Controller-Umbau brauche ich Herkunft, Hersteller und Modell.";
      }
      if (device.description.trim().length < 10) return "Bitte beschreib kurz, worum es geht (mindestens 10 Zeichen).";
    }
    if (step === 2) {
      if (contact.name.trim().length < 2) return "Bitte gib deinen Namen an.";
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(contact.email.trim())) return "Bitte gib eine gültige E-Mail-Adresse an.";
      if ((contact.preferred_contact === "phone" || callback.wanted) && !contact.phone.trim()) return "Für einen Anruf brauche ich deine Telefonnummer.";
      if (callback.wanted && !callback.date) return "Bitte wähle einen Tag für den Rückruf.";
      if (contact.contact_type === "business" && !contact.company_name.trim()) return "Bitte gib den Firmennamen an.";
      if (!consent) return "Bitte bestätige, dass ich deine Angaben verwenden darf.";
    }
    return "";
  };

  const next = async () => {
    const problem = stepError();
    if (problem) { setState({ busy: false, error: problem, done: null }); return; }
    if (step < 2) { setState({ busy: false, error: "", done: null }); setStep(step + 1); return; }
    setState({ busy: true, error: "", done: null });
    const payload = {
      request_id: draftId, request_type: type, source: "website",
      device_type: device.device_type || (type === "controller_custom" ? "controller" : ""),
      ...(type === "controller_custom" ? { device_source: device.device_source } : {}),
      manufacturer: device.manufacturer, model: device.model, description: device.description.trim(),
      budget: device.budget, timeframe: device.timeframe,
      attachment_ids: photos.map((photo) => photo.id),
      contact: { ...contact, preferred_contact: callback.wanted ? "phone" : contact.preferred_contact },
      consent: true, honeypot,
      ...(callback.wanted ? { callback_at: localIso(callback.date, callback.time) } : {}),
    };
    try {
      const answer = await postJson("/inquiries", payload);
      setState({ busy: false, error: "", done: answer });
    } catch (error) {
      setState({ busy: false, error: explain(error), done: null });
    }
  };

  const addPhotos = async (event) => {
    const files = [...(event.target.files || [])].slice(0, MAX_PHOTOS - photos.length);
    event.target.value = "";
    for (const file of files) {
      const preview = URL.createObjectURL(file);
      setPhotos((current) => [...current, { key: preview, preview, id: null, name: file.name, uploading: true }]);
      try {
        const uploaded = await uploadPhoto(file, draftId);
        setPhotos((current) => current.map((photo) => (photo.key === preview ? { ...photo, id: uploaded.id, uploading: false } : photo)));
      } catch (error) {
        URL.revokeObjectURL(preview);
        setPhotos((current) => current.filter((photo) => photo.key !== preview));
        setState({ busy: false, error: explain(error), done: null });
      }
    }
  };

  const removePhoto = (photo) => {
    URL.revokeObjectURL(photo.preview);
    setPhotos((current) => current.filter((item) => item.key !== photo.key));
    if (photo.id) deletePhoto(photo.id, draftId).catch(() => {});
  };

  const restart = () => {
    photos.forEach((photo) => URL.revokeObjectURL(photo.preview));
    setDraftId(requestId("anfrage"));
    setStep(0); setType(""); setPhotos([]); setConsent(false);
    setDevice({ device_type: "", device_source: "", manufacturer: "", model: "", description: "", budget: "", timeframe: "" });
    setState({ busy: false, error: "", done: null });
  };

  if (state.done) {
    return (
      <div className="flex flex-col gap-4" role="status">
        <h3 className="m-0 text-[26px] font-bold md:text-[32px]">Danke, deine Anfrage ist da.</h3>
        <p className="m-0 text-[17px] leading-normal text-body md:text-lg">
          Du bekommst gleich eine Bestätigung per E-Mail. Mit dieser Nummer siehst du jederzeit den Stand:
        </p>
        <div className="self-start rounded-xl border border-line bg-page px-5 py-4">
          <span className="kicker">Anfrage-Nummer</span>
          <div className="font-display text-[26px] font-bold md:text-[28px]">{state.done.ref}</div>
        </div>
        <div className="flex flex-col gap-3 sm:flex-row">
          <button type="button" onClick={() => onShowStatus?.(state.done.ref, contact.email)} className="btn-primary min-h-[52px] text-base">Status ansehen</button>
          <button type="button" onClick={restart} className="btn-outline min-h-[52px] text-base">Neue Anfrage</button>
        </div>
      </div>
    );
  }

  const photosBusy = photos.some((photo) => photo.uploading);
  return (
    <div className="relative flex flex-col gap-5">
      <div className="flex flex-col gap-3">
        <div className="flex items-baseline justify-between gap-3">
          <h3 ref={headingRef} tabIndex={-1} className="m-0 text-[22px] font-bold outline-none md:text-[26px]">
            {["Worum geht's?", type === "pc_build" || type === "consulting" ? "Was hast du vor?" : "Welches Gerät?", "Wie erreiche ich dich?"][step]}
          </h3>
          <span className="shrink-0 text-[15px] text-muted">Schritt {step + 1} von 3</span>
        </div>
        <ol className="m-0 grid list-none grid-cols-3 gap-3 p-0" aria-label="Fortschritt">
          {STEPS.map((label, index) => (
            <li key={label} className="flex flex-col gap-2" aria-current={index === step ? "step" : undefined}>
              <span aria-hidden="true" className={`h-1.5 rounded-full ${index <= step ? "bg-brand" : "bg-line-soft"}`} />
              <span className={`text-[13px] md:text-sm ${index === step ? "font-bold text-ink" : "text-muted"}`}>{label}</span>
            </li>
          ))}
        </ol>
      </div>

      {step === 0 && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="group" aria-label="Anliegen">
          {REQUEST_TYPES.map((item) => (
            <Choice key={item.key} pressed={type === item.key} onClick={() => setType(item.key)}
              className="flex min-h-[84px] flex-col gap-1 rounded-xl p-4 text-left">
              <span className="font-display text-[17px] font-bold">{item.label}</span>
              <span className="text-sm text-muted">{item.text}</span>
            </Choice>
          ))}
        </div>
      )}

      {step === 1 && (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_260px]">
          <div className="flex flex-col gap-4">
            {type !== "controller_custom" && (
              <div className="flex flex-wrap gap-2" role="group" aria-label={NEEDS_DEVICE.has(type) ? "Gerät" : "Gerät (optional)"}>
                {DEVICES.map(([key, label]) => (
                  <Choice key={key} pressed={device.device_type === key} onClick={() => setDevice((current) => ({ ...current, device_type: key }))}
                    className="min-h-[44px] rounded-full px-4 text-[15px] font-semibold">{label}</Choice>
                ))}
              </div>
            )}
            {type === "controller_custom" && (
              <div className="flex flex-wrap gap-2" role="group" aria-label="Controller">
                {SOURCES.map(([key, label]) => (
                  <Choice key={key} pressed={device.device_source === key} onClick={() => setDevice((current) => ({ ...current, device_source: key }))}
                    className="min-h-[44px] rounded-full px-4 text-[15px] font-semibold">{label}</Choice>
                ))}
              </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Hersteller" hint={type === "controller_custom" ? "" : "(optional)"} placeholder="z. B. Lenovo" maxLength={160} value={device.manufacturer} onChange={setD("manufacturer")} />
              <Field label="Modell" hint={type === "controller_custom" ? "" : "(optional)"} placeholder="z. B. ThinkPad T14" maxLength={160} value={device.model} onChange={setD("model")} />
            </div>
            <Field label={type === "pc_build" ? "Wofür soll der PC sein?" : "Was ist passiert?"} as="textarea" rows={4} maxLength={10000}
              placeholder={type === "pc_build" ? "z. B. Gaming in WQHD, leise, Budget grob 1.500 €" : "z. B. Startet seit gestern nicht mehr, Lüfter läuft kurz an und geht wieder aus."}
              value={device.description} onChange={setD("description")} />
            {WITH_BUDGET.has(type) && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Budget" hint="(optional)" maxLength={120} value={device.budget} onChange={setD("budget")} />
                <Field label="Bis wann?" hint="(optional)" maxLength={120} value={device.timeframe} onChange={setD("timeframe")} />
              </div>
            )}
          </div>
          <div className="flex flex-col gap-2.5">
            <span className="text-[15px] font-semibold">Fotos <span className="font-normal text-muted">(optional, bis zu {MAX_PHOTOS})</span></span>
            {photos.length < MAX_PHOTOS && (
              <label className="flex min-h-[120px] cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-field bg-subtle p-4 text-center text-[15px] font-semibold">
                <input type="file" accept="image/*" multiple className="sr-only" onChange={addPhotos} aria-label="Fotos auswählen" />
                Foto aufnehmen oder auswählen
                <span className="font-normal text-muted">JPG, PNG oder WebP, je bis 8 MB</span>
              </label>
            )}
            {photos.length > 0 && (
              <ul className="m-0 grid list-none grid-cols-3 gap-2 p-0">
                {photos.map((photo) => (
                  <li key={photo.key} className="relative">
                    <img src={photo.preview} alt={`Foto ${photo.name}`} className={`aspect-square w-full rounded-lg object-cover ${photo.uploading ? "opacity-50" : ""}`} />
                    <button type="button" onClick={() => removePhoto(photo)} aria-label={`Foto ${photo.name} entfernen`}
                      className="absolute right-1 top-1 flex h-8 w-8 items-center justify-center rounded-full bg-navy text-on-navy">×</button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}

      {step === 2 && (
        <div className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" autoComplete="name" maxLength={128} value={contact.name} onChange={setC("name")} />
            <Field label="E-Mail" type="email" autoComplete="email" value={contact.email} onChange={setC("email")} />
            <Field label="Telefon" hint={callback.wanted || contact.preferred_contact === "phone" ? "" : "(optional)"} type="tel" autoComplete="tel" maxLength={40} value={contact.phone} onChange={setC("phone")} />
            <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
              <legend className="mb-1.5 p-0 text-[15px] font-semibold">Anfrage als</legend>
              <div className="flex gap-2">
                {[["private", "Privatperson"], ["business", "Firma"]].map(([key, label]) => (
                  <Choice key={key} pressed={contact.contact_type === key} onClick={() => setContact((current) => ({ ...current, contact_type: key }))}
                    className="min-h-[48px] flex-1 rounded-[10px] px-4 text-[15px] font-semibold">{label}</Choice>
                ))}
              </div>
            </fieldset>
          </div>
          {contact.contact_type === "business" && (
            <Field label="Firmenname" autoComplete="organization" maxLength={128} value={contact.company_name} onChange={setC("company_name")} />
          )}
          <label className="flex items-center gap-3 text-[15px] font-semibold">
            <input type="checkbox" checked={callback.wanted} onChange={(event) => setCallback((current) => ({ ...current, wanted: event.target.checked, date: current.date || tomorrow() }))}
              className="h-5 w-5 accent-[var(--c-ink)]" />
            Lieber anrufen lassen? Wunschzeit für einen Rückruf wählen
          </label>
          {callback.wanted && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Tag" type="date" min={tomorrow()} value={callback.date} onChange={(event) => setCallback((current) => ({ ...current, date: event.target.value }))} />
              <Field label="Uhrzeit" as="select" value={callback.time} onChange={(event) => setCallback((current) => ({ ...current, time: event.target.value }))}>
                {SLOTS.map((slot) => <option key={slot} value={slot}>{slot} Uhr</option>)}
              </Field>
            </div>
          )}
          <Honeypot value={honeypot} onChange={setHoneypot} />
          <Consent checked={consent} onChange={setConsent}>
            Meine Angaben und Fotos dürfen zur Bearbeitung dieser Anfrage verwendet werden. Details in der{" "}
            <a href="/rechtliches/datenschutz" className="underline">Datenschutzerklärung</a>.
          </Consent>
        </div>
      )}

      <FormError message={state.error} />
      <div className="flex items-center gap-3 border-t border-line-soft pt-5">
        {step > 0 && (
          <button type="button" onClick={() => { setState({ busy: false, error: "", done: null }); setStep(step - 1); }}
            className="btn-outline min-h-[52px] border-line px-5 text-base">Zurück</button>
        )}
        <button type="button" onClick={next} disabled={state.busy || photosBusy}
          className="btn-primary ml-auto min-h-[52px] px-6 text-[17px] disabled:opacity-60">
          {state.busy ? "Wird gesendet …" : photosBusy ? "Fotos werden geladen …" : step === 2 ? "Anfrage senden" : "Weiter"}
          <ArrowRight />
        </button>
      </div>
    </div>
  );
}

import React, { useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { AnimatePresence, motion } from "framer-motion";
import {
  ArrowLeft, ArrowRight, BriefcaseBusiness, CheckCircle2, CircleHelp, Gamepad2,
  HardDrive, ImagePlus, Laptop, Lightbulb, Loader2, Monitor, Send, Settings2,
  ShieldCheck, Sparkles, Upload, Wrench, X,
} from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError, mediaUrl } from "../lib/api";
import { trackEvent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Input, Label, Select, Textarea } from "../components/ui/input";
import PageHero from "../components/PageHero";
import Seo from "../components/Seo";

const STEPS = ["Anliegen", "Gerät", "Wünsche", "Beschreibung", "Fotos", "Kontakt", "Prüfen"];

const REQUEST_TYPES = [
  { key: "repair", label: "Reparatur", text: "Ein defektes Gerät prüfen und reparieren lassen.", icon: Wrench },
  { key: "pc_build", label: "PC-Neubau", text: "Einen vollständigen PC passend zu dir planen lassen.", icon: Monitor },
  { key: "pc_upgrade", label: "PC-/Notebook-Upgrade", text: "Leistung, Speicher, Kühlung oder Ausstattung verbessern.", icon: Settings2 },
  { key: "controller_custom", label: "Controller-Umbau", text: "Design und Funktionen deines Controllers anpassen.", icon: Gamepad2 },
  { key: "consulting", label: "Beratung", text: "Eine ehrliche Empfehlung vor Kauf oder Umbau erhalten.", icon: Lightbulb },
  { key: "other", label: "Sonstiges", text: "Dein Anliegen passt in keine der anderen Kategorien.", icon: CircleHelp },
];
const REQUEST_LABELS = Object.fromEntries(REQUEST_TYPES.map((item) => [item.key, item.label]));

const DEVICE_OPTIONS = [
  { key: "pc", label: "Desktop-PC", icon: Monitor },
  { key: "notebook", label: "Notebook", icon: Laptop },
  { key: "playstation", label: "PlayStation", icon: Gamepad2 },
  { key: "xbox", label: "Xbox", icon: Gamepad2 },
  { key: "switch", label: "Nintendo Switch", icon: Gamepad2 },
  { key: "controller", label: "Controller", icon: Gamepad2 },
  { key: "storage", label: "Datenträger", icon: HardDrive },
  { key: "other", label: "Sonstiges", icon: CircleHelp },
];
const DEVICE_LABELS = Object.fromEntries(DEVICE_OPTIONS.map((item) => [item.key, item.label]));

const GENERAL_ISSUES = [
  "Startet nicht", "Abstürze / Fehlermeldungen", "Langsam / geringe Leistung", "Wird heiß oder laut",
  "Bild- oder Grafikfehler", "Anschluss funktioniert nicht", "Mechanischer Schaden", "Flüssigkeitsschaden",
  "Daten nicht erreichbar", "Fehler noch unklar",
];
const CONTROLLER_ISSUES = [
  "Stick Drift", "Taste reagiert nicht", "Trigger / Schultertaste defekt", "Verbindungsproblem",
  "Akku lädt nicht", "Gehäuse beschädigt", "Umbau statt Reparatur", "Fehler noch unklar",
];
const SERVICES = {
  repair: ["Fehlerdiagnose", "Reparatur", "Ersatzteiltausch", "Reinigung & Wartung", "Datenrettung", "Express-Prüfung"],
  pc_build: ["Gaming", "Office & Alltag", "Workstation / Produktivität", "Streaming", "Leiser Betrieb", "RGB / besonderes Design", "WLAN & Bluetooth", "Windows & Ersteinrichtung"],
  pc_upgrade: ["Arbeitsspeicher (RAM)", "SSD / mehr Speicher", "Grafikkarte", "Prozessor / Plattform", "Kühlung & Lautstärke", "Netzteil", "Reinigung & Wartung", "Windows / Datenübernahme"],
  controller_custom: ["Individuelles Design", "Frontschale", "Rückschale / Griffe", "Tasten & Steuerkreuz", "Sticks / Stickkappen", "Hall-Effect-Sticks", "Clicky Buttons / Trigger", "LED-Beleuchtung", "Rücktasten / Paddles", "Reparatur & Reinigung"],
  consulting: ["Kaufberatung", "Upgrade-Beratung", "Fehler-Einschätzung", "Daten & Backup", "Gaming-Setup", "Controller-Umbau"],
  other: ["Prüfung & Diagnose", "Beschaffung", "Einrichtung", "Umbau / Anpassung", "Wartung", "Beratung"],
};
const BUDGETS = {
  pc_build: ["Bis 800 €", "800–1.200 €", "1.200–1.800 €", "1.800–2.500 €", "Über 2.500 €", "Noch offen"],
  pc_upgrade: ["Bis 150 €", "150–300 €", "300–600 €", "600–1.000 €", "Über 1.000 €", "Noch offen"],
  controller_custom: ["Bis 100 €", "100–200 €", "200–350 €", "Über 350 €", "Noch offen"],
  default: ["Bis 100 €", "100–250 €", "250–500 €", "Über 500 €", "Noch offen"],
};
const TIMEFRAMES = ["So bald wie möglich", "Innerhalb von 2 Wochen", "Innerhalb eines Monats", "Zeitlich flexibel", "Erst unverbindlich informieren"];
const SOURCE_OPTIONS = [
  { key: "new_controller", label: "Neuen Controller mitbestellen", text: "Der Controller wird für den Umbau neu beschafft." },
  { key: "send_in", label: "Vorhandenen Controller einsenden", text: "Du sendest deinen eigenen Controller nach Absprache ein." },
  { key: "unsure", label: "Noch unsicher", text: "Wir klären gemeinsam, welche Variante sinnvoller ist." },
];

function createRequestId() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") return crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (char) => {
    const random = Math.floor(Math.random() * 16);
    return (char === "x" ? random : (random & 0x3) | 0x8).toString(16);
  });
}

function createInitialForm(requestType = "") {
  return {
    request_type: requestType,
    device_type: requestType === "pc_build" ? "pc" : requestType === "controller_custom" ? "controller" : "",
    controller_variant: "", device_source: "", manufacturer: "", model: "", issues: [],
    desired_services: [], budget: "", timeframe: "", description: "", attachment_ids: [], attachments: [],
    contact: {
      name: "", email: "", phone: "", preferred_contact: "email", contact_type: "private",
      company_name: "", address: "", postal_code: "", city: "", country_code: "AT",
      website: "", vat_id: "", company_registration: "", tax_number: "", court: "", eori: "",
    }, consent: false, honeypot: "",
  };
}

function ChoiceButton({ active, children, onClick, className = "", testId }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={active} data-testid={testId}
      className={`rounded-xl border p-4 text-left outline-none transition-all focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-surface ${active ? "border-brand bg-brand/10 text-ink" : "border-subtle text-muted hover:border-brand/50 hover:bg-elevated/50"} ${className}`}>
      {children}
    </button>
  );
}

function MultiChoice({ options, selected, onToggle, prefix }) {
  return (
    <div className="mt-5 flex flex-wrap gap-2">
      {options.map((option) => {
        const active = selected.includes(option);
        return (
          <button type="button" key={option} onClick={() => onToggle(option)} aria-pressed={active} data-testid={`${prefix}-${option}`}
            className={`rounded-full border px-4 py-2 text-sm outline-none transition-all focus-visible:ring-2 focus-visible:ring-brand ${active ? "border-brand bg-brand/10 text-brand" : "border-subtle text-muted hover:border-brand/50 hover:text-ink"}`}>
            {active && <CheckCircle2 size={14} className="mr-1.5 inline" />}{option}
          </button>
        );
      })}
    </div>
  );
}

function SummaryRow({ label, children }) {
  return (
    <div className="grid gap-1 border-b border-subtle py-3 last:border-b-0 sm:grid-cols-[10rem_1fr] sm:gap-4">
      <dt className="text-sm font-medium text-muted">{label}</dt>
      <dd className="min-w-0 whitespace-pre-wrap text-sm text-ink">{children || "–"}</dd>
    </div>
  );
}

export default function Reparatur() {
  const [searchParams] = useSearchParams();
  const requestedType = searchParams.get("type");
  const queryRequestType = REQUEST_TYPES.some((item) => item.key === requestedType) ? requestedType : "";
  const [requestId] = useState(createRequestId);
  const [step, setStep] = useState(0);
  const [form, setForm] = useState(() => createInitialForm(queryRequestType));
  const [uploading, setUploading] = useState(false);
  const [removingImageId, setRemovingImageId] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(null);
  const uploadLock = useRef(false);
  const formSessionRef = useRef(0);
  const stepContentRef = useRef(null);
  const focusStepAfterChange = useRef(false);

  useEffect(() => {
    formSessionRef.current += 1;
    setForm((current) => current.request_type === queryRequestType
      ? current
      : createInitialForm(queryRequestType));
    setStep(0);
    setDone(null);
    return () => { formSessionRef.current += 1; };
  }, [queryRequestType]);

  useEffect(() => {
    if (!focusStepAfterChange.current) return;
    const timer = window.setTimeout(() => {
      focusStepAfterChange.current = false;
      stepContentRef.current?.focus();
    }, 250);
    return () => window.clearTimeout(timer);
  }, [step]);

  const set = (patch) => setForm((current) => ({ ...current, ...patch }));
  const setContact = (key) => (event) => set({ contact: { ...form.contact, [key]: event.target.value } });
  const toggle = (field, value) => {
    const current = form[field];
    set({ [field]: current.includes(value) ? current.filter((item) => item !== value) : [...current, value] });
  };

  const request = REQUEST_TYPES.find((item) => item.key === form.request_type);
  const issueOptions = form.device_type === "controller" || form.request_type === "controller_custom" ? CONTROLLER_ISSUES : GENERAL_ISSUES;
  const serviceOptions = SERVICES[form.request_type] || SERVICES.other;
  const budgetOptions = BUDGETS[form.request_type] || BUDGETS.default;
  const showBudget = ["pc_build", "pc_upgrade", "controller_custom", "consulting"].includes(form.request_type);
  const showIssues = form.request_type === "repair";

  const selectRequestType = (requestType) => {
    const defaults = { pc_build: { device_type: "pc" }, controller_custom: { device_type: "controller" } };
    set({ request_type: requestType, device_type: defaults[requestType]?.device_type || "", device_source: "",
      controller_variant: "", manufacturer: "", model: "", issues: [], desired_services: [], budget: "", timeframe: "" });
  };

  const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.contact.email.trim());
  const nameValid = form.contact.name.trim().length >= 2;
  const phoneValid = form.contact.preferred_contact !== "phone" || Boolean(form.contact.phone.trim());
  const companyValid = form.contact.contact_type !== "business" || form.contact.company_name.trim().length >= 2;
  const canContinue = useMemo(() => {
    if (step === 0) return Boolean(form.request_type);
    if (step === 1) {
      if (form.request_type === "controller_custom") {
        const modelValid = form.controller_variant === "Anderer Controller"
          ? Boolean(form.manufacturer.trim() && form.model.trim())
          : Boolean(form.model);
        return Boolean(form.controller_variant && form.device_source && modelValid);
      }
      if (["repair", "pc_upgrade", "other"].includes(form.request_type)) return Boolean(form.device_type);
      return true;
    }
    if (step === 3) return form.description.trim().length >= 10;
    if (step === 5) return Boolean(nameValid && emailValid && phoneValid && companyValid && form.consent);
    return true;
  }, [companyValid, emailValid, form, nameValid, phoneValid, step]);

  const next = () => {
    if (!canContinue) return;
    if (step === 0) trackEvent("inquiry_started", { request_type: form.request_type });
    focusStepAfterChange.current = true;
    setStep((current) => Math.min(current + 1, STEPS.length - 1));
  };
  const back = () => {
    focusStepAfterChange.current = true;
    setStep((current) => Math.max(current - 1, 0));
  };

  const handleFiles = async (fileList) => {
    if (uploadLock.current) return;
    const allFiles = Array.from(fileList || []);
    const allowedTypes = new Set(["image/jpeg", "image/png", "image/webp", "image/gif"]);
    const available = 5 - form.attachment_ids.length;
    if (!available) return toast.error("Du kannst maximal 5 Fotos hochladen.");
    const files = allFiles.filter((file) => allowedTypes.has(file.type)).slice(0, available);
    if (!files.length) return;
    if (allFiles.length > files.length) toast.info("Es werden höchstens 5 Bilddateien mit je maximal 8 MB übernommen.");
    const tooLarge = files.find((file) => file.size > 8 * 1024 * 1024);
    if (tooLarge) return toast.error(`${tooLarge.name} ist größer als 8 MB.`);
    uploadLock.current = true;
    setUploading(true);
    const uploaded = [];
    const formSession = formSessionRef.current;
    try {
      for (const file of files) {
        const data = new FormData();
        data.append("file", file);
        data.append("request_id", requestId);
        const response = await api.post("/uploads/repair-attachment", data);
        const item = { id: response.data.id, url: response.data.url };
        if (formSession !== formSessionRef.current) {
          await api.delete(`/uploads/repair-attachment/${item.id}`, {
            params: { request_id: requestId },
          }).catch(() => {});
          return;
        }
        uploaded.push(item);
        // Keep every successful partial upload visible. If a later file in the
        // batch fails, the customer can still use or explicitly remove it.
        setForm((current) => ({
          ...current,
          attachment_ids: current.attachment_ids.includes(item.id)
            ? current.attachment_ids
            : [...current.attachment_ids, item.id],
          attachments: current.attachment_ids.includes(item.id)
            ? current.attachments
            : [...current.attachments, item.url],
        }));
      }
      toast.success(uploaded.length === 1 ? "Foto hochgeladen." : `${uploaded.length} Fotos hochgeladen.`);
    } catch (error) {
      const prefix = uploaded.length
        ? `${uploaded.length} Foto${uploaded.length === 1 ? " wurde" : "s wurden"} gespeichert. `
        : "";
      toast.error(`${prefix}${formatApiError(error.response?.data?.detail)}`);
    } finally {
      uploadLock.current = false;
      setUploading(false);
    }
  };

  const removeImage = async (index) => {
    const mediaId = form.attachment_ids[index];
    if (!mediaId || uploadLock.current) return;
    const removeFromForm = () => setForm((current) => {
      const currentIndex = current.attachment_ids.indexOf(mediaId);
      if (currentIndex < 0) return current;
      return {
        ...current,
        attachment_ids: current.attachment_ids.filter((_, itemIndex) => itemIndex !== currentIndex),
        attachments: current.attachments.filter((_, itemIndex) => itemIndex !== currentIndex),
      };
    });
    uploadLock.current = true;
    setRemovingImageId(mediaId);
    try {
      await api.delete(`/uploads/repair-attachment/${mediaId}`, { params: { request_id: requestId } });
      removeFromForm();
      toast.success("Foto entfernt.");
    } catch (error) {
      if (error.response?.status === 404) {
        removeFromForm();
        toast.info("Der abgelaufene Foto-Entwurf wurde aus der Auswahl entfernt.");
      } else {
        toast.error(formatApiError(error.response?.data?.detail));
      }
    } finally {
      uploadLock.current = false;
      setRemovingImageId("");
    }
  };

  const submit = async () => {
    if (!form.consent) return toast.error("Bitte stimme der Verarbeitung deiner Angaben zu.");
    setSubmitting(true);
    try {
      const payload = {
        request_id: requestId, request_type: form.request_type, device_type: form.device_type, device_source: form.device_source,
        manufacturer: form.manufacturer.trim(), model: form.model.trim(), issues: form.issues, desired_services: form.desired_services,
        budget: form.budget, timeframe: form.timeframe, description: form.description.trim(), attachment_ids: form.attachment_ids,
        contact: Object.fromEntries(Object.entries(form.contact).map(([key, value]) => [key, typeof value === "string" ? value.trim() : value])),
        consent: form.consent, honeypot: form.honeypot,
      };
      const { data } = await api.post("/inquiries", payload);
      setDone({ ref: data.ref || data.request_id || requestId, ticketRef: data.ticket_ref, ticketUrl: data.ticket_public_url });
      trackEvent("inquiry_submitted", { request_type: form.request_type });
    } catch (error) {
      toast.error(formatApiError(error.response?.data?.detail));
    } finally { setSubmitting(false); }
  };

  if (done) return (
    <>
      <Seo title="Anfrage gesendet" path="/anfrage" />
      <PageHero eyebrow="Anfrage" title="Anfrage erfolgreich gesendet" breadcrumbs={[{ name: "Start", to: "/" }, { name: "Anfrage" }]} />
      <section className="mx-auto max-w-lg px-4 py-16 text-center">
        <Card className="p-8 sm:p-10" data-testid="inquiry-success">
          <CheckCircle2 size={52} className="mx-auto text-emerald-400" />
          <h2 className="mt-4 font-heading text-2xl font-bold text-ink">Danke für deine Anfrage!</h2>
          <p className="mt-2 text-muted">Deine Referenznummer lautet:</p>
          <p className="mt-2 break-all font-mono text-xl font-bold text-brand sm:text-2xl">{done.ref}</p>
          <p className="mt-4 text-sm text-muted">Deine Angaben sind angekommen. Du erhältst nach der Prüfung eine persönliche Rückmeldung.</p>
          {done.ticketUrl && <a href={done.ticketUrl} target="_blank" rel="noopener noreferrer" className="mt-6 inline-flex min-h-10 items-center justify-center rounded-lg border border-brand px-4 py-2 text-sm font-medium text-brand transition-colors hover:bg-brand/10">{done.ticketRef ? `Dolibarr-Ticket ${done.ticketRef} öffnen` : "Ticketstatus öffnen"}</a>}
          <Button as={Link} to="/" className={done.ticketUrl ? "ml-0 mt-3 sm:ml-3 sm:mt-6" : "mt-6"}>Zur Startseite</Button>
        </Card>
      </section>
    </>
  );

  return (
    <>
      <Seo title="Unverbindliche Anfrage" description="Reparatur, PC-Neubau, Upgrade, Controller-Umbau oder Beratung unverbindlich anfragen. Mit Fotos und allen wichtigen Angaben für eine schnelle Einschätzung." path="/anfrage" />
      <PageHero eyebrow="Persönliche Anfrage" title="Was kann ich für dich tun?" subtitle="Wähle dein Anliegen und übermittle alle wichtigen Angaben in wenigen Schritten. Unverbindlich und ohne versteckte Bestellung." breadcrumbs={[{ name: "Start", to: "/" }, { name: "Anfrage" }]} />

      <section className="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-14 lg:px-8">
        <nav className="mb-8" aria-label={`Schritt ${step + 1} von ${STEPS.length}: ${STEPS[step]}`}>
          <div className="flex items-start justify-between">
            {STEPS.map((label, index) => (
              <React.Fragment key={label}>
                <div className="flex min-w-9 flex-col items-center">
                  <div aria-current={index === step ? "step" : undefined}
                    className={`flex h-9 w-9 items-center justify-center rounded-full border text-sm font-semibold transition-colors ${index < step ? "border-brand bg-brand text-white" : index === step ? "border-brand bg-brand/10 text-brand" : "border-subtle text-faint"}`}>
                    {index < step ? <CheckCircle2 size={16} /> : index + 1}
                  </div>
                  <span className={`mt-1.5 hidden text-center text-xs sm:block ${index === step ? "text-ink" : "text-faint"}`}>{label}</span>
                </div>
                {index < STEPS.length - 1 && <div className={`mx-1 mt-4 h-0.5 flex-1 ${index < step ? "bg-brand" : "bg-subtle"}`} />}
              </React.Fragment>
            ))}
          </div>
          <p className="mt-3 text-center text-sm text-muted sm:hidden">{STEPS[step]} · {step + 1} von {STEPS.length}</p>
        </nav>

        <Card className="p-5 sm:p-7 md:p-8">
          <input type="text" name="website" value={form.honeypot} onChange={(event) => set({ honeypot: event.target.value })} className="hidden" tabIndex={-1} autoComplete="off" aria-hidden="true" />
          <AnimatePresence mode="wait">
            <motion.div ref={stepContentRef} tabIndex={-1} key={step} initial={{ opacity: 0, x: 16 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -16 }} transition={{ duration: 0.2 }} className="outline-none">
              {step === 0 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Worum geht es?</h2>
                  <p className="mt-1 text-sm text-muted">Wähle die Kategorie, die am besten zu deinem Anliegen passt.</p>
                  <div className="mt-5 grid gap-3 sm:grid-cols-2">
                    {REQUEST_TYPES.map((item) => {
                      const Icon = item.icon; const active = form.request_type === item.key;
                      return <ChoiceButton key={item.key} active={active} onClick={() => selectRequestType(item.key)} testId={`request-type-${item.key}`}>
                        <span className="flex items-start gap-3"><span className={`mt-0.5 rounded-lg p-2 ${active ? "bg-brand text-white" : "bg-elevated text-brand"}`}><Icon size={20} /></span>
                          <span><span className="block font-semibold text-ink">{item.label}</span><span className="mt-1 block text-sm leading-relaxed">{item.text}</span></span></span>
                      </ChoiceButton>;
                    })}
                  </div>
                </div>
              )}

              {step === 1 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Details zu deinem {request?.label || "Anliegen"}</h2>
                  <p className="mt-1 text-sm text-muted">Damit die Anfrage von Anfang an richtig eingeordnet werden kann.</p>
                  {form.request_type === "controller_custom" ? (
                    <div className="mt-5 space-y-6">
                      <fieldset><legend className="text-sm font-medium text-muted">Controller-Modell *</legend>
                        <div className="mt-2 grid gap-3 sm:grid-cols-3">{["DualSense", "DualSense Edge", "Anderer Controller"].map((variant) => <ChoiceButton key={variant} active={form.controller_variant === variant} onClick={() => set(variant === "Anderer Controller" ? { controller_variant: variant, manufacturer: "", model: "" } : { controller_variant: variant, manufacturer: "Sony", model: variant })} className="text-center font-medium">{variant}</ChoiceButton>)}</div>
                      </fieldset>
                      <fieldset><legend className="text-sm font-medium text-muted">Controller für den Umbau *</legend>
                        <div className="mt-2 grid gap-3 sm:grid-cols-3">{SOURCE_OPTIONS.map((source) => <ChoiceButton key={source.key} active={form.device_source === source.key} onClick={() => set({ device_source: source.key })}><span className="block font-semibold text-ink">{source.label}</span><span className="mt-1 block text-xs leading-relaxed">{source.text}</span></ChoiceButton>)}</div>
                      </fieldset>
                      {form.controller_variant === "Anderer Controller" && <div className="grid gap-4 sm:grid-cols-2"><div><Label htmlFor="controller-manufacturer-detail">Hersteller *</Label><Input id="controller-manufacturer-detail" value={form.manufacturer} onChange={(event) => set({ manufacturer: event.target.value })} placeholder="z. B. Microsoft" /></div><div><Label htmlFor="controller-model-detail">Genaues Modell *</Label><Input id="controller-model-detail" value={form.model} onChange={(event) => set({ model: event.target.value })} placeholder="z. B. Xbox Elite Series 2" /></div></div>}
                    </div>
                  ) : form.request_type === "pc_build" ? (
                    <div className="mt-5 rounded-xl border border-brand/30 bg-brand/5 p-4"><div className="flex gap-3"><Sparkles className="shrink-0 text-brand" size={22} /><div><p className="font-semibold text-ink">Kompletter Desktop-PC</p><p className="mt-1 text-sm text-muted">Im nächsten Schritt wählst du Einsatzzweck, Budget und deine Wünsche. Konkrete Komponenten musst du noch nicht kennen.</p></div></div></div>
                  ) : (
                    <div className="mt-5 space-y-5">
                      <fieldset><legend className="text-sm font-medium text-muted">{form.request_type === "consulting" ? "Worum geht es hauptsächlich?" : "Gerätetyp *"}</legend>
                        <div className="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">{(form.request_type === "pc_upgrade" ? DEVICE_OPTIONS.filter((item) => ["pc", "notebook"].includes(item.key)) : DEVICE_OPTIONS).map((device) => { const Icon = device.icon; return <ChoiceButton key={device.key} active={form.device_type === device.key} onClick={() => set({ device_type: device.key, issues: form.device_type === device.key ? form.issues : [] })} className="text-center"><Icon size={22} className="mx-auto text-brand" /><span className="mt-2 block text-sm font-medium">{device.label}</span></ChoiceButton>; })}</div>
                      </fieldset>
                      <div className="grid gap-4 sm:grid-cols-2"><div><Label htmlFor="inquiry-manufacturer">Hersteller <span className="font-normal text-faint">(optional)</span></Label><Input id="inquiry-manufacturer" value={form.manufacturer} onChange={(event) => set({ manufacturer: event.target.value })} placeholder="z. B. ASUS, Sony, Lenovo" /></div><div><Label htmlFor="inquiry-model">Modell <span className="font-normal text-faint">(optional)</span></Label><Input id="inquiry-model" value={form.model} onChange={(event) => set({ model: event.target.value })} placeholder="z. B. ROG Strix, ThinkPad T14" /></div></div>
                    </div>
                  )}
                </div>
              )}

              {step === 2 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Was ist dir wichtig?</h2><p className="mt-1 text-sm text-muted">Mehrfachauswahl möglich. Wähle alles, was zu deiner Anfrage passt.</p>
                  {showIssues && <fieldset className="mt-6"><legend className="font-semibold text-ink">Problem oder Fehler</legend><MultiChoice options={issueOptions} selected={form.issues} onToggle={(value) => toggle("issues", value)} prefix="issue" /></fieldset>}
                  <fieldset className="mt-6"><legend className="font-semibold text-ink">{form.request_type === "pc_build" ? "Nutzung & Ausstattung" : "Gewünschte Leistungen"}</legend><MultiChoice options={serviceOptions} selected={form.desired_services} onToggle={(value) => toggle("desired_services", value)} prefix="service" /></fieldset>
                  <div className={`mt-7 grid gap-4 ${showBudget ? "sm:grid-cols-2" : ""}`}>
                    {showBudget && <div><Label htmlFor="inquiry-budget">Ungefährer Budgetrahmen</Label><Select id="inquiry-budget" value={form.budget} onChange={(event) => set({ budget: event.target.value })}><option value="">Noch nicht angegeben</option>{budgetOptions.map((budget) => <option key={budget} value={budget}>{budget}</option>)}</Select></div>}
                    <div><Label htmlFor="inquiry-timeframe">Gewünschter Zeitraum</Label><Select id="inquiry-timeframe" value={form.timeframe} onChange={(event) => set({ timeframe: event.target.value })}><option value="">Noch nicht angegeben</option>{TIMEFRAMES.map((timeframe) => <option key={timeframe} value={timeframe}>{timeframe}</option>)}</Select></div>
                  </div>
                </div>
              )}

              {step === 3 && (
                <div><h2 className="font-heading text-xl font-bold text-ink">Beschreibe dein Anliegen</h2><p className="mt-1 text-sm text-muted">Je genauer deine Angaben, desto besser lässt sich der Aufwand einschätzen.</p>
                  <Label htmlFor="inquiry-description" className="mt-5">Beschreibung *</Label><Textarea id="inquiry-description" className="min-h-[180px]" value={form.description} onChange={(event) => set({ description: event.target.value })} placeholder={form.request_type === "controller_custom" ? "Welche Farben, Oberflächen und Funktionen stellst du dir vor? Gibt es Vorbilder oder besondere Wünsche?" : "Was soll geprüft, gebaut oder verbessert werden? Was ist dir besonders wichtig?"} maxLength={4000} data-testid="inquiry-description" />
                  <div className="mt-2 flex justify-between text-xs text-faint"><span>{form.description.trim().length < 10 ? "Bitte mindestens 10 Zeichen eingeben." : "Danke, das hilft bei der Einschätzung."}</span><span>{form.description.length}/4000</span></div>
                </div>
              )}

              {step === 4 && (
                <div><h2 className="font-heading text-xl font-bold text-ink">Fotos hinzufügen</h2><p className="mt-1 text-sm text-muted">Optional: Bis zu 5 Fotos mit jeweils maximal 8 MB helfen bei Fehlern, Umbauten und Designwünschen.</p>
                  <label className={`mt-5 flex cursor-pointer flex-col items-center gap-2 rounded-xl border border-dashed p-8 text-center outline-none transition-colors focus-within:ring-2 focus-within:ring-brand ${form.attachment_ids.length >= 5 || uploading || removingImageId ? "cursor-not-allowed border-subtle opacity-50" : "border-subtle hover:border-brand/60 hover:bg-brand/5"}`} onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.preventDefault(); if (!uploading && !removingImageId) handleFiles(event.dataTransfer.files); }}>
                    {uploading ? <Loader2 className="animate-spin text-brand" size={28} /> : <Upload className="text-brand" size={28} />}<span className="font-medium text-ink">{uploading ? "Fotos werden hochgeladen …" : "Fotos auswählen oder hierher ziehen"}</span><span className="text-xs text-faint">JPG, PNG, WebP oder GIF · {form.attachment_ids.length}/5 hochgeladen</span>
                    <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple className="sr-only" disabled={uploading || Boolean(removingImageId) || form.attachment_ids.length >= 5} onChange={(event) => { handleFiles(event.target.files); event.target.value = ""; }} />
                  </label>
                  {form.attachments.length > 0 && <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">{form.attachments.map((url, index) => { const mediaId = form.attachment_ids[index]; const isRemoving = removingImageId === mediaId; return <div key={mediaId || `${url}-${index}`} className="group relative aspect-square overflow-hidden rounded-xl border border-subtle bg-elevated"><img src={mediaUrl(url)} alt={`Hochgeladenes Foto ${index + 1}`} className={`h-full w-full object-cover ${isRemoving ? "opacity-50" : ""}`} /><button type="button" onClick={() => removeImage(index)} disabled={Boolean(removingImageId) || uploading} className="absolute right-2 top-2 rounded-full bg-black/75 p-1.5 text-white opacity-90 outline-none transition-opacity hover:bg-red-600 focus-visible:ring-2 focus-visible:ring-white disabled:cursor-not-allowed disabled:opacity-50 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100" aria-label={`Foto ${index + 1} entfernen`}>{isRemoving ? <Loader2 className="animate-spin" size={15} /> : <X size={15} />}</button></div>; })}</div>}
                </div>
              )}

              {step === 5 && (
                <div><h2 className="font-heading text-xl font-bold text-ink">Wie kann ich dich erreichen?</h2><p className="mt-1 text-sm text-muted">Die Angaben werden ausschließlich zur Bearbeitung deiner Anfrage verwendet.</p>
                  <div className="mt-5 space-y-4">
                    <fieldset><legend className="text-sm font-medium text-muted">Anfrage als</legend><div className="mt-2 grid grid-cols-2 gap-3"><ChoiceButton active={form.contact.contact_type === "private"} onClick={() => set({ contact: { ...form.contact, contact_type: "private" } })} className="text-center font-medium">Privatperson</ChoiceButton><ChoiceButton active={form.contact.contact_type === "business"} onClick={() => set({ contact: { ...form.contact, contact_type: "business" } })} className="text-center font-medium">Unternehmen</ChoiceButton></div></fieldset>
                    {form.contact.contact_type === "business" && <div><Label htmlFor="inquiry-company">Unternehmensname *</Label><Input id="inquiry-company" autoComplete="organization" required value={form.contact.company_name} onChange={setContact("company_name")} aria-invalid={form.contact.company_name && !companyValid ? "true" : undefined} />{form.contact.company_name && !companyValid && <p className="mt-1 text-xs text-red-400">Bitte gib mindestens 2 Zeichen ein.</p>}</div>}
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div>
                        <Label htmlFor="inquiry-name">Name *</Label>
                        <Input id="inquiry-name" autoComplete="name" required value={form.contact.name} onChange={setContact("name")} aria-invalid={form.contact.name && !nameValid ? "true" : undefined} />
                        {form.contact.name && !nameValid && <p className="mt-1 text-xs text-red-400">Bitte gib mindestens 2 Zeichen ein.</p>}
                      </div>
                      <div>
                        <Label htmlFor="inquiry-email">E-Mail *</Label>
                        <Input id="inquiry-email" autoComplete="email" required type="email" value={form.contact.email} onChange={setContact("email")} aria-invalid={form.contact.email && !emailValid ? "true" : undefined} />
                        {form.contact.email && !emailValid && <p className="mt-1 text-xs text-red-400">Bitte gib eine gültige E-Mail-Adresse ein.</p>}
                      </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div>
                        <Label htmlFor="inquiry-phone">Telefon {form.contact.preferred_contact === "phone" ? "*" : <span className="font-normal text-faint">(optional)</span>}</Label>
                        <Input id="inquiry-phone" autoComplete="tel" inputMode="tel" required={form.contact.preferred_contact === "phone"} value={form.contact.phone} onChange={setContact("phone")} aria-invalid={!phoneValid ? "true" : undefined} />
                        {!phoneValid && <p className="mt-1 text-xs text-red-400">Für die bevorzugte Kontaktaufnahme per Telefon wird eine Telefonnummer benötigt.</p>}
                      </div>
                      <div>
                        <Label htmlFor="inquiry-contact-method">Bevorzugter Kontakt</Label>
                        <Select id="inquiry-contact-method" value={form.contact.preferred_contact} onChange={setContact("preferred_contact")}><option value="email">E-Mail</option><option value="phone">Telefon</option></Select>
                      </div>
                    </div>
                    <details className="rounded-xl border border-subtle p-4">
                      <summary className="cursor-pointer font-medium text-ink">Adresse und Firmendaten ergänzen <span className="font-normal text-faint">(optional)</span></summary>
                      <p className="mt-2 text-xs text-faint">Diese Angaben füllen den Dolibarr-Interessenten vollständiger aus und sind für eine Anfrage nicht erforderlich.</p>
                      <div className="mt-4 space-y-4">
                        <div><Label htmlFor="inquiry-address">Straße und Hausnummer</Label><Input id="inquiry-address" autoComplete="street-address" value={form.contact.address} onChange={setContact("address")} /></div>
                        <div className="grid gap-4 sm:grid-cols-[0.7fr_1.6fr_0.5fr]"><div><Label htmlFor="inquiry-postal">PLZ</Label><Input id="inquiry-postal" autoComplete="postal-code" value={form.contact.postal_code} onChange={setContact("postal_code")} /></div><div><Label htmlFor="inquiry-city">Ort</Label><Input id="inquiry-city" autoComplete="address-level2" value={form.contact.city} onChange={setContact("city")} /></div><div><Label htmlFor="inquiry-country">Land</Label><Input id="inquiry-country" autoComplete="country" maxLength={2} value={form.contact.country_code} onChange={(event) => set({ contact: { ...form.contact, country_code: event.target.value.toUpperCase() } })} /></div></div>
                        {form.contact.contact_type === "business" && <><div><Label htmlFor="inquiry-website">Website</Label><Input id="inquiry-website" type="url" autoComplete="url" value={form.contact.website} onChange={setContact("website")} placeholder="https://…" /></div><div className="grid gap-4 sm:grid-cols-2"><div><Label htmlFor="inquiry-vat">UID-Nummer</Label><Input id="inquiry-vat" value={form.contact.vat_id} onChange={setContact("vat_id")} placeholder="ATU…" /></div><div><Label htmlFor="inquiry-tax">Steuernummer</Label><Input id="inquiry-tax" value={form.contact.tax_number} onChange={setContact("tax_number")} /></div><div><Label htmlFor="inquiry-register">Firmenbuchnummer</Label><Input id="inquiry-register" value={form.contact.company_registration} onChange={setContact("company_registration")} /></div><div><Label htmlFor="inquiry-court">Gerichtsstand</Label><Input id="inquiry-court" value={form.contact.court} onChange={setContact("court")} /></div><div><Label htmlFor="inquiry-eori">EORI-Nummer</Label><Input id="inquiry-eori" value={form.contact.eori} onChange={setContact("eori")} /></div></div></>}
                      </div>
                    </details>
                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-subtle p-4 text-sm text-muted outline-none focus-within:ring-2 focus-within:ring-brand"><input type="checkbox" checked={form.consent} onChange={(event) => set({ consent: event.target.checked })} className="mt-0.5 h-4 w-4 shrink-0 accent-[#F26522]" /><span>Ich stimme der Verarbeitung meiner Angaben und hochgeladenen Fotos zur Bearbeitung dieser Anfrage zu. Weitere Informationen stehen in der <Link to="/datenschutz" target="_blank" rel="noreferrer" className="text-brand underline hover:text-brand-bright">Datenschutzerklärung</Link>. *</span></label>
                  </div>
                </div>
              )}

              {step === 6 && (
                <div><div className="flex items-start gap-3"><ShieldCheck size={28} className="mt-0.5 shrink-0 text-brand" /><div><h2 className="font-heading text-xl font-bold text-ink">Anfrage prüfen und senden</h2><p className="mt-1 text-sm text-muted">Kontrolliere deine Angaben. Mit „Anfrage senden“ wird noch kein kostenpflichtiger Auftrag erteilt.</p></div></div>
                  <dl className="mt-6 rounded-xl border border-subtle bg-elevated/30 px-4 sm:px-5"><SummaryRow label="Anfrage-ID">{requestId}</SummaryRow><SummaryRow label="Anliegen">{REQUEST_LABELS[form.request_type]}</SummaryRow><SummaryRow label="Gerät">{[DEVICE_LABELS[form.device_type], form.manufacturer, form.model].filter(Boolean).join(" · ")}</SummaryRow>{form.device_source && <SummaryRow label="Controller">{SOURCE_OPTIONS.find((item) => item.key === form.device_source)?.label}</SummaryRow>}{form.issues.length > 0 && <SummaryRow label="Fehler">{form.issues.join(", ")}</SummaryRow>}<SummaryRow label="Wünsche">{form.desired_services.join(", ")}</SummaryRow>{showBudget && <SummaryRow label="Budget">{form.budget}</SummaryRow>}<SummaryRow label="Zeitraum">{form.timeframe}</SummaryRow><SummaryRow label="Beschreibung">{form.description}</SummaryRow><SummaryRow label="Fotos">{form.attachment_ids.length ? `${form.attachment_ids.length} hochgeladen` : "Keine Fotos"}</SummaryRow><SummaryRow label="Kontakt">{`${form.contact.contact_type === "business" ? `${form.contact.company_name}\n` : ""}${form.contact.name}\n${form.contact.email}${form.contact.phone ? ` · ${form.contact.phone}` : ""}\nBevorzugt per ${form.contact.preferred_contact === "phone" ? "Telefon" : "E-Mail"}`}</SummaryRow>{(form.contact.address || form.contact.city) && <SummaryRow label="Adresse">{`${form.contact.address}${form.contact.address ? "\n" : ""}${form.contact.postal_code} ${form.contact.city}\n${form.contact.country_code}`}</SummaryRow>}</dl>
                  <div className="mt-4 flex items-start gap-2 rounded-xl border border-emerald-500/25 bg-emerald-500/5 p-3 text-sm text-emerald-300"><CheckCircle2 size={18} className="mt-0.5 shrink-0" /><span>Unverbindliche Anfrage – ein Preis oder Auftrag entsteht erst nach persönlicher Abstimmung und deiner ausdrücklichen Zustimmung.</span></div>
                </div>
              )}
            </motion.div>
          </AnimatePresence>

          <div className="mt-8 flex items-center justify-between gap-3 border-t border-subtle pt-5"><Button type="button" variant="ghost" onClick={back} disabled={step === 0 || submitting || uploading || Boolean(removingImageId)} data-testid="inquiry-back"><ArrowLeft size={16} /> Zurück</Button>{step < STEPS.length - 1 ? <Button type="button" onClick={next} disabled={!canContinue || uploading || Boolean(removingImageId)} data-testid="inquiry-next">Weiter <ArrowRight size={16} /></Button> : <Button type="button" onClick={submit} disabled={submitting || uploading || Boolean(removingImageId)} data-testid="inquiry-submit">{submitting ? <Loader2 className="animate-spin" size={16} /> : <Send size={16} />}{submitting ? "Wird gesendet …" : "Anfrage senden"}</Button>}</div>
        </Card>
        <div className="mt-5 grid gap-3 text-sm text-muted sm:grid-cols-3"><div className="flex items-center gap-2"><BriefcaseBusiness size={17} className="text-brand" /> Persönlich geprüft</div><div className="flex items-center gap-2"><ImagePlus size={17} className="text-brand" /> Fotos sicher zugeordnet</div><div className="flex items-center gap-2"><ShieldCheck size={17} className="text-brand" /> Noch kein Auftrag</div></div>
      </section>
    </>
  );
}

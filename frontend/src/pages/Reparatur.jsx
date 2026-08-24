import React, { useState } from "react";
import { Link } from "react-router-dom";
import { motion, AnimatePresence } from "framer-motion";
import {
  Monitor, Laptop, Gamepad2, Gamepad, HelpCircle, ArrowLeft, ArrowRight,
  Upload, X, CheckCircle2, Send, Loader2,
} from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError, mediaUrl } from "../lib/api";
import { DEVICE_TYPES, COMMON_ISSUES } from "../lib/content";
import { trackEvent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Input, Textarea, Label, Select } from "../components/ui/input";
import Seo from "../components/Seo";
import PageHero from "../components/PageHero";

const DEVICE_ICONS = { pc: Monitor, notebook: Laptop, playstation: Gamepad2, xbox: Gamepad2, switch: Gamepad, controller: Gamepad, sonstiges: HelpCircle };
const STEPS = ["Gerät", "Modell", "Fehler", "Beschreibung", "Bilder", "Kontakt"];

export default function Reparatur() {
  const [step, setStep] = useState(0);
  const [form, setForm] = useState({
    device_type: "", manufacturer: "", model: "", issues: [], description: "",
    attachment_ids: [], attachments: [],
    contact: { name: "", email: "", phone: "", preferred_contact: "email" },
    consent: false, honeypot: "",
  });
  const [uploading, setUploading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(null);

  const set = (patch) => setForm((f) => ({ ...f, ...patch }));
  const setContact = (k) => (e) => set({ contact: { ...form.contact, [k]: e.target.value } });

  const toggleIssue = (issue) =>
    set({ issues: form.issues.includes(issue) ? form.issues.filter((x) => x !== issue) : [...form.issues, issue] });

  const canNext = () => {
    if (step === 0) return !!form.device_type;
    if (step === 5) return form.contact.name && form.contact.email && form.consent;
    return true;
  };

  const next = () => {
    if (step === 0) trackEvent("repair_request_started", { device: form.device_type });
    setStep((s) => Math.min(s + 1, STEPS.length - 1));
  };
  const back = () => setStep((s) => Math.max(s - 1, 0));

  const onFiles = async (e) => {
    const files = Array.from(e.target.files || []);
    if (!files.length) return;
    setUploading(true);
    try {
      for (const file of files.slice(0, 5)) {
        const fd = new FormData();
        fd.append("file", file);
        const { data } = await api.post("/uploads/repair-attachment", fd);
        set({
          attachment_ids: [...form.attachment_ids, data.id],
          attachments: [...form.attachments, data.url],
        });
      }
      toast.success("Bild(er) hochgeladen.");
    } catch (err) {
      toast.error(formatApiError(err.response?.data?.detail));
    } finally {
      setUploading(false);
    }
  };

  const removeImage = (idx) =>
    set({
      attachment_ids: form.attachment_ids.filter((_, i) => i !== idx),
      attachments: form.attachments.filter((_, i) => i !== idx),
    });

  const submit = async () => {
    if (!form.consent) return toast.error("Bitte stimme dem Datenschutz zu.");
    setSubmitting(true);
    try {
      const payload = {
        device_type: form.device_type, manufacturer: form.manufacturer, model: form.model,
        issues: form.issues, description: form.description, attachment_ids: form.attachment_ids,
        contact: form.contact, consent: form.consent, honeypot: form.honeypot,
      };
      const { data } = await api.post("/repairs", payload);
      setDone(data.ref);
      trackEvent("repair_request_submitted", { device: form.device_type });
    } catch (err) {
      toast.error(formatApiError(err.response?.data?.detail));
    } finally {
      setSubmitting(false);
    }
  };

  if (done) {
    return (
      <>
        <Seo title="Reparaturanfrage gesendet" path="/reparatur" />
        <PageHero eyebrow="Reparatur" title="Anfrage erfolgreich gesendet" breadcrumbs={[{ name: "Start", to: "/" }, { name: "Reparatur" }]} />
        <section className="mx-auto max-w-lg px-4 py-16 text-center">
          <Card className="p-10" data-testid="repair-success">
            <CheckCircle2 size={52} className="mx-auto text-emerald-400" />
            <h2 className="mt-4 font-heading text-2xl font-bold text-ink">Danke für deine Anfrage!</h2>
            <p className="mt-2 text-muted">Deine Referenznummer lautet:</p>
            <p className="mt-2 font-mono text-2xl font-bold text-brand">{done}</p>
            <p className="mt-4 text-sm text-muted">Ich prüfe deine Anfrage und melde mich mit einer ehrlichen Einschätzung.</p>
            <Button as={Link} to="/" className="mt-6">Zur Startseite</Button>
          </Card>
        </section>
      </>
    );
  }

  return (
    <>
      <Seo
        title="Reparatur anfragen"
        description="Reparaturanfrage in wenigen Schritten: Gerät, Fehler, Bilder und Kontakt. Du erhältst eine ehrliche Einschätzung."
        path="/reparatur"
      />
      <PageHero
        eyebrow="Reparatur"
        title="Reparatur anfragen"
        subtitle="In wenigen Schritten zu deiner unverbindlichen Anfrage."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Reparatur" }]}
      />

      <section className="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
        {/* progress */}
        <div className="mb-8 flex items-center justify-between">
          {STEPS.map((s, i) => (
            <div key={s} className="flex flex-1 items-center">
              <div className="flex flex-col items-center">
                <div className={`flex h-9 w-9 items-center justify-center rounded-full border text-sm font-semibold transition-colors ${
                  i < step ? "border-brand bg-brand text-white" : i === step ? "border-brand text-brand" : "border-subtle text-faint"
                }`}>
                  {i < step ? <CheckCircle2 size={16} /> : i + 1}
                </div>
                <span className={`mt-1.5 hidden text-xs sm:block ${i === step ? "text-ink" : "text-faint"}`}>{s}</span>
              </div>
              {i < STEPS.length - 1 && <div className={`mx-1 h-0.5 flex-1 ${i < step ? "bg-brand" : "bg-subtle"}`} />}
            </div>
          ))}
        </div>

        <Card className="p-6 md:p-8">
          <input type="text" value={form.honeypot} onChange={(e) => set({ honeypot: e.target.value })} className="hidden" tabIndex={-1} aria-hidden="true" />
          <AnimatePresence mode="wait">
            <motion.div key={step} initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }} transition={{ duration: 0.25 }}>
              {step === 0 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Welches Gerät?</h2>
                  <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {DEVICE_TYPES.map((d) => {
                      const Icon = DEVICE_ICONS[d.key] || HelpCircle;
                      const active = form.device_type === d.key;
                      return (
                        <button
                          key={d.key}
                          onClick={() => set({ device_type: d.key })}
                          className={`flex flex-col items-center gap-2 rounded-xl border p-4 transition-all ${
                            active ? "border-brand bg-brand/10 text-brand" : "border-subtle text-muted hover:border-brand/50"
                          }`}
                          data-testid={`device-${d.key}`}
                        >
                          <Icon size={26} />
                          <span className="text-sm font-medium">{d.label}</span>
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}

              {step === 1 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Hersteller & Modell</h2>
                  <p className="mt-1 text-sm text-muted">Optional – hilft bei der Einschätzung.</p>
                  <div className="mt-5 space-y-4">
                    <div><Label>Hersteller</Label><Input value={form.manufacturer} onChange={(e) => set({ manufacturer: e.target.value })} placeholder="z. B. ASUS, Sony, Lenovo" data-testid="repair-manufacturer" /></div>
                    <div><Label>Modell</Label><Input value={form.model} onChange={(e) => set({ model: e.target.value })} placeholder="z. B. ROG Strix, PS5 Slim" data-testid="repair-model" /></div>
                  </div>
                </div>
              )}

              {step === 2 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Welcher Fehler tritt auf?</h2>
                  <p className="mt-1 text-sm text-muted">Mehrfachauswahl möglich.</p>
                  <div className="mt-5 flex flex-wrap gap-2">
                    {COMMON_ISSUES.map((issue) => {
                      const active = form.issues.includes(issue);
                      return (
                        <button
                          key={issue}
                          onClick={() => toggleIssue(issue)}
                          className={`rounded-full border px-4 py-2 text-sm transition-all ${
                            active ? "border-brand bg-brand/10 text-brand" : "border-subtle text-muted hover:border-brand/50"
                          }`}
                          data-testid={`issue-${issue}`}
                        >
                          {issue}
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}

              {step === 3 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Beschreibung</h2>
                  <p className="mt-1 text-sm text-muted">Was passiert genau? Seit wann?</p>
                  <Textarea className="mt-4" value={form.description} onChange={(e) => set({ description: e.target.value })} placeholder="Beschreibe das Problem so genau wie möglich ..." data-testid="repair-description" />
                </div>
              )}

              {step === 4 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Bilder (optional)</h2>
                  <p className="mt-1 text-sm text-muted">Fotos helfen bei der Einschätzung. Max. 5 Bilder, je 8 MB.</p>
                  <label className="mt-4 flex cursor-pointer flex-col items-center gap-2 rounded-xl border border-dashed border-subtle p-8 text-center transition-colors hover:border-brand/50" data-testid="repair-upload-label">
                    {uploading ? <Loader2 className="animate-spin text-brand" size={26} /> : <Upload size={26} className="text-brand" />}
                    <span className="text-sm text-muted">{uploading ? "Wird hochgeladen ..." : "Bilder auswählen oder hierher ziehen"}</span>
                    <input type="file" accept="image/*" multiple className="hidden" onChange={onFiles} data-testid="repair-file-input" />
                  </label>
                  {form.attachments.length > 0 && (
                    <div className="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
                      {form.attachments.map((url, i) => (
                        <div key={i} className="group relative aspect-square overflow-hidden rounded-lg border border-subtle">
                          <img src={mediaUrl(url)} alt={`Anhang ${i + 1}`} className="h-full w-full object-cover" />
                          <button onClick={() => removeImage(i)} className="absolute right-1 top-1 rounded-full bg-black/70 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100" aria-label="Entfernen">
                            <X size={14} />
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {step === 5 && (
                <div>
                  <h2 className="font-heading text-xl font-bold text-ink">Kontaktdaten</h2>
                  <div className="mt-5 space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div><Label>Name *</Label><Input required value={form.contact.name} onChange={setContact("name")} data-testid="repair-name" /></div>
                      <div><Label>E-Mail *</Label><Input required type="email" value={form.contact.email} onChange={setContact("email")} data-testid="repair-email" /></div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div><Label>Telefon (optional)</Label><Input value={form.contact.phone} onChange={setContact("phone")} data-testid="repair-phone" /></div>
                      <div>
                        <Label>Bevorzugter Kontakt</Label>
                        <Select value={form.contact.preferred_contact} onChange={setContact("preferred_contact")} data-testid="repair-contact-method">
                          <option value="email">E-Mail</option>
                          <option value="phone">Telefon</option>
                        </Select>
                      </div>
                    </div>
                    <label className="flex items-start gap-3 text-sm text-muted">
                      <input type="checkbox" checked={form.consent} onChange={(e) => set({ consent: e.target.checked })} className="mt-1 h-4 w-4 accent-[#F26522]" data-testid="repair-consent" />
                      <span>Ich stimme der Verarbeitung meiner Angaben zur Bearbeitung der Anfrage zu (Datenschutz).</span>
                    </label>
                  </div>
                </div>
              )}
            </motion.div>
          </AnimatePresence>

          <div className="mt-8 flex items-center justify-between">
            <Button variant="ghost" onClick={back} disabled={step === 0} data-testid="repair-back">
              <ArrowLeft size={16} /> Zurück
            </Button>
            {step < STEPS.length - 1 ? (
              <Button onClick={next} disabled={!canNext()} data-testid="repair-next">
                Weiter <ArrowRight size={16} />
              </Button>
            ) : (
              <Button onClick={submit} disabled={!canNext() || submitting} data-testid="repair-submit">
                {submitting ? <Loader2 className="animate-spin" size={16} /> : <Send size={16} />} Anfrage senden
              </Button>
            )}
          </div>
        </Card>
      </section>
    </>
  );
}

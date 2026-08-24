import React, { useState } from "react";
import { Mail, Phone, MapPin, Send, CheckCircle2 } from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError } from "../lib/api";
import { useSettings } from "../context/SettingsContext";
import { trackEvent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Input, Textarea, Label } from "../components/ui/input";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

const empty = { name: "", email: "", phone: "", subject: "", message: "", consent: false, honeypot: "" };

export default function Kontakt() {
  const { settings } = useSettings();
  const [form, setForm] = useState(empty);
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);

  const upd = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === "checkbox" ? e.target.checked : e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    if (!form.consent) return toast.error("Bitte stimme dem Datenschutz zu.");
    setLoading(true);
    try {
      await api.post("/contact", form);
      setSent(true);
      trackEvent("contact_form_submitted");
      toast.success("Nachricht gesendet. Ich melde mich zeitnah.");
      setForm(empty);
    } catch (err) {
      toast.error(formatApiError(err.response?.data?.detail));
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <Seo
        title="Kontakt"
        description="Kontaktiere IT-Tabelander für Reparatur, Beratung oder ein individuelles Gaming-System."
        path="/kontakt"
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "Kontakt", path: "/kontakt" }])}
      />
      <PageHero
        eyebrow="Kontakt"
        title="Sag mir, wie ich helfen kann"
        subtitle="Schreib mir dein Anliegen – ich melde mich mit einer ehrlichen Einschätzung."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Kontakt" }]}
      />

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="grid gap-10 lg:grid-cols-[1fr_1.4fr]">
          <div className="space-y-4">
            <Card className="p-6">
              <h2 className="font-heading text-lg font-semibold text-ink">Direkt erreichen</h2>
              <ul className="mt-4 space-y-3 text-sm">
                {settings.email && (
                  <li className="flex items-center gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand/10 text-brand"><Mail size={16} /></span>
                    <a href={`mailto:${settings.email}`} className="text-muted hover:text-brand" onClick={() => trackEvent("email_clicked")}>{settings.email}</a>
                  </li>
                )}
                {settings.phone && (
                  <li className="flex items-center gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand/10 text-brand"><Phone size={16} /></span>
                    <a href={`tel:${settings.phone}`} className="text-muted hover:text-brand" onClick={() => trackEvent("phone_clicked")}>{settings.phone}</a>
                  </li>
                )}
                <li className="flex items-center gap-3">
                  <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand/10 text-brand"><MapPin size={16} /></span>
                  <span className="text-muted">{settings.service_area || "Tirol & Österreich"}</span>
                </li>
              </ul>
              {!settings.email && !settings.phone && (
                <p className="mt-3 text-xs text-faint">Weitere Kontaktdaten werden derzeit gepflegt – nutze einfach das Formular.</p>
              )}
            </Card>
            {settings.opening_hours?.length > 0 && (
              <Card className="p-6">
                <h3 className="font-heading text-base font-semibold text-ink">Öffnungszeiten</h3>
                <ul className="mt-3 space-y-1.5 text-sm text-muted">
                  {settings.opening_hours.map((o, i) => (
                    <li key={i} className="flex justify-between"><span>{o.day}</span><span>{o.hours}</span></li>
                  ))}
                </ul>
              </Card>
            )}
          </div>

          <Card className="p-6 md:p-8">
            {sent ? (
              <div className="flex flex-col items-center py-10 text-center" data-testid="contact-success">
                <CheckCircle2 size={48} className="text-emerald-400" />
                <h3 className="mt-4 font-heading text-xl font-bold text-ink">Danke für deine Nachricht!</h3>
                <p className="mt-2 text-muted">Ich melde mich so bald wie möglich bei dir.</p>
                <Button className="mt-6" variant="outline" onClick={() => setSent(false)}>Weitere Nachricht senden</Button>
              </div>
            ) : (
              <form onSubmit={submit} className="space-y-4" data-testid="contact-form">
                <input type="text" value={form.honeypot} onChange={upd("honeypot")} className="hidden" tabIndex={-1} autoComplete="off" aria-hidden="true" />
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label>Name *</Label>
                    <Input required value={form.name} onChange={upd("name")} data-testid="contact-name" placeholder="Dein Name" />
                  </div>
                  <div>
                    <Label>E-Mail *</Label>
                    <Input required type="email" value={form.email} onChange={upd("email")} data-testid="contact-email" placeholder="name@beispiel.at" />
                  </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label>Telefon (optional)</Label>
                    <Input value={form.phone} onChange={upd("phone")} data-testid="contact-phone" placeholder="+43 ..." />
                  </div>
                  <div>
                    <Label>Betreff</Label>
                    <Input value={form.subject} onChange={upd("subject")} data-testid="contact-subject" placeholder="Worum geht's?" />
                  </div>
                </div>
                <div>
                  <Label>Nachricht *</Label>
                  <Textarea required value={form.message} onChange={upd("message")} data-testid="contact-message" placeholder="Beschreibe dein Anliegen ..." />
                </div>
                <label className="flex items-start gap-3 text-sm text-muted">
                  <input type="checkbox" checked={form.consent} onChange={upd("consent")} className="mt-1 h-4 w-4 accent-[#F26522]" data-testid="contact-consent" />
                  <span>Ich stimme zu, dass meine Angaben zur Bearbeitung meiner Anfrage verarbeitet werden (Datenschutz).</span>
                </label>
                <Button type="submit" size="lg" disabled={loading} className="w-full sm:w-auto" data-testid="contact-submit">
                  <Send size={17} /> {loading ? "Wird gesendet ..." : "Nachricht senden"}
                </Button>
              </form>
            )}
          </Card>
        </div>
      </section>
    </>
  );
}

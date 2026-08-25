import React from "react";
import { Link } from "react-router-dom";
import { Mail, MapPin, Phone, Send } from "lucide-react";
import { useSettings } from "../context/SettingsContext";
import { trackEvent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

export default function Kontakt() {
  const { settings } = useSettings();

  return (
    <>
      <Seo
        title="Kontakt"
        description="Kontaktiere IT-Tabelander direkt oder sende eine strukturierte Anfrage für Reparatur, Beratung, PC-Projekt oder Controller-Umbau."
        path="/kontakt"
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "Kontakt", path: "/kontakt" }])}
      />
      <PageHero
        eyebrow="Kontakt"
        title="Sag mir, wie ich helfen kann"
        subtitle="Für eine schnelle Einschätzung führt dich das Anfrageformular durch alle wichtigen Angaben."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Kontakt" }]}
      />

      <section className="mx-auto max-w-5xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="grid gap-6 lg:grid-cols-2">
          <Card className="p-6 md:p-8">
            <h2 className="font-heading text-xl font-semibold text-ink">Direkt erreichen</h2>
            <ul className="mt-5 space-y-4 text-sm">
              {settings.email && (
                <li className="flex items-center gap-3">
                  <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand/10 text-brand"><Mail size={17} /></span>
                  <a href={`mailto:${settings.email}`} className="text-muted hover:text-brand" onClick={() => trackEvent("email_clicked")}>{settings.email}</a>
                </li>
              )}
              {settings.phone && (
                <li className="flex items-center gap-3">
                  <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand/10 text-brand"><Phone size={17} /></span>
                  <a href={`tel:${settings.phone}`} className="text-muted hover:text-brand" onClick={() => trackEvent("phone_clicked")}>{settings.phone}</a>
                </li>
              )}
              <li className="flex items-center gap-3">
                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand/10 text-brand"><MapPin size={17} /></span>
                <span className="text-muted">{settings.service_area || "Tirol & Österreich"}</span>
              </li>
            </ul>
            {!settings.email && !settings.phone && (
              <p className="mt-4 text-sm text-faint">Die direkten Kontaktdaten werden derzeit gepflegt. Das Anfrageformular ist bereits verfügbar.</p>
            )}
            {settings.opening_hours?.length > 0 && (
              <div className="mt-7 border-t border-subtle pt-5">
                <h3 className="font-heading font-semibold text-ink">Öffnungszeiten</h3>
                <ul className="mt-3 space-y-1.5 text-sm text-muted">
                  {settings.opening_hours.map((opening, index) => (
                    <li key={`${opening.day}-${index}`} className="flex justify-between gap-4"><span>{opening.day}</span><span>{opening.hours}</span></li>
                  ))}
                </ul>
              </div>
            )}
          </Card>

          <Card className="flex flex-col justify-center p-6 md:p-8">
            <span className="text-sm font-semibold uppercase tracking-[0.18em] text-brand">Zentrale Anfrage</span>
            <h2 className="mt-3 font-heading text-2xl font-bold text-ink">Alle Angaben an einem Ort</h2>
            <p className="mt-3 leading-relaxed text-muted">
              Wähle Reparatur, PC-Neubau, Upgrade, Controller-Umbau, Beratung oder Sonstiges.
              Du kannst Wünsche, Budget, Zeitraum und bis zu fünf Fotos direkt mitsenden.
            </p>
            <p className="mt-3 text-sm text-faint">Unverbindlich: Ein Auftrag entsteht erst nach persönlicher Abstimmung.</p>
            <Button as={Link} to="/anfrage" size="lg" className="mt-7 w-full sm:w-auto" data-testid="contact-inquiry-cta">
              <Send size={18} /> Anfrage starten
            </Button>
          </Card>
        </div>
      </section>
    </>
  );
}

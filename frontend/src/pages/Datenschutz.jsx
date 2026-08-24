import React from "react";
import { AlertTriangle } from "lucide-react";
import { useSettings } from "../context/SettingsContext";
import { useConsent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import Seo from "../components/Seo";
import PageHero from "../components/PageHero";

export default function Datenschutz() {
  const { settings } = useSettings();
  const { consent, acceptAll, rejectAll } = useConsent();

  return (
    <>
      <Seo title="Datenschutzerklärung" path="/datenschutz" description="Datenschutzerklärung von IT-Tabelander." />
      <PageHero eyebrow="Rechtliches" title="Datenschutzerklärung" breadcrumbs={[{ name: "Start", to: "/" }, { name: "Datenschutz" }]} />
      <section className="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="mb-8 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-300">
          <AlertTriangle size={18} className="mt-0.5 shrink-0" />
          <p>Diese Datenschutzerklärung ist ein Entwurf und vom Betreiber rechtlich zu prüfen und zu vervollständigen.</p>
        </div>

        {settings.datenschutz_html ? (
          <div className="space-y-3 text-muted" dangerouslySetInnerHTML={{ __html: settings.datenschutz_html }} />
        ) : (
          <div className="space-y-6 text-muted">
            <div>
              <h2 className="font-heading text-lg font-semibold text-ink">Verantwortlicher</h2>
              <p className="mt-2">{settings.company_name || "IT-Tabelander"}{settings.email ? `, ${settings.email}` : ""}. Weitere Angaben siehe Impressum.</p>
            </div>
            <div>
              <h2 className="font-heading text-lg font-semibold text-ink">Kontakt- & Reparaturformulare</h2>
              <p className="mt-2">Die eingegebenen Daten (Name, E-Mail, ggf. Telefon, Beschreibung, optionale Bilder) werden ausschließlich zur Bearbeitung deiner Anfrage verarbeitet und nicht ohne Grund weitergegeben.</p>
            </div>
            <div>
              <h2 className="font-heading text-lg font-semibold text-ink">Cookies & Statistik</h2>
              <p className="mt-2">Es werden nur notwendige Cookies gesetzt. Statistik-Dienste wie Google Analytics werden ausschließlich nach deiner ausdrücklichen Zustimmung geladen. Du kannst deine Einwilligung jederzeit unten anpassen.</p>
            </div>
          </div>
        )}

        <div id="cookies" className="mt-12 scroll-mt-24 rounded-2xl border border-subtle bg-surface/40 p-6">
          <h2 className="font-heading text-lg font-semibold text-ink">Cookie-Einstellungen</h2>
          <p className="mt-2 text-sm text-muted">
            Aktueller Status:{" "}
            <span className="font-semibold text-ink">
              {consent ? (consent.statistics ? "Statistik erlaubt" : "Nur notwendige Cookies") : "Keine Auswahl getroffen"}
            </span>
          </p>
          <div className="mt-4 flex gap-3">
            <Button size="sm" onClick={acceptAll} data-testid="ds-accept-all">Alle akzeptieren</Button>
            <Button size="sm" variant="outline" onClick={rejectAll} data-testid="ds-reject-all">Nur notwendige</Button>
          </div>
        </div>
      </section>
    </>
  );
}

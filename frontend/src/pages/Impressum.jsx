import React from "react";
import { AlertTriangle } from "lucide-react";
import { useSettings } from "../context/SettingsContext";
import Seo from "../components/Seo";
import PageHero from "../components/PageHero";

export default function Impressum() {
  const { settings } = useSettings();
  const hasData = settings.company_name && (settings.address || settings.email);

  return (
    <>
      <Seo title="Impressum" path="/impressum" description="Impressum von IT-Tabelander." />
      <PageHero eyebrow="Rechtliches" title="Impressum" breadcrumbs={[{ name: "Start", to: "/" }, { name: "Impressum" }]} />
      <section className="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="mb-8 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-300">
          <AlertTriangle size={18} className="mt-0.5 shrink-0" />
          <p>Diese Angaben sind vom Betreiber zu prüfen und zu vervollständigen. Sie werden im Admin-Bereich gepflegt.</p>
        </div>

        {settings.impressum_html ? (
          <div className="prose-invert space-y-3 text-muted" dangerouslySetInnerHTML={{ __html: settings.impressum_html }} />
        ) : (
          <div className="space-y-4 text-muted">
            <div>
              <h2 className="font-heading text-lg font-semibold text-ink">Angaben gemäß § 5 ECG / § 25 MedienG</h2>
              <p className="mt-2">{settings.company_name || "IT-Tabelander"}</p>
              {settings.address && <p>{settings.address}</p>}
              {(settings.postal_code || settings.city) && <p>{settings.postal_code} {settings.city}</p>}
              {settings.country && <p>{settings.country}</p>}
              {!settings.address && <p className="text-faint">Adresse: wird im Admin-Bereich gepflegt.</p>}
            </div>
            <div>
              <h3 className="font-heading text-base font-semibold text-ink">Kontakt</h3>
              {settings.email ? <p>E-Mail: {settings.email}</p> : <p className="text-faint">E-Mail: wird im Admin-Bereich gepflegt.</p>}
              {settings.phone && <p>Telefon: {settings.phone}</p>}
            </div>
          </div>
        )}
      </section>
    </>
  );
}

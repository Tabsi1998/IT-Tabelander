import React from "react";
import { Link } from "react-router-dom";
import { Cpu, Gauge, ShieldCheck, CheckCircle2 } from "lucide-react";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import Reveal from "../components/Reveal";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";
import { IMAGES } from "../lib/content";

const TYPES = [
  { title: "Gaming-PC", desc: "Hohe FPS, leiser Betrieb, saubere Verkabelung." },
  { title: "Office-PC", desc: "Zuverlässig, sparsam und flott im Alltag." },
  { title: "Workstation", desc: "Rechenleistung für Kreativ- & Profi-Software." },
  { title: "Multimedia-PC", desc: "Für Wohnzimmer, Streaming und Medien." },
];

const STEPS = [
  { icon: Cpu, t: "Komponenten wählen", d: "Konfiguriere deinen PC im Konfigurator oder lass dich beraten." },
  { icon: ShieldCheck, t: "Kompatibilität prüfen", d: "Jede Konfiguration wird vor Bestellung geprüft und freigegeben." },
  { icon: Gauge, t: "Bau & Stresstest", d: "Sauberer Zusammenbau, Systemtest und Optimierung." },
];

export default function GamingPCInfo() {
  return (
    <>
      <Seo
        title="Gaming-PC & individueller PC-Bau"
        description="Individueller PC-Bau in Tirol: Gaming-PC, Office-PC, Workstation und Multimedia-PC. Kompatibilität wird vor Bestellung geprüft und freigegeben."
        path="/gaming-pc"
        image={IMAGES.hero}
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "Gaming-PC", path: "/gaming-pc" }])}
      />
      <PageHero
        eyebrow="PC-Bau"
        title="Individuelle PCs nach deinem Einsatzzweck"
        subtitle="Ob Gaming, Office oder Workstation – dein System wird passend zusammengestellt, sauber gebaut und getestet."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Gaming-PC" }]}
      >
        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
          <Button as={Link} to="/gaming-pc-konfigurator" size="lg" data-testid="gamingpc-cta-configurator">
            <Cpu size={18} /> Jetzt konfigurieren
          </Button>
          <Button as={Link} to="/kontakt" variant="secondary" size="lg">Beratung anfragen</Button>
        </div>
      </PageHero>

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          {TYPES.map((t, i) => (
            <Reveal key={t.title} delay={i * 0.05}>
              <Card className="h-full p-6">
                <h3 className="font-heading text-lg font-semibold text-ink">{t.title}</h3>
                <p className="mt-2 text-sm text-muted">{t.desc}</p>
              </Card>
            </Reveal>
          ))}
        </div>

        <div className="mt-16 grid gap-6 md:grid-cols-3">
          {STEPS.map((s, i) => (
            <Reveal key={s.t} delay={i * 0.08}>
              <Card className="h-full p-7">
                <div className="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand/10 text-brand">
                  <s.icon size={20} />
                </div>
                <h3 className="font-heading text-lg font-semibold text-ink">{s.t}</h3>
                <p className="mt-2 text-sm text-muted">{s.d}</p>
              </Card>
            </Reveal>
          ))}
        </div>

        <div className="mt-14 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5 text-sm text-amber-300">
          <div className="flex items-start gap-3">
            <ShieldCheck size={18} className="mt-0.5 shrink-0" />
            <p>
              Wichtig: Es wird niemals blind eine technische Kompatibilität garantiert. Jede
              Konfiguration wird vor einer verbindlichen Bestellung von IT-Tabelander geprüft und freigegeben.
            </p>
          </div>
        </div>

        <div className="mt-8 flex flex-wrap gap-3">
          {["Vom Fachmann gebaut", "Stresstest inklusive", "Ehrliche Beratung"].map((x) => (
            <span key={x} className="flex items-center gap-2 rounded-full border border-subtle bg-surface/40 px-4 py-2 text-sm text-muted">
              <CheckCircle2 size={15} className="text-brand" /> {x}
            </span>
          ))}
        </div>
      </section>
    </>
  );
}

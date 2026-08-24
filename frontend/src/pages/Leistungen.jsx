import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, CheckCircle2 } from "lucide-react";
import api from "../lib/api";
import { iconFor } from "../lib/icons";
import { Card } from "../components/ui/card";
import { Button } from "../components/ui/button";
import Skeleton from "../components/ui/skeleton";
import Reveal from "../components/Reveal";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

const slugToPath = (slug) => (slug === "pc-bau" ? "/gaming-pc" : `/${slug}`);

export default function Leistungen() {
  const [services, setServices] = useState(null);

  useEffect(() => {
    api.get("/services").then(({ data }) => setServices(data)).catch(() => setServices([]));
  }, []);

  return (
    <>
      <Seo
        title="Leistungen"
        description="Alle Leistungen von IT-Tabelander: PC-Bau, PC- & Notebook-Reparatur, Upgrades, Konsolen- und Controller-Reparatur in Tirol."
        path="/leistungen"
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "Leistungen", path: "/leistungen" }])}
      />
      <PageHero
        eyebrow="Leistungen"
        title="Professionelle IT-Leistungen"
        subtitle="Von der Reparatur bis zum individuellen Gaming-System – strukturiert, transparent und ehrlich."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Leistungen" }]}
      />

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        {!services ? (
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-64" />)}
          </div>
        ) : (
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            {services.map((s, i) => {
              const Icon = iconFor(s.icon);
              return (
                <Reveal key={s.id} delay={i * 0.05}>
                  <Card className="group flex h-full flex-col p-7 hover:-translate-y-1 hover:border-brand/40">
                    <div className="mb-5 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand/10 text-brand transition-colors group-hover:bg-brand group-hover:text-white">
                      <Icon size={22} />
                    </div>
                    <h2 className="font-heading text-xl font-semibold text-ink">{s.title}</h2>
                    <p className="mt-2 text-sm leading-relaxed text-muted">{s.short_description}</p>
                    {s.bullets?.length > 0 && (
                      <ul className="mt-4 space-y-1.5">
                        {s.bullets.slice(0, 4).map((b) => (
                          <li key={b} className="flex items-center gap-2 text-sm text-muted">
                            <CheckCircle2 size={14} className="shrink-0 text-brand" /> {b}
                          </li>
                        ))}
                      </ul>
                    )}
                    <Link
                      to={slugToPath(s.slug)}
                      className="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-brand transition-all hover:gap-2"
                      data-testid={`leistung-link-${s.slug}`}
                    >
                      Details ansehen <ArrowRight size={15} />
                    </Link>
                  </Card>
                </Reveal>
              );
            })}
          </div>
        )}

        <div className="mt-14 flex flex-col items-center gap-4 rounded-2xl border border-subtle bg-surface/40 p-8 text-center">
          <h3 className="font-heading text-2xl font-bold text-ink">Nicht sicher, was du brauchst?</h3>
          <p className="max-w-xl text-muted">Schildere dein Anliegen – du bekommst eine ehrliche Einschätzung, ohne Fachchinesisch.</p>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Button as={Link} to="/reparatur" size="lg" data-testid="leistungen-cta-repair">Reparatur anfragen</Button>
            <Button as={Link} to="/kontakt" variant="secondary" size="lg">Kontakt aufnehmen</Button>
          </div>
        </div>
      </section>
    </>
  );
}

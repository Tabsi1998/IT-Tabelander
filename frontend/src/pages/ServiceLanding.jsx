import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, CheckCircle2, AlertTriangle, Wrench } from "lucide-react";
import api from "../lib/api";
import { LANDING } from "../lib/content";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import Accordion from "../components/ui/accordion";
import Reveal from "../components/Reveal";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

export default function ServiceLanding({ slug }) {
  const c = LANDING[slug];
  const [faqs, setFaqs] = useState([]);

  useEffect(() => {
    if (!c) return;
    api.get(`/faqs?category=${c.faqCategory}`).then(({ data }) => setFaqs(data)).catch(() => {});
  }, [slug, c]);

  if (!c) return null;

  const serviceLd = {
    "@context": "https://schema.org",
    "@type": "Service",
    name: c.title,
    description: c.seoDesc,
    areaServed: "Tirol",
    provider: { "@type": "LocalBusiness", name: "IT-Tabelander" },
  };

  return (
    <>
      <Seo
        title={c.title}
        description={c.seoDesc}
        path={`/${slug}`}
        image={c.image}
        jsonLd={[serviceLd, breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "Leistungen", path: "/leistungen" }, { name: c.title, path: `/${slug}` }])]}
      />
      <PageHero
        eyebrow="Leistung"
        title={c.h1}
        subtitle={c.intro}
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Leistungen", to: "/leistungen" }, { name: c.title }]}
      />

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="grid gap-10 lg:grid-cols-2 lg:items-start">
          <Reveal>
            <div className="overflow-hidden rounded-2xl border border-subtle shadow-card">
              <img src={c.image} alt={c.h1} className="h-64 w-full object-cover md:h-96" loading="lazy" />
            </div>
          </Reveal>
          <Reveal delay={0.1}>
            <h2 className="font-heading text-2xl font-bold text-ink">Das übernehme ich für dich</h2>
            <ul className="mt-5 grid gap-3 sm:grid-cols-2">
              {c.bullets.map((b) => (
                <li key={b} className="flex items-start gap-2 rounded-xl border border-subtle bg-surface/40 p-3 text-sm text-muted">
                  <CheckCircle2 size={16} className="mt-0.5 shrink-0 text-brand" /> {b}
                </li>
              ))}
            </ul>
            {c.note && (
              <div className="mt-6 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-300">
                <AlertTriangle size={18} className="mt-0.5 shrink-0" />
                <p>{c.note}</p>
              </div>
            )}
            <Button as={Link} to={`/anfrage?type=${slug === "pc-aufruestung" ? "pc_upgrade" : "repair"}`} size="lg" className="mt-7" data-testid="landing-cta-inquiry">
              <Wrench size={18} /> Anfrage starten
            </Button>
          </Reveal>
        </div>

        {faqs.length > 0 && (
          <div className="mx-auto mt-20 max-w-3xl">
            <h2 className="mb-6 text-center font-heading text-2xl font-bold text-ink">Häufige Fragen</h2>
            <Accordion items={faqs.map((f) => ({ question: f.question, answer: f.answer }))} />
          </div>
        )}

        <div className="mt-16">
          <h3 className="mb-4 font-heading text-lg font-semibold text-ink">Weitere Leistungen</h3>
          <div className="grid gap-4 sm:grid-cols-3">
            {c.related.map(([name, to]) => (
              <Card key={to} className="p-5">
                <Link to={to} className="flex items-center justify-between text-sm font-semibold text-ink hover:text-brand" data-testid={`related-${to.slice(1)}`}>
                  {name} <ArrowRight size={15} className="text-brand" />
                </Link>
              </Card>
            ))}
          </div>
        </div>
      </section>
    </>
  );
}

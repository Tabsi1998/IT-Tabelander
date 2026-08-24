import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Star, MessageSquareQuote, Info } from "lucide-react";
import api from "../lib/api";
import { Card } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import { Button } from "../components/ui/button";
import Skeleton from "../components/ui/skeleton";
import Reveal from "../components/Reveal";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

function Stars({ n }) {
  return (
    <div className="flex gap-0.5" aria-label={`${n} von 5 Sternen`}>
      {Array.from({ length: 5 }).map((_, i) => (
        <Star key={i} size={16} className={i < n ? "fill-brand text-brand" : "text-faint"} />
      ))}
    </div>
  );
}

export default function Bewertungen() {
  const [data, setData] = useState(null);

  useEffect(() => {
    api.get("/reviews").then(({ data }) => setData(data)).catch(() => setData({ reviews: [], count: 0 }));
  }, []);

  return (
    <>
      <Seo
        title="Bewertungen"
        description="Kundenbewertungen von IT-Tabelander. Ehrliche Rückmeldungen zu Reparatur, PC-Bau und Service."
        path="/bewertungen"
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "Bewertungen", path: "/bewertungen" }])}
      />
      <PageHero
        eyebrow="Bewertungen"
        title="Was Kunden sagen"
        subtitle="Echte Rückmeldungen. Es werden ausschließlich vorhandene, freigegebene Bewertungen angezeigt."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Bewertungen" }]}
      />

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        {!data ? (
          <div className="grid gap-6 md:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-48" />)}
          </div>
        ) : data.reviews.length === 0 ? (
          <Card className="mx-auto max-w-lg p-10 text-center">
            <MessageSquareQuote size={40} className="mx-auto text-brand" />
            <h2 className="mt-4 font-heading text-xl font-semibold text-ink">Noch keine Bewertungen veröffentlicht</h2>
            <p className="mt-2 text-muted">
              {data.google_place_configured
                ? "Google-Bewertungen werden vorbereitet."
                : "Sobald Bewertungen vorliegen, werden sie hier angezeigt – ohne erfundene Daten."}
            </p>
            <Button as={Link} to="/kontakt" className="mt-6">Kontakt aufnehmen</Button>
          </Card>
        ) : (
          <>
            {data.average != null && (
              <div className="mb-10 flex flex-wrap items-center gap-4 rounded-2xl border border-subtle bg-surface/40 p-6">
                <div className="text-5xl font-bold text-ink">{data.average}</div>
                <div>
                  <Stars n={Math.round(data.average)} />
                  <p className="mt-1 text-sm text-muted">Basierend auf {data.count} Bewertung(en)</p>
                </div>
              </div>
            )}
            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
              {data.reviews.map((r, i) => (
                <Reveal key={r.id} delay={i * 0.04}>
                  <Card className="flex h-full flex-col p-6">
                    <Stars n={r.rating} />
                    <p className="mt-3 flex-1 text-sm leading-relaxed text-muted">"{r.text}"</p>
                    <div className="mt-4 flex items-center justify-between">
                      <div>
                        <p className="text-sm font-semibold text-ink">{r.author}</p>
                        <p className="text-xs text-faint">{r.source}</p>
                      </div>
                      <div className="flex gap-1.5">
                        {r.featured && <Badge tone="brand">Empfohlen</Badge>}
                        {r.is_demo && <Badge tone="demo">Demo</Badge>}
                      </div>
                    </div>
                  </Card>
                </Reveal>
              ))}
            </div>
            {data.google_place_configured && (
              <p className="mt-6 flex items-center gap-2 text-xs text-faint">
                <Info size={13} /> Bewertungen teilweise via Google. Anzeige gemäß Google-Vorgaben.
              </p>
            )}
          </>
        )}
      </section>
    </>
  );
}

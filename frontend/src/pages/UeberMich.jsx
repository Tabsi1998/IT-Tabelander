import React, { useState } from "react";
import { Link } from "react-router-dom";
import { Award, Network, Server, GraduationCap, Wrench, ZoomIn } from "lucide-react";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import Dialog from "../components/ui/dialog";
import Reveal from "../components/Reveal";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

const QUALS = [
  { icon: Award, t: "CompTIA A+ zertifiziert", d: "International anerkannte Zertifizierung für PC-Techniker." },
  { icon: GraduationCap, t: "Ausbildung am WIFI Tirol", d: "Fundierte Ausbildung mit internationalem Zeugnis." },
  { icon: Network, t: "Netzwerkadministrator", d: "Planung, Aufbau und Betreuung von Netzwerken." },
  { icon: Server, t: "Systemadministrator", d: "Verwaltung und Wartung von Systemen und Servern." },
];

export default function UeberMich() {
  const [zoom, setZoom] = useState(false);

  return (
    <>
      <Seo
        title="Über mich"
        description="PC-Techniker mit CompTIA A+ Zertifizierung sowie Ausbildung im Bereich Netzwerk- und Systemadministration mit internationalem Zeugnis am WIFI Tirol."
        path="/ueber-mich"
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "Über mich", path: "/ueber-mich" }])}
      />
      <PageHero
        eyebrow="Über mich"
        title="Jemand, der Hardware wirklich versteht"
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "Über mich" }]}
      />

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="grid gap-12 lg:grid-cols-[1.4fr_1fr] lg:items-start">
          <Reveal>
            <div className="space-y-5 text-lg leading-relaxed text-muted">
              <p>
                Ich bin PC-Techniker mit <span className="text-ink font-medium">CompTIA-A+-Zertifizierung</span> sowie
                Ausbildung im Bereich Netzwerk- und Systemadministration mit internationalem
                Zeugnis am WIFI Tirol.
              </p>
              <p>
                Mein Fokus liegt darauf, technische Probleme sauber zu analysieren und sinnvolle
                Lösungen zu finden – von Reparaturen und Hardware-Upgrades bis hin zu individuellen
                Gaming-Systemen.
              </p>
              <p>
                Ehrlichkeit ist mir dabei wichtig: Wenn eine Reparatur oder ein Upgrade nicht
                sinnvoll ist, sage ich das offen. So triffst du eine gute Entscheidung.
              </p>
            </div>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <Button as={Link} to="/reparatur" size="lg" data-testid="about-cta-repair">
                <Wrench size={18} /> Reparatur anfragen
              </Button>
              <Button as={Link} to="/leistungen" variant="secondary" size="lg">Leistungen ansehen</Button>
            </div>
          </Reveal>

          <Reveal delay={0.1}>
            <Card className="p-6 text-center">
              <p className="mb-4 font-mono text-xs uppercase tracking-widest text-brand">Zertifizierung</p>
              <button
                onClick={() => setZoom(true)}
                className="group relative mx-auto block"
                data-testid="comptia-zoom-trigger"
                aria-label="CompTIA A+ Zertifikat vergrößern"
              >
                <img src="/assets/img/certs/comptia-aplus-color.png" alt="CompTIA A+ Certified CE" className="mx-auto h-56 w-56 object-contain transition-transform group-hover:scale-105" />
                <span className="mt-3 inline-flex items-center gap-1.5 text-sm text-brand">
                  <ZoomIn size={15} /> Zertifikat ansehen
                </span>
              </button>
            </Card>
          </Reveal>
        </div>

        <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          {QUALS.map((q, i) => (
            <Reveal key={q.t} delay={i * 0.05}>
              <Card className="h-full p-6">
                <div className="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand/10 text-brand">
                  <q.icon size={20} />
                </div>
                <h3 className="font-heading text-base font-semibold text-ink">{q.t}</h3>
                <p className="mt-2 text-sm text-muted">{q.d}</p>
              </Card>
            </Reveal>
          ))}
        </div>
      </section>

      <Dialog open={zoom} onClose={() => setZoom(false)} title="CompTIA A+ Certified" maxWidth="max-w-lg" testId="comptia-dialog">
        <img src="/assets/img/certs/comptia-aplus-color.png" alt="CompTIA A+ Certified CE" className="mx-auto max-h-[70vh] object-contain" />
      </Dialog>
    </>
  );
}

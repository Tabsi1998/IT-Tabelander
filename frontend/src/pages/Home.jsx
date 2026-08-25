import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { motion } from "framer-motion";
import {
  Send, Cpu, Gamepad2, ArrowRight, ShieldCheck, CheckCircle2, Star, Sparkles,
} from "lucide-react";
import api from "../lib/api";
import { TRUST, IMAGES } from "../lib/content";
import { iconFor } from "../lib/icons";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import Reveal from "../components/Reveal";
import Accordion from "../components/ui/accordion";
import Seo, { orgJsonLd } from "../components/Seo";
import { useSettings } from "../context/SettingsContext";

function Hero() {
  return (
    <section className="relative overflow-hidden pt-28 pb-16 md:pt-36 md:pb-24">
      <div className="pointer-events-none absolute inset-0 bg-grid-faint bg-[size:44px_44px] opacity-40" />
      <div className="pointer-events-none absolute -top-40 right-0 h-[520px] w-[520px] rounded-full bg-brand/10 blur-[120px]" />
      <div className="relative mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
        <div>
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6 }}
          >
            <Badge tone="brand" className="mb-5">
              <ShieldCheck size={13} /> CompTIA A+ zertifiziert · WIFI Tirol
            </Badge>
            <h1 className="font-heading text-4xl font-extrabold leading-[1.05] tracking-tight text-ink sm:text-5xl lg:text-6xl">
              IT-Technik, die{" "}
              <span className="relative text-brand text-glow">funktioniert.</span>
            </h1>
            <p className="mt-6 max-w-xl text-lg leading-relaxed text-muted">
              PCs, Notebooks, Konsolen und Gaming-Hardware – Reparatur, Aufrüstung und
              individuelle Systeme von IT-Tabelander.
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <Button
                as={Link}
                to="/anfrage"
                size="lg"
                data-testid="hero-cta-inquiry"
              >
                <Send size={18} /> Anfrage starten
              </Button>
              <Button as={Link} to="/gaming-pc" variant="secondary" size="lg" data-testid="hero-cta-pc">
                <Cpu size={18} /> Gaming-PC ansehen
              </Button>
            </div>
            <div className="mt-8 flex flex-wrap gap-x-6 gap-y-2">
              {TRUST.slice(0, 4).map((t) => (
                <span key={t} className="flex items-center gap-1.5 text-sm text-muted">
                  <CheckCircle2 size={15} className="text-brand" /> {t}
                </span>
              ))}
            </div>
          </motion.div>
        </div>

        <motion.div
          initial={{ opacity: 0, scale: 0.96 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.7, delay: 0.15 }}
          className="relative"
        >
          <div className="absolute inset-0 rounded-3xl bg-brand/20 blur-3xl" />
          <div className="relative overflow-hidden rounded-3xl border border-subtle shadow-card">
            <img
              src={IMAGES.hero}
              alt="Hochwertiges Gaming-PC-Setup von IT-Tabelander"
              className="h-[320px] w-full object-cover md:h-[460px]"
              width="940"
              height="650"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-[#070d18]/70 via-transparent to-transparent" />
            <div className="absolute bottom-4 left-4 flex items-center gap-2">
              <img src="/assets/img/certs/comptia-aplus-color.png" alt="CompTIA A+ Certified" className="h-14 rounded-lg bg-white/90 p-1" />
            </div>
          </div>
        </motion.div>
      </div>
    </section>
  );
}

function TrustBar() {
  return (
    <section className="border-y border-subtle bg-surface/40">
      <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
          {TRUST.map((t) => (
            <div key={t} className="flex items-center gap-2 text-sm text-muted">
              <ShieldCheck size={16} className="shrink-0 text-brand" />
              {t}
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function ServicesSection({ services }) {
  return (
    <section id="services" className="mx-auto max-w-7xl px-4 py-20 sm:px-6 md:py-28 lg:px-8">
      <Reveal className="mb-12 max-w-2xl">
        <p className="mb-3 font-mono text-xs uppercase tracking-[0.25em] text-brand">Leistungen</p>
        <h2 className="font-heading text-3xl font-bold tracking-tight text-ink md:text-4xl">
          Technik, Reparatur & Gaming-Hardware aus einer Hand
        </h2>
        <p className="mt-4 text-muted">
          Vom individuellen PC-Bau über präzise Reparaturen bis zur Konsolen- und
          Controller-Instandsetzung.
        </p>
      </Reveal>

      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {services.map((s, i) => {
          const Icon = iconFor(s.icon);
          const to = s.slug === "pc-bau" ? "/gaming-pc" : `/${s.slug}`;
          return (
            <Reveal key={s.id} delay={i * 0.06}>
              <Card className="group h-full hover:-translate-y-1 hover:border-brand/40">
                <div className="p-7">
                  <div className="mb-5 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand/10 text-brand transition-colors group-hover:bg-brand group-hover:text-white">
                    <Icon size={22} />
                  </div>
                  <h3 className="font-heading text-xl font-semibold text-ink">{s.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-muted">{s.short_description}</p>
                  <Link
                    to={to}
                    className="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-brand transition-all hover:gap-2"
                    data-testid={`service-link-${s.slug}`}
                  >
                    Mehr erfahren <ArrowRight size={15} />
                  </Link>
                </div>
              </Card>
            </Reveal>
          );
        })}
      </div>
    </section>
  );
}

function InquiryTeaser() {
  return (
    <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <div className="grid gap-6 lg:grid-cols-2">
        <Reveal>
          <Card className="group relative h-full overflow-hidden">
            <img src={IMAGES.hero} alt="Individueller PC nach Wunsch" className="h-56 w-full object-cover opacity-70 transition-transform duration-500 group-hover:scale-105" />
            <div className="absolute inset-0 bg-gradient-to-t from-[#070d18] via-[#070d18]/60 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-7">
              <Badge tone="brand" className="mb-3"><Sparkles size={12} /> Individuell geplant</Badge>
              <h3 className="font-heading text-2xl font-bold text-white">PC nach Wunsch anfragen</h3>
              <p className="mt-2 max-w-md text-sm text-slate-300">
                Nenne Einsatzzweck, Budget und Wünsche. Du bekommst eine persönlich geprüfte Zusammenstellung statt einer anonymen Teileliste.
              </p>
              <Button as={Link} to="/anfrage?type=pc_build" className="mt-4" size="sm" data-testid="teaser-pc-inquiry">
                PC anfragen <ArrowRight size={15} />
              </Button>
            </div>
          </Card>
        </Reveal>
        <Reveal delay={0.1}>
          <Card className="group relative h-full overflow-hidden">
            <div className="flex h-56 items-center justify-center bg-gradient-to-br from-[#0f172a] to-[#1e293b]">
              <img src={IMAGES.controllerFront} alt="PS5 DualSense Controller-Umbau" className="h-48 object-contain transition-transform duration-500 group-hover:scale-105" />
            </div>
            <div className="absolute inset-0 bg-gradient-to-t from-[#070d18] via-transparent to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-7">
              <Badge tone="brand" className="mb-3"><Gamepad2 size={12} /> DualSense</Badge>
              <h3 className="font-heading text-2xl font-bold text-white">Controller-Umbau anfragen</h3>
              <p className="mt-2 max-w-md text-sm text-slate-300">
                Beschreibe gewünschte Optik, Tasten und Funktionen. Details und Machbarkeit werden anschließend persönlich abgestimmt.
              </p>
              <Button as={Link} to="/anfrage?type=controller_custom" className="mt-4" size="sm" data-testid="teaser-controller-inquiry">
                Umbau anfragen <ArrowRight size={15} />
              </Button>
            </div>
          </Card>
        </Reveal>
      </div>
    </section>
  );
}

function ReviewsTeaser({ data }) {
  if (!data || data.reviews.length === 0) return null;
  return (
    <section className="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
      <Reveal className="mb-10 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-3 font-mono text-xs uppercase tracking-[0.25em] text-brand">Bewertungen</p>
          <h2 className="font-heading text-3xl font-bold text-ink md:text-4xl">Das sagen Kunden</h2>
        </div>
        <Link to="/bewertungen" className="inline-flex items-center gap-1 text-sm font-semibold text-brand hover:gap-2">
          Alle ansehen <ArrowRight size={15} />
        </Link>
      </Reveal>
      <div className="grid gap-6 md:grid-cols-3">
        {data.reviews.slice(0, 3).map((r) => (
          <Card key={r.id} className="p-6">
            <div className="mb-3 flex gap-0.5">
              {Array.from({ length: 5 }).map((_, i) => (
                <Star key={i} size={16} className={i < r.rating ? "fill-brand text-brand" : "text-faint"} />
              ))}
            </div>
            <p className="text-sm leading-relaxed text-muted">"{r.text}"</p>
            <div className="mt-4 flex items-center justify-between">
              <p className="text-sm font-semibold text-ink">{r.author}</p>
              {r.is_demo && <Badge tone="demo">Demo</Badge>}
            </div>
          </Card>
        ))}
      </div>
    </section>
  );
}

function FinalCTA() {
  return (
    <section className="mx-auto max-w-7xl px-4 pb-8 sm:px-6 lg:px-8">
      <div className="relative overflow-hidden rounded-3xl border border-brand/20 bg-gradient-to-br from-[#0f172a] to-[#111c2d] p-10 md:p-16">
        <div className="pointer-events-none absolute -right-20 -top-20 h-80 w-80 rounded-full bg-brand/20 blur-3xl" />
        <div className="relative max-w-2xl">
          <h2 className="font-heading text-3xl font-bold text-white md:text-4xl">
            Reparatur, Wunsch-PC oder Controller-Umbau geplant?
          </h2>
          <p className="mt-4 text-slate-300">
            Beschreibe dein Anliegen in wenigen Schritten. Du bekommst eine persönliche Rückmeldung und eine ehrliche Einschätzung.
          </p>
          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Button as={Link} to="/anfrage" size="lg" data-testid="final-cta-inquiry">
              <Send size={18} /> Anfrage starten
            </Button>
            <Button as={Link} to="/kontakt" variant="secondary" size="lg" className="text-white border-white/40 hover:bg-white/10" data-testid="final-cta-contact">
              Kontakt aufnehmen
            </Button>
          </div>
        </div>
      </div>
    </section>
  );
}

export default function Home() {
  const { settings } = useSettings();
  const [services, setServices] = useState([]);
  const [reviews, setReviews] = useState(null);
  const [faqs, setFaqs] = useState([]);

  useEffect(() => {
    api.get("/services").then(({ data }) => setServices(data)).catch(() => {});
    api.get("/reviews").then(({ data }) => setReviews(data)).catch(() => {});
    api.get("/faqs").then(({ data }) => setFaqs(data.slice(0, 6))).catch(() => {});
  }, []);

  return (
    <>
      <Seo path="/" jsonLd={orgJsonLd(settings)} image={IMAGES.hero} />
      <Hero />
      <TrustBar />
      <ServicesSection services={services} />
      <InquiryTeaser />
      <ReviewsTeaser data={reviews} />
      {faqs.length > 0 && (
        <section className="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
          <Reveal className="mb-8 text-center">
            <p className="mb-3 font-mono text-xs uppercase tracking-[0.25em] text-brand">FAQ</p>
            <h2 className="font-heading text-3xl font-bold text-ink">Häufige Fragen</h2>
          </Reveal>
          <Accordion items={faqs.map((f) => ({ question: f.question, answer: f.answer }))} />
        </section>
      )}
      <FinalCTA />
    </>
  );
}

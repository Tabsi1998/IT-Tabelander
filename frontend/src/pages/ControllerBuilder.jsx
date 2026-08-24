import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Check, Save, Send, Loader2, RotateCcw, Info, Gamepad2 } from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError, mediaUrl } from "../lib/api";
import { EUR } from "../lib/utils";
import { trackEvent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import Skeleton from "../components/ui/skeleton";
import ControllerCanvas from "../components/controller/ControllerCanvas";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";
import Dialog from "../components/ui/dialog";
import { Input, Textarea, Select } from "../components/ui/input";

const CATEGORY_LABELS = {
  shell: "Frontschale",
  trim: "Zierrahmen",
  touchpad: "Touchpad",
  dpad: "Steuerkreuz",
  buttons: "Aktionstasten",
  sticks: "Thumbsticks",
  accent: "Akzentringe",
  back_shell: "Rückschale",
  grips: "Griffbereiche",
  paddles: "Rücktasten / Paddles",
  beyond: "BEYOND Rücktasten-Kit",
};

const categoryLabel = (category) => CATEGORY_LABELS[category?.key] || category?.name || "Option";

export default function ControllerBuilder() {
  const [controllers, setControllers] = useState(null);
  const [model, setModel] = useState(null);       // controller key
  const [data, setData] = useState(null);          // {controller, categories}
  const [sel, setSel] = useState({});              // category_key -> {product, variant}
  const [side, setSide] = useState("front");
  const [saving, setSaving] = useState(false);
  const [savedId, setSavedId] = useState(null);
  const [requestOpen, setRequestOpen] = useState(false);
  const [requestNote, setRequestNote] = useState("");
  const [consent, setConsent] = useState(false);
  const [honeypot, setHoneypot] = useState("");
  const [contact, setContact] = useState({ name: "", email: "", phone: "", preferred_contact: "email" });

  useEffect(() => {
    trackEvent("controller_configurator_started");
    api.get("/builder/controllers").then(({ data }) => {
      setControllers(data);
      if (data[0]) setModel(data[0].key);
    }).catch(() => setControllers([]));
  }, []);

  useEffect(() => {
    if (!model) return;
    setData(null); setSel({}); setSavedId(null);
    api.get(`/builder/${model}`)
      .then(({ data }) => {
        // defensive: ensure every variant has a stable id even if the API omits it
        (data.categories || []).forEach((cat) =>
          (cat.products || []).forEach((p) =>
            (p.variants || []).forEach((v, i) => { if (!v.id) v.id = `${p.id}:${i}`; })));
        setData(data);
      }).catch(() => setData({ categories: [] }));
  }, [model]);

  const colors = useMemo(() => {
    const m = {};
    (data?.categories || []).forEach((cat) => {
      const s = sel[cat.key];
      if (s?.variant?.color_hex) m[cat.region_key] = s.variant.color_hex;
    });
    return m;
  }, [sel, data]);

  const overlays = useMemo(() => Object.values(sel)
    .filter((s) => s?.variant?.overlay_image_url)
    .map((s, i) => ({ id: i, url: s.variant.overlay_image_url, layer: s.variant.layer, region: s.product?.category_key })), [sel]);

  const chosen = Object.entries(sel).filter(([, s]) => s?.variant);
  const base = data?.controller?.base_price || 0;
  const total = base + chosen.reduce((sum, [, s]) => sum + (s.variant.price || 0), 0);

  const pick = (cat, product, variant) => {
    setSavedId(null);
    setSel((prev) => {
      const cur = prev[cat.key];
      if (cur?.variant?.id === variant.id) { const n = { ...prev }; delete n[cat.key]; return n; }
      return { ...prev, [cat.key]: { product, variant } };
    });
    if (cat.side === "back" && side !== "back") setSide("back");
  };

  const reset = () => { setSel({}); setSavedId(null); };

  const save = async (asRequest) => {
    if (asRequest && (!contact.name.trim() || !contact.email.trim())) return toast.error("Bitte Name und E-Mail angeben.");
    if (asRequest && !consent) return toast.error("Bitte der Datenverarbeitung zustimmen.");
    setSaving(true);
    try {
      const selections = Object.fromEntries(chosen.map(([k, s]) => [k, {
        product_id: s.product.id, product: s.product.name,
        variant_id: s.variant.id, name: s.variant.name, price: s.variant.price,
      }]));
      const { data: r } = await api.post("/builder/save", {
        controller_key: model, selections, total,
        note: asRequest ? requestNote : "",
        ...(asRequest ? { contact, consent, honeypot } : {}),
      });
      setSavedId(r.config_id);
      if (asRequest) setRequestOpen(false);
      trackEvent("controller_configuration_completed", { config_id: r.config_id, model });
      toast.success(asRequest ? "Anfrage gespeichert!" : "Konfiguration gespeichert!");
    } catch (err) { toast.error(formatApiError(err.response?.data?.detail)); }
    finally { setSaving(false); }
  };

  const currentController = controllers?.find((c) => c.key === model);
  const catsForSide = (data?.categories || []).filter((c) => c.side === "both" || c.side === side);

  return (
    <>
      <Seo title="PS5 Controller Builder" path="/ps5-controller-konfigurator"
        description="Gestalte deinen individuellen PS5 DualSense oder DualSense Edge Controller: Gehäuse, Buttons, Sticks, Back-Paddles und mehr – mit Live-Vorschau."
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "PS5 Controller Builder", path: "/ps5-controller-konfigurator" }])} />
      <PageHero eyebrow="Custom Controller Builder" title="PS5 Custom Controller Builder"
        subtitle="Wähle dein Modell und gestalte deinen Controller – die Vorschau aktualisiert sich live."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "PS5 Controller" }]} />

      <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        {/* Customers choose the controller model only. Hardware revision/BDM
            compatibility is assessed internally after the request. */}
        <div className="mb-6 flex flex-wrap items-center gap-3">
          {!controllers ? <Skeleton className="h-11 w-64" /> : controllers.map((c) => (
            <button key={c.key} onClick={() => setModel(c.key)}
              className={`flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition-all ${model === c.key ? "border-brand bg-brand/10 text-brand" : "border-subtle text-muted hover:border-brand/50"}`}
              data-testid={`builder-model-${c.key}`}>
              <Gamepad2 size={16} /> {c.name}
            </button>
          ))}
          <span className="text-xs text-faint">Die interne Hardware-Revision prüfen wir nach deiner Anfrage.</span>
        </div>

        <div className="grid gap-8 lg:grid-cols-12">
          {/* preview */}
          <div className="lg:col-span-5">
            <div className="sticky top-24">
              <Card className="overflow-hidden p-6">
                <div className="relative rounded-2xl bg-gradient-to-br from-[#0b0f18] to-[#1a2233] p-4">
                  <div className="pointer-events-none absolute inset-0 bg-grid-faint bg-[size:30px_30px] opacity-30" />
                  <Badge tone="success" className="absolute left-4 top-4 z-20">Live-Vorschau</Badge>
                  <ControllerCanvas model={model} side={side} colors={colors} overlays={overlays}
                    photoFront={data?.controller?.preview_front} photoBack={data?.controller?.preview_back}
                    className="relative mx-auto max-w-md" />
                </div>
                <div className="mt-4 flex items-center justify-center gap-2">
                  {["front", "back"].map((s) => (
                    <button key={s} onClick={() => setSide(s)}
                      className={`rounded-lg px-4 py-2 text-sm font-medium transition-colors ${side === s ? "bg-brand text-white" : "bg-elevated text-muted hover:text-ink"}`}
                      data-testid={`builder-side-${s}`}>{s === "front" ? "Vorne" : "Hinten"}</button>
                  ))}
                  <button onClick={reset} className="ml-auto flex items-center gap-1 rounded-lg px-3 py-2 text-sm text-muted hover:text-brand" data-testid="builder-reset"><RotateCcw size={14} /> Zurücksetzen</button>
                </div>
              </Card>

              <Card className="mt-4 p-6" data-testid="builder-summary">
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="font-heading text-lg font-bold text-ink">Dein Controller</h3>
                  {chosen.some(([, s]) => s.variant.is_demo) && <Badge tone="demo">Demo-Preise</Badge>}
                </div>
                <ul className="space-y-2 text-sm">
                  <li className="flex justify-between"><span className="text-muted">Basis: {currentController?.name}</span><span className="text-ink">{EUR(base)}</span></li>
                  {chosen.map(([k, s]) => (
                    <li key={k} className="flex justify-between gap-2">
                      <span className="min-w-0 truncate text-muted">{categoryLabel(data.categories.find((c) => c.key === k))}: {s.variant.name}</span>
                      <span className="shrink-0 text-ink">{s.variant.price ? `+ ${EUR(s.variant.price)}` : "inkl."}</span>
                    </li>
                  ))}
                </ul>
                <div className="mt-4 flex items-center justify-between border-t border-subtle pt-4">
                  <span className="font-heading font-semibold text-ink">Gesamt</span>
                  <span className="font-heading text-xl font-bold text-brand">{EUR(total)}</span>
                </div>
                {savedId && <div className="mt-4 rounded-lg border border-brand/30 bg-brand/10 p-3 text-sm text-brand" data-testid="builder-saved">Konfigurations-ID: <span className="font-mono font-bold">{savedId}</span></div>}
                <div className="mt-4 flex flex-col gap-2">
                  <Button onClick={() => setRequestOpen(true)} disabled={saving} data-testid="builder-request"><Send size={16} /> Konfiguration anfragen</Button>
                  <Button variant="outline" onClick={() => save(false)} disabled={saving} data-testid="builder-save"><Save size={16} /> Konfiguration speichern</Button>
                </div>
              </Card>
            </div>
          </div>

          {/* options */}
          <div className="lg:col-span-7">
            {!data ? <div className="space-y-6">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-44" />)}</div> : (
              <div className="space-y-6">
                <div className="flex gap-2 text-sm">
                  <span className="text-faint">Ansicht folgt der Kategorie ·</span>
                  <span className="text-muted">Zeige {side === "front" ? "Vorderseite" : "Rückseite"}</span>
                </div>
                {catsForSide.map((cat) => (
                  <Card key={cat.id} className="p-6" data-testid={`builder-category-${cat.key}`}>
                    <div className="mb-4 flex items-center justify-between">
                      <h3 className="font-heading text-lg font-semibold text-ink">{categoryLabel(cat)} {cat.required && <span className="text-brand">*</span>}</h3>
                      <Badge tone="neutral">{cat.side === "back" ? "Rückseite" : "Vorderseite"}</Badge>
                    </div>
                    {cat.products.length === 0 ? <p className="text-sm text-faint">Keine Produkte für dieses Modell.</p> :
                      cat.products.map((prod) => (
                        <div key={prod.id} className="mb-4 last:mb-0">
                          {cat.products.length > 1 && <p className="mb-2 text-xs font-medium uppercase tracking-wide text-faint">{prod.name}</p>}
                          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {prod.variants.filter((v) => v.active).map((v) => {
                              const active = sel[cat.key]?.variant?.id === v.id;
                              return (
                                <button key={v.id} onClick={() => pick(cat, prod, v)}
                                  className={`flex items-center gap-2.5 rounded-xl border p-2.5 text-left transition-all ${active ? "border-brand bg-brand/10" : "border-subtle hover:border-brand/50"}`}
                                  data-testid={`builder-variant-${v.id}`}>
                                  {v.thumb_url || v.overlay_image_url ? (
                                    <img src={mediaUrl(v.thumb_url || v.overlay_image_url)} alt="" className="h-10 w-10 shrink-0 rounded-lg border border-white/10 object-cover" />
                                  ) : v.color_hex ? (
                                    <span className="h-7 w-7 shrink-0 rounded-full border border-white/20 shadow-inner" style={{ background: v.color_hex }} />
                                  ) : null}
                                  <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium text-ink">{v.name}</span>
                                    <span className="block text-xs text-faint">{v.price ? `+ ${EUR(v.price)}` : "inklusive"}</span>
                                  </span>
                                  {active && <Check size={15} className="shrink-0 text-brand" />}
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      ))}
                  </Card>
                ))}
                <p className="flex items-start gap-1.5 text-xs text-faint">
                  <Info size={13} className="mt-0.5 shrink-0" /> Umbauten wie Hall-Effect-Sticks werden nach Prüfung des Geräts umgesetzt. Preise sind Demo-Werte, im Admin mit Dolibarr verknüpfbar.{" "}
                  <Link to="/controller-reparatur" className="text-brand hover:underline">Controller-Reparatur</Link>
                </p>
              </div>
            )}
          </div>
        </div>
      </section>

      <Dialog open={requestOpen} onClose={() => !saving && setRequestOpen(false)} title="Controller-Konfiguration anfragen" maxWidth="max-w-xl">
        <div className="space-y-4">
          <p className="text-sm text-muted">Sende uns deine Auswahl mit der Konfigurations-ID. Die genaue Hardware-Kompatibilität prüfen wir anschließend persönlich.</p>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm text-muted">Name *<Input className="mt-1" value={contact.name} onChange={(e) => setContact({ ...contact, name: e.target.value })} autoComplete="name" /></label>
            <label className="text-sm text-muted">E-Mail *<Input className="mt-1" type="email" value={contact.email} onChange={(e) => setContact({ ...contact, email: e.target.value })} autoComplete="email" /></label>
            <label className="text-sm text-muted">Telefon (optional)<Input className="mt-1" value={contact.phone} onChange={(e) => setContact({ ...contact, phone: e.target.value })} autoComplete="tel" /></label>
            <label className="text-sm text-muted">Bevorzugte Antwort<Select className="mt-1" value={contact.preferred_contact} onChange={(e) => setContact({ ...contact, preferred_contact: e.target.value })}><option value="email">E-Mail</option><option value="phone">Telefon</option></Select></label>
          </div>
          <label className="block text-sm text-muted">Anmerkung (optional)<Textarea className="mt-1 min-h-24" value={requestNote} onChange={(e) => setRequestNote(e.target.value)} placeholder="Wünsche, vorhandener Controller, gewünschter Termin …" /></label>
          <label className="hidden" aria-hidden="true">Website<Input tabIndex={-1} autoComplete="off" value={honeypot} onChange={(e) => setHoneypot(e.target.value)} /></label>
          <label className="flex items-start gap-2 text-sm text-muted">
            <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} className="mt-1 h-4 w-4 accent-[#F26522]" />
            <span>Ich stimme der Verarbeitung meiner Angaben zur Bearbeitung der Anfrage zu. <Link to="/datenschutz" className="text-brand hover:underline">Datenschutz</Link></span>
          </label>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setRequestOpen(false)} disabled={saving}>Abbrechen</Button>
            <Button onClick={() => save(true)} disabled={saving}>{saving ? <Loader2 className="animate-spin" size={16} /> : <Send size={16} />} Anfrage senden</Button>
          </div>
        </div>
      </Dialog>
    </>
  );
}

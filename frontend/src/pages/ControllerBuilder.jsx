import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Check, Save, Send, Loader2, RotateCcw, Info, Gamepad2 } from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError } from "../lib/api";
import { EUR } from "../lib/utils";
import { trackEvent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import Skeleton from "../components/ui/skeleton";
import ControllerCanvas from "../components/controller/ControllerCanvas";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

export default function ControllerBuilder() {
  const [controllers, setControllers] = useState(null);
  const [model, setModel] = useState(null);       // controller key
  const [version, setVersion] = useState("");
  const [data, setData] = useState(null);          // {controller, categories}
  const [sel, setSel] = useState({});              // category_key -> {product, variant}
  const [side, setSide] = useState("front");
  const [saving, setSaving] = useState(false);
  const [savedId, setSavedId] = useState(null);

  useEffect(() => {
    trackEvent("controller_configurator_started");
    api.get("/builder/controllers").then(({ data }) => {
      setControllers(data);
      if (data[0]) { setModel(data[0].key); setVersion(data[0].versions?.[0]?.code || ""); }
    }).catch(() => setControllers([]));
  }, []);

  useEffect(() => {
    if (!model) return;
    setData(null); setSel({}); setSavedId(null);
    api.get(`/builder/${model}${version ? `?version=${version}` : ""}`)
      .then(({ data }) => setData(data)).catch(() => setData({ categories: [] }));
  }, [model, version]);

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
    setSaving(true);
    try {
      const selections = Object.fromEntries(chosen.map(([k, s]) => [k, {
        product_id: s.product.id, product: s.product.name,
        variant_id: s.variant.id, name: s.variant.name, price: s.variant.price,
      }]));
      const { data: r } = await api.post("/builder/save", {
        controller_key: model, version, selections, total,
        note: asRequest ? "Anfrage über Website" : "",
      });
      setSavedId(r.config_id);
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
        {/* model + version selector */}
        <div className="mb-6 flex flex-wrap items-center gap-3">
          {!controllers ? <Skeleton className="h-11 w-64" /> : controllers.map((c) => (
            <button key={c.key} onClick={() => { setModel(c.key); setVersion(c.versions?.[0]?.code || ""); }}
              className={`flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition-all ${model === c.key ? "border-brand bg-brand/10 text-brand" : "border-subtle text-muted hover:border-brand/50"}`}
              data-testid={`builder-model-${c.key}`}>
              <Gamepad2 size={16} /> {c.name}
            </button>
          ))}
          {currentController?.versions?.length > 1 && (
            <select value={version} onChange={(e) => setVersion(e.target.value)}
              className="rounded-xl border border-subtle bg-elevated/60 px-3 py-2.5 text-sm text-ink" data-testid="builder-version">
              {currentController.versions.map((v) => <option key={v.code} value={v.code}>{v.label}</option>)}
            </select>
          )}
        </div>

        <div className="grid gap-8 lg:grid-cols-12">
          {/* preview */}
          <div className="lg:col-span-5">
            <div className="sticky top-24">
              <Card className="overflow-hidden p-6">
                <div className="relative rounded-2xl bg-gradient-to-br from-[#0b0f18] to-[#1a2233] p-4">
                  <div className="pointer-events-none absolute inset-0 bg-grid-faint bg-[size:30px_30px] opacity-30" />
                  <ControllerCanvas model={model} side={side} colors={colors} overlays={overlays} className="relative mx-auto max-w-md" />
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
                      <span className="min-w-0 truncate text-muted">{data.categories.find((c) => c.key === k)?.name}: {s.variant.name}</span>
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
                  <Button onClick={() => save(true)} disabled={saving} data-testid="builder-request">{saving ? <Loader2 className="animate-spin" size={16} /> : <Send size={16} />} In den Warenkorb / Anfragen</Button>
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
                      <h3 className="font-heading text-lg font-semibold text-ink">{cat.name} {cat.required && <span className="text-brand">*</span>}</h3>
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
                                  {v.color_hex && <span className="h-7 w-7 shrink-0 rounded-full border border-white/20" style={{ background: v.color_hex }} />}
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
    </>
  );
}

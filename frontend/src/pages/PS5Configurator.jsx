import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Check, Save, Send, Loader2, Info, Sparkles } from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError } from "../lib/api";
import { EUR } from "../lib/utils";
import { trackEvent } from "../context/ConsentContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import Skeleton from "../components/ui/skeleton";
import ControllerSVG from "../components/ControllerSVG";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

export default function PS5Configurator() {
  const [cats, setCats] = useState(null);
  const [selections, setSelections] = useState({}); // key -> option (single); special -> array
  const [saving, setSaving] = useState(false);
  const [savedId, setSavedId] = useState(null);

  useEffect(() => {
    trackEvent("controller_configurator_started");
    api.get("/configurator/ps5")
      .then(({ data }) => {
        setCats(data.categories);
        const init = {};
        data.categories.forEach((c) => {
          if (c.required && c.options[0] && !c.multi) init[c.key] = c.options[0];
        });
        setSelections(init);
      })
      .catch(() => setCats([]));
  }, []);

  const colors = useMemo(() => {
    const map = {};
    Object.entries(selections).forEach(([k, v]) => {
      if (Array.isArray(v)) { if (v[0]?.color_hex) map[k] = v[0].color_hex; }
      else if (v?.color_hex) map[k] = v.color_hex;
    });
    return map;
  }, [selections]);

  const chosen = useMemo(() => {
    const list = [];
    Object.values(selections).forEach((v) => (Array.isArray(v) ? list.push(...v) : v && list.push(v)));
    return list;
  }, [selections]);

  const total = chosen.reduce((sum, o) => sum + (o.price_on_request ? 0 : o.price || 0), 0);
  const hasOnRequest = chosen.some((o) => o.price_on_request);
  const hasDemo = chosen.some((o) => o.is_demo);

  const select = (cat, opt) => {
    setSavedId(null);
    if (cat.multi) {
      setSelections((s) => {
        const cur = s[cat.key] || [];
        const exists = cur.find((x) => x.id === opt.id);
        return { ...s, [cat.key]: exists ? cur.filter((x) => x.id !== opt.id) : [...cur, opt] };
      });
    } else {
      setSelections((s) => ({ ...s, [cat.key]: opt }));
    }
  };

  const isSelected = (cat, opt) => {
    const v = selections[cat.key];
    return Array.isArray(v) ? !!v.find((x) => x.id === opt.id) : v?.id === opt.id;
  };

  const save = async (asRequest) => {
    setSaving(true);
    try {
      const payload = {
        configurator: "ps5",
        selections: Object.fromEntries(
          Object.entries(selections).map(([k, v]) => [k, Array.isArray(v) ? v.map((o) => ({ id: o.id, name: o.name })) : { id: v.id, name: v.name }])
        ),
        note: asRequest ? "Anfrage über Website" : "",
      };
      const { data } = await api.post("/configurator/save", payload);
      setSavedId(data.config_id);
      trackEvent("controller_configuration_completed", { config_id: data.config_id });
      toast.success(asRequest ? "Anfrage gespeichert!" : "Konfiguration gespeichert!");
    } catch (err) {
      toast.error(formatApiError(err.response?.data?.detail));
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Seo
        title="PS5 Controller Konfigurator"
        description="Gestalte deinen PS5 DualSense Controller: Gehäuse, Buttons, Sticks, Trigger und Spezialoptionen mit Live-Vorschau."
        path="/ps5-controller-konfigurator"
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "PS5 Controller Konfigurator", path: "/ps5-controller-konfigurator" }])}
      />
      <PageHero
        eyebrow="Konfigurator"
        title="PS5 DualSense Konfigurator"
        subtitle="Stelle dein Wunschdesign zusammen – die Vorschau aktualisiert sich live."
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "PS5 Controller" }]}
      />

      <section className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div className="grid gap-8 lg:grid-cols-12">
          {/* Preview */}
          <div className="lg:col-span-5">
            <div className="sticky top-24">
              <Card className="overflow-hidden p-6">
                <div className="relative rounded-2xl bg-gradient-to-br from-[#0b0f18] to-[#1a2233] p-4">
                  <div className="pointer-events-none absolute inset-0 bg-grid-faint bg-[size:30px_30px] opacity-30" />
                  <ControllerSVG colors={colors} className="relative mx-auto w-full max-w-md" />
                </div>
              </Card>

              {/* Summary */}
              <Card className="mt-4 p-6" data-testid="ps5-summary">
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="font-heading text-lg font-bold text-ink">Deine Konfiguration</h3>
                  {hasDemo && <Badge tone="demo">Demo-Werte</Badge>}
                </div>
                <ul className="space-y-2 text-sm">
                  {chosen.length === 0 && <li className="text-faint">Noch nichts ausgewählt.</li>}
                  {chosen.map((o) => (
                    <li key={o.id} className="flex justify-between gap-2">
                      <span className="text-muted">{o.name}</span>
                      <span className="text-ink">{o.price_on_request ? "auf Anfrage" : o.price ? EUR(o.price) : "inkl."}</span>
                    </li>
                  ))}
                </ul>
                <div className="mt-4 flex items-center justify-between border-t border-subtle pt-4">
                  <span className="font-heading font-semibold text-ink">Gesamt</span>
                  <span className="font-heading text-xl font-bold text-brand">
                    {hasOnRequest ? "Preis auf Anfrage" : EUR(total)}
                  </span>
                </div>
                {hasDemo && (
                  <p className="mt-3 flex items-start gap-1.5 text-xs text-faint">
                    <Info size={13} className="mt-0.5 shrink-0" /> Preise sind Demo-/Beispielwerte und werden über das Admin-Panel mit Dolibarr verknüpft.
                  </p>
                )}
                {savedId && (
                  <div className="mt-4 rounded-lg border border-brand/30 bg-brand/10 p-3 text-sm text-brand" data-testid="ps5-saved">
                    Konfigurations-ID: <span className="font-mono font-bold">{savedId}</span>
                  </div>
                )}
                <div className="mt-4 flex flex-col gap-2">
                  <Button onClick={() => save(true)} disabled={saving || chosen.length === 0} data-testid="ps5-request">
                    {saving ? <Loader2 className="animate-spin" size={16} /> : <Send size={16} />} Als Anfrage senden
                  </Button>
                  <Button variant="outline" onClick={() => save(false)} disabled={saving || chosen.length === 0} data-testid="ps5-save">
                    <Save size={16} /> Konfiguration speichern
                  </Button>
                </div>
              </Card>
            </div>
          </div>

          {/* Options panel */}
          <div className="lg:col-span-7">
            {!cats ? (
              <div className="space-y-6">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-40" />)}</div>
            ) : (
              <div className="space-y-6">
                {cats.map((cat) => (
                  <Card key={cat.id} className="p-6" data-testid={`ps5-category-${cat.key}`}>
                    <div className="mb-4 flex items-center justify-between">
                      <h3 className="font-heading text-lg font-semibold text-ink">
                        {cat.name} {cat.required && <span className="text-brand">*</span>}
                      </h3>
                      {cat.multi && <Badge tone="neutral"><Sparkles size={11} /> Mehrfach</Badge>}
                    </div>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                      {cat.options.map((opt) => {
                        const sel = isSelected(cat, opt);
                        return (
                          <button
                            key={opt.id}
                            onClick={() => select(cat, opt)}
                            className={`group relative flex items-center gap-3 rounded-xl border p-3 text-left transition-all ${
                              sel ? "border-brand bg-brand/10" : "border-subtle hover:border-brand/50"
                            }`}
                            data-testid={`ps5-option-${opt.id}`}
                          >
                            {opt.color_hex && (
                              <span className="h-7 w-7 shrink-0 rounded-full border border-white/20" style={{ background: opt.color_hex }} />
                            )}
                            <span className="min-w-0 flex-1">
                              <span className="block truncate text-sm font-medium text-ink">{opt.name}</span>
                              <span className="block text-xs text-faint">
                                {opt.price_on_request ? "auf Anfrage" : opt.price ? EUR(opt.price) : "inklusive"}
                              </span>
                            </span>
                            {sel && <Check size={16} className="shrink-0 text-brand" />}
                          </button>
                        );
                      })}
                    </div>
                  </Card>
                ))}
                <p className="text-sm text-muted">
                  Individuelle Umbauten wie Hall-Effect-Sticks werden nach Prüfung des Geräts umgesetzt.{" "}
                  <Link to="/controller-reparatur" className="text-brand hover:underline">Mehr zur Controller-Reparatur</Link>.
                </p>
              </div>
            )}
          </div>
        </div>
      </section>
    </>
  );
}

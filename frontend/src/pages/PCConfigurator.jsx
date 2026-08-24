import React, { useEffect, useMemo, useState } from "react";
import { Check, Save, Send, Loader2, ShieldCheck, AlertTriangle, XCircle, Info } from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError, mediaUrl } from "../lib/api";
import { EUR } from "../lib/utils";
import { checkPCCompatibility } from "../lib/compatibility";
import { trackEvent } from "../context/ConsentContext";
import { useSettings } from "../context/SettingsContext";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import Skeleton from "../components/ui/skeleton";
import Seo, { breadcrumbJsonLd } from "../components/Seo";
import PageHero from "../components/PageHero";

function specLine(specs) {
  if (!specs) return "";
  return Object.entries(specs)
    .filter(([, v]) => v != null && !Array.isArray(v))
    .slice(0, 4)
    .map(([k, v]) => `${k}: ${v}`)
    .join(" · ");
}

export default function PCConfigurator() {
  const { settings } = useSettings();
  const [cats, setCats] = useState(null);
  const [sel, setSel] = useState({}); // key -> option
  const [saving, setSaving] = useState(false);
  const [savedId, setSavedId] = useState(null);

  useEffect(() => {
    trackEvent("pc_configurator_started");
    api.get("/configurator/pc").then(({ data }) => setCats(data.categories)).catch(() => setCats([]));
  }, []);

  const chosen = useMemo(() => Object.values(sel).filter(Boolean), [sel]);
  const total = chosen.reduce((s, o) => s + (o.price_on_request ? 0 : o.price || 0), 0);
  const hasOnRequest = chosen.some((o) => o.price_on_request);
  const hasDemo = chosen.some((o) => o.is_demo);
  const issues = useMemo(() => checkPCCompatibility(sel), [sel]);
  const hasError = issues.some((i) => i.level === "error");

  const requiredKeys = (cats || []).filter((c) => c.required).map((c) => c.key);
  const missing = requiredKeys.filter((k) => !sel[k]);

  const pick = (key, opt) => { setSavedId(null); setSel((s) => ({ ...s, [key]: s[key]?.id === opt.id ? undefined : opt })); };

  const pcTitle = settings.pc_builder_title || "PC Builder";
  const pcSubtitle = settings.pc_builder_subtitle || "Stelle deinen PC zusammen – der Builder prüft, soweit die Daten es erlauben, die Kompatibilität.";

  const save = async (asRequest) => {
    if (missing.length) return toast.error("Bitte wähle alle Pflichtkomponenten (*).");
    setSaving(true);
    try {
      const payload = {
        configurator: "pc",
        selections: Object.fromEntries(chosen.map((o) => [o.category_key, { id: o.id, name: o.name, price: o.price, sku: o.sku }])),
        note: (asRequest ? "Anfrage über Website. " : "") + (hasError ? "ACHTUNG: Kompatibilitätsfehler – Prüfung nötig." : ""),
      };
      const { data } = await api.post("/configurator/save", payload);
      setSavedId(data.config_id);
      trackEvent("pc_configuration_completed", { config_id: data.config_id });
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
        title={pcTitle}
        description="Stelle deinen PC zusammen: CPU, GPU, Mainboard, RAM, Kühlung und mehr – mit Kompatibilitäts-Check und Zusammenfassung."
        path="/gaming-pc-konfigurator"
        jsonLd={breadcrumbJsonLd([{ name: "Start", path: "/" }, { name: "PC Builder", path: "/gaming-pc-konfigurator" }])}
      />
      <PageHero
        eyebrow="PC Builder"
        title={pcTitle}
        subtitle={pcSubtitle}
        breadcrumbs={[{ name: "Start", to: "/" }, { name: "PC Builder" }]}
      />

      <section className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        {settings.pc_builder_note && (
          <div className="mb-8 flex items-start gap-3 rounded-xl border border-brand/20 bg-brand/5 p-4 text-sm text-muted" data-testid="pc-builder-note">
            <Info size={16} className="mt-0.5 shrink-0 text-brand" />
            <span>{settings.pc_builder_note}</span>
          </div>
        )}
        <div className="grid gap-8 lg:grid-cols-12">
          {/* components */}
          <div className="lg:col-span-8">
            {!cats ? (
              <div className="space-y-6">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-52" />)}</div>
            ) : (
              <div className="space-y-6">
                {cats.map((cat) => (
                  <Card key={cat.id} className="p-6" data-testid={`pc-category-${cat.key}`}>
                    <h3 className="mb-4 font-heading text-lg font-semibold text-ink">
                      {cat.name} {cat.required && <span className="text-brand">*</span>}
                    </h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                      {cat.options.map((opt) => {
                        const active = sel[cat.key]?.id === opt.id;
                        return (
                          <button
                            key={opt.id}
                            onClick={() => pick(cat.key, opt)}
                            className={`flex gap-3 rounded-xl border p-3 text-left transition-all ${
                              active ? "border-brand bg-brand/10" : "border-subtle hover:border-brand/50"
                            }`}
                            data-testid={`pc-option-${opt.id}`}
                          >
                            {opt.image_url && (
                              <img src={mediaUrl(opt.image_url)} alt={opt.name} className="h-16 w-16 shrink-0 rounded-lg object-cover" loading="lazy" />
                            )}
                            <span className="min-w-0 flex-1">
                              <span className="flex items-center justify-between gap-2">
                                <span className="truncate text-sm font-semibold text-ink">{opt.name}</span>
                                {active && <Check size={15} className="shrink-0 text-brand" />}
                              </span>
                              <span className="mt-0.5 block text-xs text-faint">{specLine(opt.specs)}</span>
                              <span className="mt-1 block text-sm font-semibold text-brand">
                                {opt.price_on_request ? "auf Anfrage" : opt.price ? EUR(opt.price) : "inklusive"}
                              </span>
                            </span>
                          </button>
                        );
                      })}
                    </div>
                  </Card>
                ))}
              </div>
            )}
          </div>

          {/* summary */}
          <div className="lg:col-span-4">
            <div className="sticky top-24 space-y-4">
              {/* compatibility */}
              <Card className="p-5" data-testid="pc-compatibility">
                <div className="flex items-center gap-2">
                  {hasError ? <XCircle size={18} className="text-red-400" /> : issues.length ? <AlertTriangle size={18} className="text-amber-400" /> : <ShieldCheck size={18} className="text-emerald-400" />}
                  <h3 className="font-heading text-base font-semibold text-ink">Kompatibilität</h3>
                </div>
                {issues.length === 0 ? (
                  <p className="mt-2 text-sm text-muted">Keine Konflikte erkannt.</p>
                ) : (
                  <ul className="mt-3 space-y-2">
                    {issues.map((it, i) => (
                      <li key={i} className={`flex items-start gap-2 rounded-lg border p-2.5 text-xs ${it.level === "error" ? "border-red-500/30 bg-red-500/10 text-red-300" : "border-amber-500/30 bg-amber-500/10 text-amber-300"}`}>
                        {it.level === "error" ? <XCircle size={14} className="mt-0.5 shrink-0" /> : <AlertTriangle size={14} className="mt-0.5 shrink-0" />}
                        {it.message}
                      </li>
                    ))}
                  </ul>
                )}
                <p className="mt-3 flex items-start gap-1.5 text-xs text-faint">
                  <Info size={13} className="mt-0.5 shrink-0" /> Jede Konfiguration wird vor Bestellung von IT-Tabelander geprüft und freigegeben. Kompatibilität wird nie blind garantiert.
                </p>
              </Card>

              {/* config summary */}
              <Card className="p-6" data-testid="pc-summary">
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="font-heading text-lg font-bold text-ink">Deine Konfiguration</h3>
                  {hasDemo && <Badge tone="demo">Demo-Werte</Badge>}
                </div>
                <ul className="space-y-2 text-sm">
                  {chosen.length === 0 && <li className="text-faint">Noch nichts ausgewählt.</li>}
                  {chosen.map((o) => (
                    <li key={o.id} className="flex justify-between gap-2">
                      <span className="min-w-0 truncate text-muted">{o.name}</span>
                      <span className="shrink-0 text-ink">{o.price_on_request ? "auf Anfrage" : o.price ? EUR(o.price) : "inkl."}</span>
                    </li>
                  ))}
                </ul>
                <div className="mt-4 flex items-center justify-between border-t border-subtle pt-4">
                  <span className="font-heading font-semibold text-ink">Gesamt</span>
                  <span className="font-heading text-xl font-bold text-brand">{hasOnRequest ? "auf Anfrage" : EUR(total)}</span>
                </div>
                {missing.length > 0 && <p className="mt-2 text-xs text-amber-400">Noch offen: {missing.join(", ")}</p>}
                {savedId && (
                  <div className="mt-4 rounded-lg border border-brand/30 bg-brand/10 p-3 text-sm text-brand" data-testid="pc-saved">
                    Konfigurations-ID: <span className="font-mono font-bold">{savedId}</span>
                  </div>
                )}
                <div className="mt-4 flex flex-col gap-2">
                  <Button onClick={() => save(true)} disabled={saving || chosen.length === 0} data-testid="pc-request">
                    {saving ? <Loader2 className="animate-spin" size={16} /> : <Send size={16} />} Als Anfrage senden
                  </Button>
                  <Button variant="outline" onClick={() => save(false)} disabled={saving || chosen.length === 0} data-testid="pc-save">
                    <Save size={16} /> Konfiguration speichern
                  </Button>
                </div>
              </Card>
            </div>
          </div>
        </div>
      </section>
    </>
  );
}

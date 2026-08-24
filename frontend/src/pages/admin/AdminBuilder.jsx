import React, { useCallback, useEffect, useState } from "react";
import { Plus, Pencil, Trash2, Link2 } from "lucide-react";
import { toast } from "sonner";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Empty, Field } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Input, Textarea, Select } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import Dialog from "../../components/ui/dialog";
import { EUR } from "../../lib/utils";

const emptyProduct = (ck, cat) => ({
  controller_key: ck, category_key: cat || "", name: "", description: "", sku: "",
  dolibarr_product_id: "", compatible_versions: [], requires: [], excludes: [],
  active: true, is_demo: false, sort: 0, variants: [],
});

export default function AdminBuilder() {
  const [controllers, setControllers] = useState(null);
  const [ck, setCk] = useState("");
  const [cats, setCats] = useState([]);
  const [prods, setProds] = useState([]);
  const [dolProds, setDolProds] = useState([]);
  const [requests, setRequests] = useState([]);
  const [edit, setEdit] = useState(null);

  useEffect(() => {
    api.get("/admin/builder/controllers").then(({ data }) => {
      setControllers(data); if (data[0]) setCk(data[0].key);
    }).catch(() => setControllers([]));
    api.get("/admin/dolibarr/products").then(({ data }) => setDolProds(data)).catch(() => {});
    api.get("/admin/builder/requests").then(({ data }) => setRequests(data)).catch(() => {});
  }, []);

  const load = useCallback(() => {
    if (!ck) return;
    api.get(`/admin/builder/${ck}/categories`).then(({ data }) => setCats(data)).catch(() => setCats([]));
    api.get(`/admin/builder/${ck}/products`).then(({ data }) => setProds(data)).catch(() => setProds([]));
  }, [ck]);
  useEffect(() => { load(); }, [load]);

  const openEdit = (p, catKey) => {
    const d = p ? { ...p } : emptyProduct(ck, catKey);
    setEdit({ ...d, variants: (d.variants || []).map((variant) => ({ ...variant })) });
  };

  const save = async () => {
    if (!(edit.variants || []).every((variant) => variant.name?.trim())) return toast.error("Jede Variante benötigt einen Namen");
    const payload = { ...edit, variants: edit.variants || [] };
    try {
      if (edit.id) await api.put(`/admin/builder/products/${edit.id}`, payload);
      else await api.post("/admin/builder/products", payload);
      toast.success("Gespeichert"); setEdit(null); load();
    } catch { toast.error("Fehler beim Speichern"); }
  };

  const del = async (id) => { if (!window.confirm("Produkt löschen?")) return; await api.delete(`/admin/builder/products/${id}`); load(); };
  const addVariant = () => setEdit((current) => ({
    ...current,
    variants: [...(current.variants || []), {
      name: "Neue Variante", color_hex: "#1A1D22", overlay_image_url: "", thumb_url: "",
      price: 0, sku: "", available: true, active: true, is_demo: false, sort: current.variants?.length || 0, layer: {},
    }],
  }));
  const updateVariant = (index, patch) => setEdit((current) => ({
    ...current,
    variants: current.variants.map((variant, variantIndex) => variantIndex === index ? { ...variant, ...patch } : variant),
  }));
  const removeVariant = (index) => setEdit((current) => ({
    ...current,
    variants: current.variants.filter((_, variantIndex) => variantIndex !== index),
  }));

  const controller = controllers?.find((c) => c.key === ck);

  return (
    <>
      <AdminHeader title="Controller Builder" desc="Modelle, Kategorien, Produkte & Varianten verwalten (datengetrieben)" />
      {!controllers ? <Skeleton className="h-96" /> : (
        <>
          <div className="mb-6 inline-flex rounded-xl border border-subtle p-1">
            {controllers.map((c) => (
              <button key={c.key} onClick={() => setCk(c.key)} className={`rounded-lg px-4 py-2 text-sm font-medium transition-colors ${ck === c.key ? "bg-brand text-white" : "text-muted hover:text-ink"}`} data-testid={`abuilder-tab-${c.key}`}>{c.name}</button>
            ))}
          </div>

          {controller && (
            <Panel className="mb-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="font-heading font-semibold text-ink">{controller.name} <Badge tone="neutral">Basis {EUR(controller.base_price)}</Badge></p>
                  <p className="text-xs text-faint">Revisionen: {(controller.versions || []).map((v) => v.code).join(", ") || "–"}</p>
                </div>
              </div>
            </Panel>
          )}

          <Panel className="mb-6">
            <div className="mb-3 flex items-center justify-between">
              <h3 className="font-heading font-semibold text-ink">Controller-Anfragen</h3>
              <Badge tone={requests.length ? "brand" : "neutral"}>{requests.length}</Badge>
            </div>
            {requests.length === 0 ? <p className="text-sm text-faint">Noch keine Kundenanfragen.</p> : (
              <div className="max-h-80 space-y-2 overflow-y-auto">
                {requests.map((request) => (
                  <div key={request.id} className="rounded-xl border border-subtle p-3 text-sm">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-medium text-ink">{request.contact?.name || "Unbekannt"} · <a href={`mailto:${request.contact?.email || ""}`} className="text-brand hover:underline">{request.contact?.email}</a></p>
                        <p className="text-xs text-faint">{request.contact?.phone || "Keine Telefonnummer"} · ID {request.config_id}</p>
                      </div>
                      <div className="text-right"><p className="font-semibold text-brand">{EUR(request.total)}</p><p className="text-xs text-faint">{new Date(request.created_at).toLocaleString("de-AT")}</p></div>
                    </div>
                    <p className="mt-2 text-xs text-muted">{Object.values(request.selections || {}).map((selection) => selection.name).join(" · ") || "Basismodell ohne Zusatzoptionen"}</p>
                    {request.note && <p className="mt-2 rounded-lg bg-elevated/60 p-2 text-xs text-muted">{request.note}</p>}
                  </div>
                ))}
              </div>
            )}
          </Panel>

          {cats.length === 0 ? <Empty>Keine Kategorien.</Empty> : (
            <div className="space-y-5">
              {cats.map((cat) => (
                <Panel key={cat.id}>
                  <div className="mb-3 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <h3 className="font-heading font-semibold text-ink">{cat.name}</h3>
                      <Badge tone="neutral">{cat.region_key}</Badge>
                      <Badge tone="neutral">{cat.side}</Badge>
                      {cat.required && <Badge tone="brand">Pflicht</Badge>}
                    </div>
                    <Button size="sm" variant="outline" onClick={() => openEdit(null, cat.key)} data-testid={`abuilder-add-${cat.key}`}><Plus size={14} /> Produkt</Button>
                  </div>
                  <div className="grid gap-2 sm:grid-cols-2">
                    {prods.filter((p) => p.category_key === cat.key).map((p) => (
                      <div key={p.id} className="flex items-center gap-2 rounded-lg border border-subtle p-2.5">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm text-ink">{p.name}</p>
                          <p className="truncate text-xs text-faint">{(p.variants || []).length} Varianten {p.dolibarr_product_id && <span className="text-brand"><Link2 size={10} className="inline" /> Dolibarr</span>} {p.is_demo && "· Demo"} {!p.active && "· inaktiv"}</p>
                        </div>
                        <button onClick={() => openEdit(p)} className="rounded p-1 text-muted hover:text-brand"><Pencil size={14} /></button>
                        <button onClick={() => del(p.id)} className="rounded p-1 text-muted hover:text-red-400"><Trash2 size={14} /></button>
                      </div>
                    ))}
                    {prods.filter((p) => p.category_key === cat.key).length === 0 && <p className="text-xs text-faint">Keine Produkte.</p>}
                  </div>
                </Panel>
              ))}
            </div>
          )}
        </>
      )}

      <Dialog open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? "Produkt bearbeiten" : "Neues Produkt"} maxWidth="max-w-2xl">
        {edit && (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Kategorie"><Select value={edit.category_key} onChange={(e) => setEdit({ ...edit, category_key: e.target.value })} data-testid="abuilder-prod-cat">{cats.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}</Select></Field>
              <Field label="Name"><Input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} data-testid="abuilder-prod-name" /></Field>
            </div>
            <Field label="Beschreibung"><Textarea value={edit.description} onChange={(e) => setEdit({ ...edit, description: e.target.value })} /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="SKU"><Input value={edit.sku} onChange={(e) => setEdit({ ...edit, sku: e.target.value })} /></Field>
              <Field label="Dolibarr-Produkt (Preis-Quelle)">
                <Select value={edit.dolibarr_product_id} onChange={(e) => setEdit({ ...edit, dolibarr_product_id: e.target.value })}>
                  <option value="">— nicht verknüpft —</option>
                  {dolProds.map((p) => <option key={p.id} value={p.dolibarr_product_id}>{p.ref} · {p.label}</option>)}
                </Select>
              </Field>
            </div>
            <Field label="Kompatible Revisionen (Komma, leer = alle)">
              <Input value={(edit.compatible_versions || []).join(",")} onChange={(e) => setEdit({ ...edit, compatible_versions: e.target.value.split(",").map((s) => s.trim()).filter(Boolean) })} placeholder="BDM-030, BDM-040" />
            </Field>
            <div>
              <div className="mb-3 flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-ink">Varianten und Live-Farben</p>
                  <p className="text-xs text-faint">Der Hexwert färbt das gewählte Bauteil direkt in der Kundenvorschau.</p>
                </div>
                <Button type="button" size="sm" variant="outline" onClick={addVariant}><Plus size={14} /> Variante</Button>
              </div>
              <div className="max-h-[420px] space-y-3 overflow-y-auto pr-1">
                {(edit.variants || []).map((variant, index) => {
                  const validColor = /^#[0-9a-f]{6}$/i.test(variant.color_hex || "") ? variant.color_hex : "#1A1D22";
                  return (
                    <div key={index} className="rounded-xl border border-subtle p-3">
                      <div className="grid gap-3 sm:grid-cols-[1fr_7rem_auto]">
                        <Field label="Name"><Input value={variant.name || ""} onChange={(e) => updateVariant(index, { name: e.target.value })} /></Field>
                        <Field label="Aufpreis"><Input type="number" step="0.01" value={variant.price ?? 0} onChange={(e) => updateVariant(index, { price: Number(e.target.value) || 0 })} /></Field>
                        <button type="button" onClick={() => removeVariant(index)} className="mt-6 rounded-lg p-2 text-faint hover:bg-red-500/10 hover:text-red-400" aria-label="Variante entfernen"><Trash2 size={16} /></button>
                      </div>
                      <div className="mt-3 grid gap-3 sm:grid-cols-[3rem_1fr_1fr]">
                        <input type="color" value={validColor} onChange={(e) => updateVariant(index, { color_hex: e.target.value })} className="h-11 w-12 cursor-pointer rounded-lg border border-subtle bg-elevated p-1" aria-label="Farbe wählen" />
                        <Field label="Farbe (Hex)"><Input value={variant.color_hex || ""} onChange={(e) => updateVariant(index, { color_hex: e.target.value })} placeholder="#F26522" /></Field>
                        <Field label="SKU"><Input value={variant.sku || ""} onChange={(e) => updateVariant(index, { sku: e.target.value })} /></Field>
                      </div>
                      <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <Field label="Vorschaubild (optional)"><Input value={variant.thumb_url || ""} onChange={(e) => updateVariant(index, { thumb_url: e.target.value })} placeholder="/api/media/..." /></Field>
                        <Field label="Overlay-Bild (optional)"><Input value={variant.overlay_image_url || ""} onChange={(e) => updateVariant(index, { overlay_image_url: e.target.value })} placeholder="/api/media/..." /></Field>
                      </div>
                      <label className="mt-3 flex items-center gap-2 text-sm text-muted"><input type="checkbox" checked={variant.active !== false} onChange={(e) => updateVariant(index, { active: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Für Kunden auswählbar</label>
                    </div>
                  );
                })}
                {(edit.variants || []).length === 0 && <p className="rounded-xl border border-dashed border-subtle p-4 text-center text-xs text-faint">Noch keine Varianten angelegt.</p>}
              </div>
            </div>
            <div className="flex flex-wrap gap-4 text-sm text-muted">
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Aktiv</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.is_demo} onChange={(e) => setEdit({ ...edit, is_demo: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Demo</label>
            </div>
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setEdit(null)}>Abbrechen</Button><Button onClick={save} data-testid="abuilder-prod-save">Speichern</Button></div>
          </div>
        )}
      </Dialog>
    </>
  );
}

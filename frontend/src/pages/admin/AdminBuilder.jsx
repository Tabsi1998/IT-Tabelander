import React, { useEffect, useState } from "react";
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
  const [edit, setEdit] = useState(null);
  const [variantsText, setVariantsText] = useState("[]");

  useEffect(() => {
    api.get("/admin/builder/controllers").then(({ data }) => {
      setControllers(data); if (data[0]) setCk(data[0].key);
    }).catch(() => setControllers([]));
    api.get("/admin/dolibarr/products").then(({ data }) => setDolProds(data)).catch(() => {});
  }, []);

  const load = () => {
    if (!ck) return;
    api.get(`/admin/builder/${ck}/categories`).then(({ data }) => setCats(data)).catch(() => setCats([]));
    api.get(`/admin/builder/${ck}/products`).then(({ data }) => setProds(data)).catch(() => setProds([]));
  };
  useEffect(() => { load(); }, [ck]);

  const openEdit = (p, catKey) => {
    const d = p ? { ...p } : emptyProduct(ck, catKey);
    setVariantsText(JSON.stringify(d.variants || [], null, 2));
    setEdit(d);
  };

  const save = async () => {
    let variants = [];
    try { variants = JSON.parse(variantsText || "[]"); } catch { return toast.error("Varianten: ungültiges JSON"); }
    const payload = { ...edit, variants };
    try {
      if (edit.id) await api.put(`/admin/builder/products/${edit.id}`, payload);
      else await api.post("/admin/builder/products", payload);
      toast.success("Gespeichert"); setEdit(null); load();
    } catch { toast.error("Fehler beim Speichern"); }
  };

  const del = async (id) => { if (!window.confirm("Produkt löschen?")) return; await api.delete(`/admin/builder/products/${id}`); load(); };

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
            <Field label="Varianten (JSON: name, color_hex, price, overlay_image_url, layer{x,y,scale,rotation,z,side})">
              <Textarea value={variantsText} onChange={(e) => setVariantsText(e.target.value)} className="min-h-[160px] font-mono text-xs" />
            </Field>
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

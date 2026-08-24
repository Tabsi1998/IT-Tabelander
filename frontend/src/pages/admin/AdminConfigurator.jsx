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

const optEmpty = (ctype, cat) => ({
  configurator: ctype, category_key: cat || "", name: "", description: "", color_hex: "",
  image_url: "", overlay_image_url: "", dolibarr_product_id: "", sku: "", price: 0,
  price_on_request: false, available: true, active: true, is_demo: false, sort: 0,
  specs: {}, incompatible_with: [], depends_on: [],
});

const catEmpty = (ctype) => ({
  configurator: ctype, key: "", name: "", description: "",
  required: false, multi: false, sort: 0, active: true,
});

export default function AdminConfigurator() {
  const [ctype, setCtype] = useState("ps5");
  const [cats, setCats] = useState(null);
  const [opts, setOpts] = useState([]);
  const [products, setProducts] = useState([]);
  const [edit, setEdit] = useState(null);
  const [catEdit, setCatEdit] = useState(null);
  const [specText, setSpecText] = useState("{}");

  const load = () => {
    api.get(`/admin/configurator/${ctype}/categories`).then(({ data }) => setCats(data)).catch(() => setCats([]));
    api.get(`/admin/configurator/${ctype}/options`).then(({ data }) => setOpts(data)).catch(() => setOpts([]));
    api.get("/admin/dolibarr/products").then(({ data }) => setProducts(data)).catch(() => {});
  };
  useEffect(() => { load(); }, [ctype]);

  const openEdit = (o, catKey) => {
    const data = o ? { ...o } : optEmpty(ctype, catKey);
    setSpecText(JSON.stringify(data.specs || {}, null, 2));
    setEdit(data);
  };

  const saveCat = async () => {
    if (!catEdit.name || !catEdit.key) return toast.error("Name und Schlüssel sind Pflicht");
    const payload = { ...catEdit, sort: Number(catEdit.sort) || 0 };
    try {
      if (catEdit.id) await api.put(`/admin/configurator/categories/${catEdit.id}`, payload);
      else await api.post("/admin/configurator/categories", payload);
      toast.success("Kategorie gespeichert"); setCatEdit(null); load();
    } catch { toast.error("Fehler beim Speichern"); }
  };

  const delCat = async (id) => {
    if (!window.confirm("Kategorie löschen? Alle Optionen dieser Kategorie werden ebenfalls unwiderruflich gelöscht.")) return;
    await api.delete(`/admin/configurator/categories/${id}`); load();
  };

  const save = async () => {
    let specs = {};
    try { specs = JSON.parse(specText || "{}"); } catch { return toast.error("Specs: ungültiges JSON"); }
    const payload = { ...edit, specs, price: Number(edit.price) || 0 };
    try {
      if (edit.id) await api.put(`/admin/configurator/options/${edit.id}`, payload);
      else await api.post("/admin/configurator/options", payload);
      toast.success("Gespeichert"); setEdit(null); load();
    } catch { toast.error("Fehler beim Speichern"); }
  };

  const del = async (id) => { if (!window.confirm("Option löschen?")) return; await api.delete(`/admin/configurator/options/${id}`); load(); };

  const byCat = (key) => opts.filter((o) => o.category_key === key);

  return (
    <>
      <AdminHeader title="PC Builder & Konfiguratoren" desc="Kategorien, Optionen & Komponenten verwalten, Dolibarr-Produkte verknüpfen"
        action={<Button size="sm" onClick={() => setCatEdit(catEmpty(ctype))} data-testid="cfg-add-category"><Plus size={14} /> Kategorie</Button>} />
      <div className="mb-6 inline-flex rounded-xl border border-subtle p-1">
        {[["ps5", "PS5 Controller"], ["pc", "PC Builder"]].map(([k, l]) => (
          <button key={k} onClick={() => setCtype(k)} className={`rounded-lg px-4 py-2 text-sm font-medium transition-colors ${ctype === k ? "bg-brand text-ink" : "text-muted hover:text-ink"}`} data-testid={`cfg-tab-${k}`}>{l}</button>
        ))}
      </div>

      {!cats ? <Skeleton className="h-96" /> : cats.length === 0 ? <Empty>Keine Kategorien.</Empty> : (
        <div className="space-y-5">
          {cats.map((c) => (
            <Panel key={c.id}>
              <div className="mb-3 flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <h3 className="font-heading font-semibold text-ink">{c.name}</h3>
                  <Badge tone="neutral">{c.key}</Badge>
                  {c.required && <Badge tone="brand">Pflicht</Badge>}
                  {c.multi && <Badge tone="neutral">Mehrfach</Badge>}
                </div>
                <div className="flex items-center gap-1">
                  <button onClick={() => setCatEdit({ ...c })} className="rounded p-1.5 text-muted hover:text-brand" title="Kategorie bearbeiten" data-testid={`cfg-cat-edit-${c.key}`}><Pencil size={14} /></button>
                  <button onClick={() => delCat(c.id)} className="rounded p-1.5 text-muted hover:text-red-400" title="Kategorie löschen" data-testid={`cfg-cat-del-${c.key}`}><Trash2 size={14} /></button>
                  <Button size="sm" variant="outline" onClick={() => openEdit(null, c.key)} data-testid={`cfg-add-${c.key}`}><Plus size={14} /> Option</Button>
                </div>
              </div>
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {byCat(c.key).map((o) => (
                  <div key={o.id} className="flex items-center gap-2 rounded-lg border border-subtle p-2.5">
                    {o.color_hex && <span className="h-6 w-6 shrink-0 rounded-full border border-white/20" style={{ background: o.color_hex }} />}
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm text-ink">{o.name}</p>
                      <p className="truncate text-xs text-faint">
                        {o.price_on_request ? "auf Anfrage" : o.price ? EUR(o.price) : "inkl."}
                        {o.dolibarr_product_id && <span className="ml-1 text-brand"><Link2 size={10} className="inline" /> Dolibarr</span>}
                        {o.is_demo && " · Demo"}
                        {!o.active && " · inaktiv"}
                      </p>
                    </div>
                    <button onClick={() => openEdit(o)} className="rounded p-1 text-muted hover:text-brand" title="Option bearbeiten" data-testid={`cfg-opt-edit-${o.id}`}><Pencil size={14} /></button>
                    <button onClick={() => del(o.id)} className="rounded p-1 text-muted hover:text-red-400" title="Option löschen" data-testid={`cfg-opt-del-${o.id}`}><Trash2 size={14} /></button>
                  </div>
                ))}
                {byCat(c.key).length === 0 && <p className="text-xs text-faint">Keine Optionen.</p>}
              </div>
            </Panel>
          ))}
        </div>
      )}

      <Dialog open={!!catEdit} onClose={() => setCatEdit(null)} title={catEdit?.id ? "Kategorie bearbeiten" : "Neue Kategorie"} maxWidth="max-w-lg">
        {catEdit && (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Name"><Input value={catEdit.name} onChange={(e) => setCatEdit({ ...catEdit, name: e.target.value })} data-testid="cfg-cat-name" placeholder="z. B. Grafikkarte (GPU)" /></Field>
              <Field label="Schlüssel (technisch, eindeutig)"><Input value={catEdit.key} onChange={(e) => setCatEdit({ ...catEdit, key: e.target.value.toLowerCase().replace(/\s+/g, "_") })} data-testid="cfg-cat-key" placeholder="gpu" disabled={!!catEdit.id} /></Field>
            </div>
            <Field label="Beschreibung"><Textarea value={catEdit.description} onChange={(e) => setCatEdit({ ...catEdit, description: e.target.value })} /></Field>
            <Field label="Sortierung"><Input type="number" value={catEdit.sort} onChange={(e) => setCatEdit({ ...catEdit, sort: Number(e.target.value) })} /></Field>
            <div className="flex flex-wrap gap-4 text-sm text-muted">
              <label className="flex items-center gap-2"><input type="checkbox" checked={catEdit.active} onChange={(e) => setCatEdit({ ...catEdit, active: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Aktiv</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={catEdit.required} onChange={(e) => setCatEdit({ ...catEdit, required: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Pflichtauswahl</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={catEdit.multi} onChange={(e) => setCatEdit({ ...catEdit, multi: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Mehrfachauswahl</label>
            </div>
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setCatEdit(null)}>Abbrechen</Button><Button onClick={saveCat} data-testid="cfg-cat-save">Speichern</Button></div>
          </div>
        )}
      </Dialog>

      <Dialog open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? "Option bearbeiten" : "Neue Option"} maxWidth="max-w-2xl">
        {edit && (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Kategorie"><Select value={edit.category_key} onChange={(e) => setEdit({ ...edit, category_key: e.target.value })} data-testid="cfg-opt-category">{(cats || []).map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}</Select></Field>
              <Field label="Name"><Input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} data-testid="cfg-opt-name" /></Field>
            </div>
            <Field label="Beschreibung"><Textarea value={edit.description} onChange={(e) => setEdit({ ...edit, description: e.target.value })} /></Field>
            <div className="grid gap-4 sm:grid-cols-3">
              {ctype === "ps5" && <Field label="Farbe (Hex)"><Input value={edit.color_hex} onChange={(e) => setEdit({ ...edit, color_hex: e.target.value })} placeholder="#F26522" /></Field>}
              <Field label="Preis (€)"><Input type="number" value={edit.price} onChange={(e) => setEdit({ ...edit, price: e.target.value })} data-testid="cfg-opt-price" /></Field>
              <Field label="Sortierung"><Input type="number" value={edit.sort} onChange={(e) => setEdit({ ...edit, sort: Number(e.target.value) })} /></Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Dolibarr-Produkt (Preis-Quelle)">
                <Select value={edit.dolibarr_product_id} onChange={(e) => setEdit({ ...edit, dolibarr_product_id: e.target.value })} data-testid="cfg-opt-dolibarr">
                  <option value="">— nicht verknüpft —</option>
                  {products.map((p) => <option key={p.id} value={p.dolibarr_product_id}>{p.ref} · {p.label}</option>)}
                </Select>
              </Field>
              <Field label="SKU / Artikelnummer"><Input value={edit.sku} onChange={(e) => setEdit({ ...edit, sku: e.target.value })} /></Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Bild-URL"><Input value={edit.image_url} onChange={(e) => setEdit({ ...edit, image_url: e.target.value })} /></Field>
              <Field label="Overlay-Bild-URL"><Input value={edit.overlay_image_url} onChange={(e) => setEdit({ ...edit, overlay_image_url: e.target.value })} /></Field>
            </div>
            <Field label="Technische Daten (JSON, für Kompatibilität)"><Textarea value={specText} onChange={(e) => setSpecText(e.target.value)} className="min-h-[100px] font-mono text-xs" /></Field>
            <div className="flex flex-wrap gap-4 text-sm text-muted">
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Aktiv</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.available} onChange={(e) => setEdit({ ...edit, available: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Verfügbar</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.price_on_request} onChange={(e) => setEdit({ ...edit, price_on_request: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Preis auf Anfrage</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.is_demo} onChange={(e) => setEdit({ ...edit, is_demo: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Demo</label>
            </div>
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setEdit(null)}>Abbrechen</Button><Button onClick={save} data-testid="cfg-opt-save">Speichern</Button></div>
          </div>
        )}
      </Dialog>
    </>
  );
}

import React, { useEffect, useState } from "react";
import { Plus, Pencil, Trash2, Star, Eye, EyeOff } from "lucide-react";
import { toast } from "sonner";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Empty, Field } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Input, Textarea, Select } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import Dialog from "../../components/ui/dialog";

const EMPTY = { author: "", rating: 5, text: "", source: "manuell", is_demo: false, featured: false, visible: true, sort: 0 };

export default function AdminReviews() {
  const [items, setItems] = useState(null);
  const [edit, setEdit] = useState(null);

  const load = () => api.get("/admin/reviews").then(({ data }) => setItems(data)).catch(() => setItems([]));
  useEffect(() => { load(); }, []);

  const save = async () => {
    try {
      if (edit.id) await api.put(`/admin/reviews/${edit.id}`, edit);
      else await api.post("/admin/reviews", edit);
      toast.success("Gespeichert"); setEdit(null); load();
    } catch { toast.error("Fehler"); }
  };
  const del = async (id) => { if (!window.confirm("Löschen?")) return; await api.delete(`/admin/reviews/${id}`); load(); };
  const quick = async (r, patch) => { await api.put(`/admin/reviews/${r.id}`, { ...r, ...patch }); load(); };

  return (
    <>
      <AdminHeader title="Bewertungen" desc="Bewertungen kuratieren – nichts wird verändert oder erfunden"
        action={<Button onClick={() => setEdit({ ...EMPTY })} data-testid="review-add"><Plus size={16} /> Bewertung hinzufügen</Button>} />
      <div className="mb-4 rounded-lg border border-sky-500/30 bg-sky-500/10 p-3 text-xs text-sky-300">
        Hinweis: Keine gefälschten Bewertungen erstellen. Manuell erfasste Beispiele als „Demo" markieren.
      </div>
      {!items ? <Skeleton className="h-64" /> : items.length === 0 ? <Empty>Keine Bewertungen.</Empty> : (
        <div className="space-y-3">
          {items.map((r) => (
            <Panel key={r.id}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2">
                    <p className="font-medium text-ink">{r.author}</p>
                    <span className="flex">{Array.from({ length: 5 }).map((_, i) => <Star key={i} size={13} className={i < r.rating ? "fill-brand text-brand" : "text-faint"} />)}</span>
                    {r.featured && <Badge tone="brand">Featured</Badge>}
                    {r.is_demo && <Badge tone="demo">Demo</Badge>}
                    {!r.visible && <Badge tone="warning">Verborgen</Badge>}
                  </div>
                  <p className="mt-1 text-sm text-muted">"{r.text}"</p>
                </div>
                <div className="flex gap-1">
                  <button onClick={() => quick(r, { visible: !r.visible })} className="rounded-lg p-2 text-muted hover:bg-elevated" title="Sichtbar umschalten">{r.visible ? <Eye size={16} /> : <EyeOff size={16} />}</button>
                  <button onClick={() => quick(r, { featured: !r.featured })} className="rounded-lg p-2 text-muted hover:bg-elevated" title="Featured umschalten"><Star size={16} className={r.featured ? "fill-brand text-brand" : ""} /></button>
                  <button onClick={() => setEdit({ ...r })} className="rounded-lg p-2 text-muted hover:bg-elevated hover:text-brand"><Pencil size={16} /></button>
                  <button onClick={() => del(r.id)} className="rounded-lg p-2 text-muted hover:bg-red-500/10 hover:text-red-400"><Trash2 size={16} /></button>
                </div>
              </div>
            </Panel>
          ))}
        </div>
      )}
      <Dialog open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? "Bewertung bearbeiten" : "Neue Bewertung"}>
        {edit && (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Autor"><Input value={edit.author} onChange={(e) => setEdit({ ...edit, author: e.target.value })} data-testid="review-author" /></Field>
              <Field label="Bewertung (1-5)"><Select value={edit.rating} onChange={(e) => setEdit({ ...edit, rating: Number(e.target.value) })}>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</Select></Field>
            </div>
            <Field label="Text"><Textarea value={edit.text} onChange={(e) => setEdit({ ...edit, text: e.target.value })} data-testid="review-text" /></Field>
            <Field label="Quelle"><Input value={edit.source} onChange={(e) => setEdit({ ...edit, source: e.target.value })} placeholder="manuell / Google" /></Field>
            <div className="flex flex-wrap gap-4 text-sm text-muted">
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.visible} onChange={(e) => setEdit({ ...edit, visible: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Sichtbar</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.featured} onChange={(e) => setEdit({ ...edit, featured: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Featured</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={edit.is_demo} onChange={(e) => setEdit({ ...edit, is_demo: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Demo</label>
            </div>
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setEdit(null)}>Abbrechen</Button><Button onClick={save} data-testid="review-save">Speichern</Button></div>
          </div>
        )}
      </Dialog>
    </>
  );
}

import React, { useEffect, useState } from "react";
import { Plus, Pencil, Trash2 } from "lucide-react";
import { toast } from "sonner";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Empty, Field } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Input, Textarea } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import Dialog from "../../components/ui/dialog";

const EMPTY = { title: "", short_description: "", long_description: "", icon: "wrench", slug: "", bullets: [], seo_title: "", seo_description: "", sort: 0, active: true };

export default function AdminServices() {
  const [items, setItems] = useState(null);
  const [edit, setEdit] = useState(null);

  const load = () => api.get("/services?include_inactive=true").then(({ data }) => setItems(data)).catch(() => setItems([]));
  useEffect(() => { load(); }, []);

  const save = async () => {
    const payload = { ...edit, bullets: typeof edit.bullets === "string" ? edit.bullets.split("\n").filter(Boolean) : edit.bullets };
    try {
      if (edit.id) await api.put(`/admin/services/${edit.id}`, payload);
      else await api.post("/admin/services", payload);
      toast.success("Gespeichert");
      setEdit(null);
      load();
    } catch { toast.error("Fehler beim Speichern"); }
  };

  const del = async (id) => { if (!window.confirm("Löschen?")) return; await api.delete(`/admin/services/${id}`); toast.success("Gelöscht"); load(); };

  return (
    <>
      <AdminHeader title="Leistungen" desc="Leistungen erstellen, bearbeiten und sortieren"
        action={<Button onClick={() => setEdit({ ...EMPTY })} data-testid="service-add"><Plus size={16} /> Neue Leistung</Button>} />
      {!items ? <Skeleton className="h-64" /> : items.length === 0 ? <Empty>Keine Leistungen.</Empty> : (
        <div className="space-y-3">
          {items.map((s) => (
            <Panel key={s.id}>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2">
                    <p className="font-medium text-ink">{s.title}</p>
                    {!s.active && <Badge tone="warning">Inaktiv</Badge>}
                    <Badge tone="neutral">/{s.slug}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-muted">{s.short_description}</p>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => setEdit({ ...s, bullets: (s.bullets || []).join("\n") })} className="rounded-lg p-2 text-muted hover:bg-elevated hover:text-brand" data-testid={`service-edit-${s.slug}`}><Pencil size={16} /></button>
                  <button onClick={() => del(s.id)} className="rounded-lg p-2 text-muted hover:bg-red-500/10 hover:text-red-400"><Trash2 size={16} /></button>
                </div>
              </div>
            </Panel>
          ))}
        </div>
      )}

      <Dialog open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? "Leistung bearbeiten" : "Neue Leistung"} maxWidth="max-w-2xl">
        {edit && (
          <div className="space-y-4">
            <Field label="Titel"><Input value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} data-testid="service-title" /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Slug (optional)"><Input value={edit.slug} onChange={(e) => setEdit({ ...edit, slug: e.target.value })} placeholder="auto" /></Field>
              <Field label="Icon"><Input value={edit.icon} onChange={(e) => setEdit({ ...edit, icon: e.target.value })} placeholder="wrench, cpu, laptop ..." /></Field>
            </div>
            <Field label="Kurzbeschreibung"><Textarea value={edit.short_description} onChange={(e) => setEdit({ ...edit, short_description: e.target.value })} /></Field>
            <Field label="Ausführliche Beschreibung"><Textarea value={edit.long_description} onChange={(e) => setEdit({ ...edit, long_description: e.target.value })} /></Field>
            <Field label="Bulletpoints (eine pro Zeile)"><Textarea value={edit.bullets} onChange={(e) => setEdit({ ...edit, bullets: e.target.value })} /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="SEO Title"><Input value={edit.seo_title} onChange={(e) => setEdit({ ...edit, seo_title: e.target.value })} /></Field>
              <Field label="Sortierung"><Input type="number" value={edit.sort} onChange={(e) => setEdit({ ...edit, sort: Number(e.target.value) })} /></Field>
            </div>
            <Field label="SEO Description"><Textarea value={edit.seo_description} onChange={(e) => setEdit({ ...edit, seo_description: e.target.value })} /></Field>
            <label className="flex items-center gap-2 text-sm text-muted"><input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Aktiv</label>
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setEdit(null)}>Abbrechen</Button><Button onClick={save} data-testid="service-save">Speichern</Button></div>
          </div>
        )}
      </Dialog>
    </>
  );
}

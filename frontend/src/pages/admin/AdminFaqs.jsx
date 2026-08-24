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

const EMPTY = { question: "", answer: "", category: "allgemein", sort: 0, active: true };

export default function AdminFaqs() {
  const [items, setItems] = useState(null);
  const [edit, setEdit] = useState(null);

  const load = () => api.get("/faqs?include_inactive=true").then(({ data }) => setItems(data)).catch(() => setItems([]));
  useEffect(() => { load(); }, []);

  const save = async () => {
    try {
      if (edit.id) await api.put(`/admin/faqs/${edit.id}`, edit);
      else await api.post("/admin/faqs", edit);
      toast.success("Gespeichert"); setEdit(null); load();
    } catch { toast.error("Fehler"); }
  };
  const del = async (id) => { if (!window.confirm("Löschen?")) return; await api.delete(`/admin/faqs/${id}`); load(); };

  return (
    <>
      <AdminHeader title="FAQs" desc="Häufige Fragen verwalten"
        action={<Button onClick={() => setEdit({ ...EMPTY })} data-testid="faq-add"><Plus size={16} /> Neue FAQ</Button>} />
      {!items ? <Skeleton className="h-64" /> : items.length === 0 ? <Empty>Keine FAQs.</Empty> : (
        <div className="space-y-3">
          {items.map((f) => (
            <Panel key={f.id}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2"><p className="font-medium text-white">{f.question}</p><Badge tone="neutral">{f.category}</Badge>{!f.active && <Badge tone="warning">Inaktiv</Badge>}</div>
                  <p className="mt-1 text-sm text-slate-400">{f.answer}</p>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => setEdit({ ...f })} className="rounded-lg p-2 text-slate-400 hover:bg-white/5 hover:text-brand"><Pencil size={16} /></button>
                  <button onClick={() => del(f.id)} className="rounded-lg p-2 text-slate-400 hover:bg-red-500/10 hover:text-red-400"><Trash2 size={16} /></button>
                </div>
              </div>
            </Panel>
          ))}
        </div>
      )}
      <Dialog open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? "FAQ bearbeiten" : "Neue FAQ"}>
        {edit && (
          <div className="space-y-4">
            <Field label="Frage"><Input value={edit.question} onChange={(e) => setEdit({ ...edit, question: e.target.value })} data-testid="faq-question" /></Field>
            <Field label="Antwort"><Textarea value={edit.answer} onChange={(e) => setEdit({ ...edit, answer: e.target.value })} data-testid="faq-answer" /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Kategorie"><Input value={edit.category} onChange={(e) => setEdit({ ...edit, category: e.target.value })} /></Field>
              <Field label="Sortierung"><Input type="number" value={edit.sort} onChange={(e) => setEdit({ ...edit, sort: Number(e.target.value) })} /></Field>
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} className="h-4 w-4 accent-[#F26522]" /> Aktiv</label>
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setEdit(null)}>Abbrechen</Button><Button onClick={save} data-testid="faq-save">Speichern</Button></div>
          </div>
        )}
      </Dialog>
    </>
  );
}

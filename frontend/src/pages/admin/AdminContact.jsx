import React, { useEffect, useState } from "react";
import { Trash2, MailOpen } from "lucide-react";
import { toast } from "sonner";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Empty } from "../../components/admin/AdminUI";
import { Badge } from "../../components/ui/badge";

export default function AdminContact() {
  const [items, setItems] = useState(null);
  const load = () => api.get("/admin/contact").then(({ data }) => setItems(data)).catch(() => setItems([]));
  useEffect(() => { load(); }, []);

  const markRead = async (id) => { await api.patch(`/admin/contact/${id}/read`); load(); };
  const del = async (id) => { if (!window.confirm("Löschen?")) return; await api.delete(`/admin/contact/${id}`); toast.success("Gelöscht"); load(); };

  return (
    <>
      <AdminHeader title="Nachrichten" desc="Kontaktformular-Anfragen" />
      {!items ? <Skeleton className="h-64" /> : items.length === 0 ? <Empty>Keine Nachrichten.</Empty> : (
        <div className="space-y-3">
          {items.map((m) => (
            <Panel key={m.id}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="font-medium text-white">{m.name}</p>
                    {m.status === "neu" && <Badge tone="brand">Neu</Badge>}
                  </div>
                  <p className="text-xs text-slate-500">{m.email} {m.phone && `· ${m.phone}`} · {new Date(m.created_at).toLocaleString("de-AT")}</p>
                  {m.subject && <p className="mt-2 text-sm font-medium text-slate-300">{m.subject}</p>}
                  <p className="mt-1 text-sm text-slate-400">{m.message}</p>
                </div>
                <div className="flex gap-2">
                  {m.status === "neu" && <button onClick={() => markRead(m.id)} className="rounded-lg p-2 text-slate-500 hover:bg-white/5 hover:text-emerald-400" title="Als gelesen"><MailOpen size={16} /></button>}
                  <button onClick={() => del(m.id)} className="rounded-lg p-2 text-slate-500 hover:bg-red-500/10 hover:text-red-400"><Trash2 size={16} /></button>
                </div>
              </div>
            </Panel>
          ))}
        </div>
      )}
    </>
  );
}

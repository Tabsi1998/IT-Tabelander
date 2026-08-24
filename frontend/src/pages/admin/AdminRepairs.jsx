import React, { useEffect, useState } from "react";
import { Trash2, Eye, ChevronDown } from "lucide-react";
import { toast } from "sonner";
import api, { mediaUrl } from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Empty } from "../../components/admin/AdminUI";
import { Select } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";

const STATUSES = ["eingegangen", "in_diagnose", "warten_auf_teile", "in_reparatur", "fertig", "abgeschlossen", "abgelehnt"];
const LABEL = {
  eingegangen: "Eingegangen", in_diagnose: "In Diagnose", warten_auf_teile: "Warten auf Teile",
  in_reparatur: "In Reparatur", fertig: "Fertig", abgeschlossen: "Abgeschlossen", abgelehnt: "Abgelehnt",
};

export default function AdminRepairs() {
  const [items, setItems] = useState(null);
  const [expanded, setExpanded] = useState(null);
  const [filter, setFilter] = useState("");

  const load = () => {
    const q = filter ? `?status=${filter}` : "";
    api.get(`/admin/repairs${q}`).then(({ data }) => setItems(data)).catch(() => setItems([]));
  };
  useEffect(() => { load(); }, [filter]);

  const setStatus = async (id, status) => {
    try {
      await api.patch(`/admin/repairs/${id}/status`, { status });
      toast.success("Status aktualisiert");
      load();
    } catch { toast.error("Fehler beim Aktualisieren"); }
  };

  const del = async (id) => {
    if (!window.confirm("Anfrage löschen?")) return;
    await api.delete(`/admin/repairs/${id}`);
    toast.success("Gelöscht");
    load();
  };

  return (
    <>
      <AdminHeader title="Reparaturanfragen" desc="Alle eingegangenen Anfragen verwalten"
        action={
          <Select value={filter} onChange={(e) => setFilter(e.target.value)} className="w-52" data-testid="repair-filter">
            <option value="">Alle Status</option>
            {STATUSES.map((s) => <option key={s} value={s}>{LABEL[s]}</option>)}
          </Select>
        } />
      {!items ? <Skeleton className="h-64" /> : items.length === 0 ? <Empty>Keine Anfragen gefunden.</Empty> : (
        <div className="space-y-3">
          {items.map((r) => (
            <Panel key={r.id} className="p-0">
              <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                <button onClick={() => setExpanded(expanded === r.id ? null : r.id)} className="flex min-w-0 flex-1 items-center gap-3 text-left" data-testid={`repair-row-${r.ref}`}>
                  <ChevronDown size={16} className={`shrink-0 text-faint transition-transform ${expanded === r.id ? "rotate-180" : ""}`} />
                  <div className="min-w-0">
                    <p className="truncate font-medium text-ink">{r.ref} · {r.device_type} {r.model && `– ${r.model}`}</p>
                    <p className="truncate text-xs text-faint">{r.contact?.name} · {r.contact?.email} · {new Date(r.created_at).toLocaleDateString("de-AT")}</p>
                  </div>
                </button>
                <div className="flex items-center gap-2">
                  <Select value={r.status} onChange={(e) => setStatus(r.id, e.target.value)} className="w-44 py-2 text-sm" data-testid={`repair-status-${r.ref}`}>
                    {STATUSES.map((s) => <option key={s} value={s}>{LABEL[s]}</option>)}
                  </Select>
                  <button onClick={() => del(r.id)} className="rounded-lg p-2 text-faint hover:bg-red-500/10 hover:text-red-400" data-testid={`repair-delete-${r.ref}`}><Trash2 size={16} /></button>
                </div>
              </div>
              {expanded === r.id && (
                <div className="border-t border-subtle bg-black/20 p-4 text-sm">
                  <div className="grid gap-4 md:grid-cols-2">
                    <div>
                      <p className="text-muted">Hersteller: <span className="text-ink">{r.manufacturer || "–"}</span></p>
                      <p className="text-muted">Fehler: <span className="text-ink">{r.issues?.join(", ") || "–"}</span></p>
                      <p className="mt-2 text-muted">Beschreibung:</p>
                      <p className="text-ink">{r.description || "–"}</p>
                      <p className="mt-2 text-muted">Kontakt bevorzugt: <span className="text-ink">{r.contact?.preferred_contact}</span> {r.contact?.phone && `· ${r.contact.phone}`}</p>
                      <p className="mt-2"><Badge tone={r.dolibarr?.demo ? "demo" : r.dolibarr?.created ? "success" : "neutral"}>Dolibarr: {r.dolibarr?.created ? "Ticket erstellt" : r.dolibarr?.demo ? "Demo" : "nicht verknüpft"}</Badge></p>
                    </div>
                    <div>
                      {r.attachment_ids?.length > 0 ? (
                        <div className="grid grid-cols-3 gap-2">
                          {r.attachment_ids.map((_, i) => <div key={i} className="rounded-lg border border-subtle p-2 text-center text-xs text-faint">Anhang {i + 1}</div>)}
                        </div>
                      ) : <p className="text-faint">Keine Anhänge</p>}
                    </div>
                  </div>
                </div>
              )}
            </Panel>
          ))}
        </div>
      )}
    </>
  );
}

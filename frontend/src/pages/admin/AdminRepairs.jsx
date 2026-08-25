import React, { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { ChevronDown, Loader2, RefreshCw, Trash2 } from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError, mediaUrl } from "../../lib/api";
import { AdminHeader, Empty, Panel } from "../../components/admin/AdminUI";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Select } from "../../components/ui/input";
import Skeleton from "../../components/ui/skeleton";

const CURRENT_STATUSES = ["eingegangen", "in_bearbeitung", "wartet_auf_kunde", "angebot_erstellt", "beauftragt", "abgeschlossen", "abgelehnt"];
const LEGACY_STATUSES = ["in_diagnose", "warten_auf_teile", "in_reparatur", "fertig"];
const STATUS_LABELS = {
  eingegangen: "Eingegangen", in_bearbeitung: "In Bearbeitung", wartet_auf_kunde: "Wartet auf Kunde",
  angebot_erstellt: "Angebot erstellt", beauftragt: "Beauftragt", abgeschlossen: "Abgeschlossen", abgelehnt: "Abgelehnt",
  in_diagnose: "In Diagnose (alt)", warten_auf_teile: "Warten auf Teile (alt)",
  in_reparatur: "In Reparatur (alt)", fertig: "Fertig (alt)",
};
const REQUEST_LABELS = {
  repair: "Reparatur", pc_build: "PC-Neubau", pc_upgrade: "PC-/Notebook-Upgrade",
  controller_custom: "Controller-Umbau", consulting: "Beratung", other: "Sonstiges",
};
const DEVICE_LABELS = {
  pc: "Desktop-PC", notebook: "Notebook", playstation: "PlayStation", xbox: "Xbox",
  switch: "Nintendo Switch", controller: "Controller", storage: "Datenträger", other: "Sonstiges",
};
const SOURCE_LABELS = {
  new_controller: "Neuen Controller mitbestellen", send_in: "Vorhandenen Controller einsenden", unsure: "Noch unsicher",
};

function itemId(item) { return item.id || item._id; }
function itemRef(item) { return item.ref || item.request_id || itemId(item) || "Anfrage"; }
function displayDate(value) {
  if (!value) return "–";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "–" : date.toLocaleString("de-AT", { dateStyle: "medium", timeStyle: "short" });
}
function dolibarrErrorText(error) {
  if (!error) return "";
  if (typeof error === "string") return error;
  if (typeof error === "object") {
    return [error.message, error.detail].filter(Boolean).join(" – ") || "Unbekannter Dolibarr-Fehler";
  }
  return String(error);
}
function dolibarrInfo(item) {
  const data = item.dolibarr || item.dolibarr_sync || {};
  return {
    synced: Boolean(data.synced),
    thirdpartyId: data.thirdparty_id,
    ticketId: data.ticket_id ?? item.dolibarr_ticket_id,
    ticketRef: data.ticket_ref ?? item.dolibarr_ticket_ref,
    error: dolibarrErrorText(data.error ?? data.last_error ?? item.dolibarr_error),
    demo: Boolean(data.demo),
    syncedAt: data.synced_at ?? data.last_sync_at,
  };
}

function AttachmentList({ item }) {
  const attachments = item.attachments || [];
  const ids = item.attachment_ids || [];
  if (!attachments.length && !ids.length) return <p className="text-faint">Keine Fotos</p>;
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
      {attachments.map((attachment, index) => {
        const url = typeof attachment === "string" ? attachment : attachment.url;
        return url ? <a key={`${url}-${index}`} href={mediaUrl(url)} target="_blank" rel="noreferrer" className="group aspect-square overflow-hidden rounded-lg border border-subtle bg-elevated"><img src={mediaUrl(url)} alt={`Anfragefoto ${index + 1}`} className="h-full w-full object-cover transition-transform group-hover:scale-105" /></a> : null;
      })}
      {!attachments.length && ids.map((id, index) => <div key={id || index} className="rounded-lg border border-subtle p-3 text-center text-xs text-faint">Foto {index + 1}</div>)}
    </div>
  );
}

export default function AdminRepairs() {
  const [items, setItems] = useState(null);
  const [expanded, setExpanded] = useState(null);
  const [filter, setFilter] = useState("");
  const [syncing, setSyncing] = useState(null);

  const load = useCallback(async () => {
    try {
      const params = filter ? { status: filter } : undefined;
      const { data } = await api.get("/admin/inquiries", { params });
      setItems(Array.isArray(data) ? data : data.items || []);
    } catch (error) {
      setItems([]);
      toast.error(formatApiError(error.response?.data?.detail || "Anfragen konnten nicht geladen werden."));
    }
  }, [filter]);
  useEffect(() => { load(); }, [load]);

  const setStatus = async (id, status) => {
    try {
      await api.patch(`/admin/inquiries/${id}/status`, { status });
      setItems((current) => current?.map((item) => itemId(item) === id ? { ...item, status } : item));
      toast.success("Status aktualisiert.");
    } catch (error) { toast.error(formatApiError(error.response?.data?.detail || "Status konnte nicht aktualisiert werden.")); }
  };

  const retryDolibarr = async (id) => {
    setSyncing(id);
    try {
      const { data } = await api.post(`/admin/inquiries/${id}/sync-dolibarr`);
      if (data.ok || data.already_synced) {
        toast.success(data.already_synced ? "Anfrage war bereits mit Dolibarr synchronisiert." : "Dolibarr-Synchronisierung abgeschlossen.");
      } else {
        toast.error(dolibarrErrorText(data.dolibarr?.error) || "Dolibarr-Synchronisierung fehlgeschlagen.");
      }
      await load();
    } catch (error) {
      toast.error(formatApiError(error.response?.data?.detail || "Dolibarr-Synchronisierung fehlgeschlagen."));
      await load();
    } finally { setSyncing(null); }
  };

  const remove = async (id) => {
    if (!window.confirm("Diese Anfrage dauerhaft löschen?")) return;
    try {
      await api.delete(`/admin/inquiries/${id}`);
      toast.success("Anfrage gelöscht.");
      setExpanded((current) => current === id ? null : current);
      await load();
    } catch (error) { toast.error(formatApiError(error.response?.data?.detail || "Anfrage konnte nicht gelöscht werden.")); }
  };

  return (
    <>
      <AdminHeader title="Anfragen" desc="Reparaturen, PC-Projekte, Upgrades, Controller-Umbauten und Beratungen zentral verwalten"
        action={<Select value={filter} onChange={(event) => setFilter(event.target.value)} className="w-full sm:w-52" data-testid="inquiry-filter" aria-label="Anfragen nach Status filtern">
          <option value="">Alle Status</option>
          {CURRENT_STATUSES.map((status) => <option key={status} value={status}>{STATUS_LABELS[status]}</option>)}
          <optgroup label="Frühere Statuswerte">{LEGACY_STATUSES.map((status) => <option key={status} value={status}>{STATUS_LABELS[status]}</option>)}</optgroup>
        </Select>} />

      {!items ? <Skeleton className="h-64" /> : items.length === 0 ? <Empty>Keine Anfragen gefunden.</Empty> : (
        <div className="space-y-3">
          {items.map((item) => {
            const id = itemId(item); const ref = itemRef(item); const isExpanded = expanded === id;
            const dolibarr = dolibarrInfo(item);
            const statusOptions = CURRENT_STATUSES.includes(item.status) ? CURRENT_STATUSES : [...CURRENT_STATUSES, item.status].filter(Boolean);
            const hasDolibarrIds = Boolean(dolibarr.thirdpartyId || dolibarr.ticketId);
            return (
              <Panel key={id || ref} className="p-0">
                <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                  <button type="button" onClick={() => setExpanded(isExpanded ? null : id)} className="flex min-w-0 flex-1 items-start gap-3 rounded-lg text-left outline-none focus-visible:ring-2 focus-visible:ring-brand" aria-expanded={isExpanded} data-testid={`inquiry-row-${ref}`}>
                    <ChevronDown size={17} className={`mt-1 shrink-0 text-faint transition-transform ${isExpanded ? "rotate-180" : ""}`} />
                    <span className="min-w-0"><span className="flex flex-wrap items-center gap-2"><span className="font-medium text-ink">{ref}</span><Badge tone="brand">{REQUEST_LABELS[item.request_type] || item.request_type || "Anfrage"}</Badge>{dolibarr.error ? <Badge tone="warning">Dolibarr-Fehler</Badge> : hasDolibarrIds ? <Badge tone="success">Dolibarr verknüpft</Badge> : <Badge tone="neutral">Dolibarr offen</Badge>}</span><span className="mt-1 block truncate text-xs text-faint">{item.contact?.name || "Ohne Namen"} · {item.contact?.email || "Keine E-Mail"} · {displayDate(item.created_at)}</span></span>
                  </button>
                  <div className="flex items-center gap-2 pl-7 lg:pl-0">
                    <Select value={item.status || "eingegangen"} onChange={(event) => setStatus(id, event.target.value)} className="min-w-0 flex-1 py-2 text-sm sm:w-44 sm:flex-none" data-testid={`inquiry-status-${ref}`} aria-label={`Status von ${ref}`}>{statusOptions.map((status) => <option key={status} value={status}>{STATUS_LABELS[status] || status}</option>)}</Select>
                    <button type="button" onClick={() => remove(id)} className="rounded-lg p-2 text-faint outline-none hover:bg-red-500/10 hover:text-red-400 focus-visible:ring-2 focus-visible:ring-red-400" aria-label={`${ref} löschen`}><Trash2 size={17} /></button>
                  </div>
                </div>

                {isExpanded && <div className="border-t border-subtle bg-black/20 p-4 text-sm sm:p-5">
                  <div className="grid gap-6 xl:grid-cols-[1.25fr_1fr]">
                    <div className="space-y-5">
                      <section><h3 className="font-semibold text-ink">Anfrage</h3><dl className="mt-2 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        <div><dt className="text-faint">Art</dt><dd className="text-ink">{REQUEST_LABELS[item.request_type] || item.request_type || "–"}</dd></div>
                        <div><dt className="text-faint">Gerät</dt><dd className="text-ink">{[DEVICE_LABELS[item.device_type] || item.device_type, item.manufacturer, item.model].filter(Boolean).join(" · ") || "–"}</dd></div>
                        {item.device_source && <div><dt className="text-faint">Controller</dt><dd className="text-ink">{SOURCE_LABELS[item.device_source] || item.device_source}</dd></div>}
                        <div><dt className="text-faint">Budget</dt><dd className="text-ink">{item.budget || "–"}</dd></div><div><dt className="text-faint">Zeitraum</dt><dd className="text-ink">{item.timeframe || "–"}</dd></div>
                        <div><dt className="text-faint">Anfrage-ID</dt><dd className="break-all font-mono text-xs text-ink">{item.request_id || ref}</dd></div>
                      </dl></section>
                      {(item.issues?.length > 0 || item.desired_services?.length > 0) && <section><h3 className="font-semibold text-ink">Probleme & Wünsche</h3>{item.issues?.length > 0 && <p className="mt-2 text-muted">Fehler: <span className="text-ink">{item.issues.join(", ")}</span></p>}{item.desired_services?.length > 0 && <p className="mt-1 text-muted">Gewünscht: <span className="text-ink">{item.desired_services.join(", ")}</span></p>}</section>}
                      <section><h3 className="font-semibold text-ink">Beschreibung</h3><p className="mt-2 whitespace-pre-wrap text-ink">{item.description || "–"}</p></section>
                      <section><h3 className="font-semibold text-ink">Kontakt</h3><div className="mt-2 text-muted"><p><span className="text-ink">{item.contact?.name || "–"}</span></p><p><a className="text-brand hover:underline" href={`mailto:${item.contact?.email || ""}`}>{item.contact?.email || "–"}</a></p>{item.contact?.phone && <p><a className="text-brand hover:underline" href={`tel:${item.contact.phone}`}>{item.contact.phone}</a></p>}<p className="mt-1 text-xs text-faint">Bevorzugt: {item.contact?.preferred_contact === "phone" ? "Telefon" : "E-Mail"}</p></div></section>
                    </div>
                    <div className="space-y-5">
                      <section className="rounded-xl border border-subtle bg-elevated/30 p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-semibold text-ink">Dolibarr</h3><p className="mt-1 text-xs text-faint">Interessent und Ticket zur Anfrage</p></div><Button type="button" size="sm" variant="outline" onClick={() => retryDolibarr(id)} disabled={syncing === id || dolibarr.synced || dolibarr.demo}>{syncing === id ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}{syncing === id ? "Synchronisiere …" : dolibarr.synced ? "Bereits synchronisiert" : dolibarr.demo ? "Dolibarr nicht aktiviert" : "Erneut synchronisieren"}</Button></div>
                        <dl className="mt-4 space-y-2"><div className="flex justify-between gap-4"><dt className="text-muted">Interessent-ID</dt><dd className="text-right font-mono text-ink">{dolibarr.thirdpartyId || "–"}</dd></div><div className="flex justify-between gap-4"><dt className="text-muted">Ticket-ID</dt><dd className="text-right font-mono text-ink">{dolibarr.ticketId || "–"}</dd></div>{dolibarr.ticketRef && <div className="flex justify-between gap-4"><dt className="text-muted">Ticket-Ref.</dt><dd className="text-right font-mono text-ink">{dolibarr.ticketRef}</dd></div>}{dolibarr.syncedAt && <div className="flex justify-between gap-4"><dt className="text-muted">Letzter Sync</dt><dd className="text-right text-ink">{displayDate(dolibarr.syncedAt)}</dd></div>}</dl>
                        {dolibarr.demo && <p className="mt-3 rounded-lg bg-sky-500/10 p-2 text-xs text-sky-300">Dolibarr ist nicht aktiviert. <Link to="/admin/einstellungen" className="underline">Einstellungen öffnen</Link></p>}{dolibarr.error && <p className="mt-3 break-words rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-300">{dolibarr.error}</p>}
                      </section>
                      <section><h3 className="mb-2 font-semibold text-ink">Fotos</h3><AttachmentList item={item} /></section>
                    </div>
                  </div>
                </div>}
              </Panel>
            );
          })}
        </div>
      )}
    </>
  );
}

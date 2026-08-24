import React, { useEffect, useState } from "react";
import { RefreshCw, CheckCircle2, XCircle, Loader2, Database, Package } from "lucide-react";
import { toast } from "sonner";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Empty } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Badge } from "../../components/ui/badge";

export default function AdminDolibarr() {
  const [status, setStatus] = useState(null);
  const [products, setProducts] = useState([]);
  const [logs, setLogs] = useState([]);
  const [syncing, setSyncing] = useState(false);

  const load = () => {
    api.get("/admin/dolibarr/status").then(({ data }) => setStatus(data)).catch(() => setStatus({}));
    api.get("/admin/dolibarr/products").then(({ data }) => setProducts(data)).catch(() => {});
    api.get("/admin/dolibarr/logs").then(({ data }) => setLogs(data)).catch(() => {});
  };
  useEffect(() => { load(); }, []);

  const sync = async () => {
    setSyncing(true);
    try {
      const { data } = await api.post("/admin/dolibarr/sync");
      toast[data.status === "error" ? "error" : "success"](data.message);
      load();
    } catch { toast.error("Sync fehlgeschlagen"); } finally { setSyncing(false); }
  };

  if (!status) return (<><AdminHeader title="Dolibarr Sync" /><Skeleton className="h-64" /></>);

  const conn = status.connection || {};

  return (
    <>
      <AdminHeader title="Dolibarr Synchronisation" desc="Produkte aus dem ERP synchronisieren"
        action={<Button onClick={sync} disabled={syncing} data-testid="dolibarr-sync">{syncing ? <Loader2 className="animate-spin" size={16} /> : <RefreshCw size={16} />} Jetzt synchronisieren</Button>} />

      <div className="grid gap-4 md:grid-cols-3">
        <Panel>
          <div className="flex items-center gap-2">
            {status.enabled ? <CheckCircle2 size={18} className="text-emerald-400" /> : <XCircle size={18} className="text-faint" />}
            <h3 className="font-semibold text-ink">Status</h3>
          </div>
          <p className="mt-2 text-sm text-muted">{status.enabled ? "Dolibarr aktiv" : "Demo-Modus"}</p>
          <p className="mt-1 text-xs text-faint">{conn.message}</p>
          {!status.enabled && (
            <p className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-2 text-xs text-amber-300">
              Dolibarr unter „Einstellungen“ aktivieren und dort URL sowie API-Key hinterlegen.
            </p>
          )}
        </Panel>
        <Panel>
          <div className="flex items-center gap-2"><Database size={18} className="text-brand" /><h3 className="font-semibold text-ink">Letzter Sync</h3></div>
          {status.last_sync ? (
            <>
              <Badge tone={status.last_sync.status === "success" ? "success" : status.last_sync.status === "error" ? "warning" : "demo"} className="mt-2">{status.last_sync.status}</Badge>
              <p className="mt-2 text-xs text-faint">{new Date(status.last_sync.finished_at).toLocaleString("de-AT")}</p>
            </>
          ) : <p className="mt-2 text-sm text-faint">Noch kein Sync.</p>}
        </Panel>
        <Panel>
          <div className="flex items-center gap-2"><Package size={18} className="text-brand" /><h3 className="font-semibold text-ink">Produkte im Cache</h3></div>
          <p className="mt-2 text-3xl font-bold text-ink">{status.cached_products ?? 0}</p>
        </Panel>
      </div>

      <Panel className="mt-6">
        <h3 className="mb-4 font-semibold text-ink">Zwischengespeicherte Produkte</h3>
        {products.length === 0 ? <Empty>Keine Produkte im Cache. Führe einen Sync durch (benötigt aktiven API-Key).</Empty> : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-xs uppercase text-faint"><tr><th className="py-2">Ref</th><th>Name</th><th>Preis</th><th>Lager</th></tr></thead>
              <tbody className="divide-y divide-subtle">
                {products.map((p) => (
                  <tr key={p.id}><td className="py-2 font-mono text-muted">{p.ref}</td><td className="text-ink">{p.label}</td><td className="text-brand">{p.price} €</td><td className="text-muted">{p.stock ?? "–"}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Panel className="mt-6">
        <h3 className="mb-4 font-semibold text-ink">Sync-Protokoll</h3>
        {logs.length === 0 ? <Empty>Keine Einträge.</Empty> : (
          <div className="space-y-2">
            {logs.map((l) => (
              <div key={l.id} className="flex items-center justify-between rounded-lg border border-subtle p-3 text-sm">
                <span className="text-muted">{l.message}</span>
                <div className="flex items-center gap-3">
                  <Badge tone={l.status === "success" ? "success" : l.status === "error" ? "warning" : "demo"}>{l.status}</Badge>
                  <span className="text-xs text-faint">{new Date(l.finished_at).toLocaleString("de-AT")}</span>
                </div>
              </div>
            ))}
          </div>
        )}
      </Panel>
    </>
  );
}

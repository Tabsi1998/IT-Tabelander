import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  RefreshCw, CheckCircle2, XCircle, Loader2, Users, TicketCheck, CircleAlert,
} from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError } from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Badge } from "../../components/ui/badge";

export default function AdminDolibarr() {
  const [status, setStatus] = useState(null);
  const [checking, setChecking] = useState(false);
  const [loadError, setLoadError] = useState("");

  const load = async (notify = false) => {
    setChecking(true);
    setLoadError("");
    try {
      const { data } = await api.get("/admin/dolibarr/status");
      setStatus(data);
      if (notify) {
        toast[data.connection?.connected ? "success" : "error"](
          data.connection?.message || "Dolibarr-Prüfung abgeschlossen"
        );
      }
    } catch (error) {
      const message = formatApiError(error.response?.data?.detail || "Dolibarr-Status konnte nicht geladen werden.");
      setLoadError(message);
      if (notify) toast.error(message);
    } finally {
      setChecking(false);
    }
  };

  useEffect(() => { load(); }, []);

  if (!status) {
    return (
      <>
        <AdminHeader
          title="Dolibarr-Anbindung"
          desc="Website-Anfragen als Interessent und verknüpftes Ticket übergeben"
          action={loadError ? (
            <Button onClick={() => load(true)} disabled={checking} data-testid="dolibarr-retry-load">
              {checking ? <Loader2 className="animate-spin" size={16} /> : <RefreshCw size={16} />}
              Erneut versuchen
            </Button>
          ) : null}
        />
        {loadError ? (
          <Panel>
            <div className="flex items-start gap-3 text-amber-300">
              <XCircle size={20} className="mt-0.5 shrink-0" />
              <div>
                <h3 className="font-semibold">Dolibarr-Status nicht erreichbar</h3>
                <p className="mt-1 text-sm text-muted">{loadError}</p>
              </div>
            </div>
          </Panel>
        ) : <Skeleton className="h-64" />}
      </>
    );
  }

  const connection = status.connection || {};
  const inquiries = status.inquiries || {};
  const healthy = status.enabled && connection.connected;
  const latest = status.latest_activity;

  return (
    <>
      <AdminHeader
        title="Dolibarr-Anbindung"
        desc="Website-Anfragen als Interessent und verknüpftes Ticket übergeben"
        action={(
          <Button onClick={() => load(true)} disabled={checking} data-testid="dolibarr-check">
            {checking ? <Loader2 className="animate-spin" size={16} /> : <RefreshCw size={16} />}
            Verbindung prüfen
          </Button>
        )}
      />

      <div className="grid gap-4 md:grid-cols-3">
        <Panel>
          <div className="flex items-center gap-2">
            {healthy
              ? <CheckCircle2 size={18} className="text-emerald-400" />
              : <XCircle size={18} className="text-amber-400" />}
            <h3 className="font-semibold text-ink">Verbindung</h3>
          </div>
          <p className="mt-2 text-sm text-muted">
            {healthy ? "API und Leserechte erreichbar" : status.enabled ? "Prüfung fehlgeschlagen" : "Nicht aktiviert"}
          </p>
          <p className="mt-1 text-xs text-faint">{connection.message}</p>
          {connection.detail && (
            <p className="mt-2 break-words rounded-lg bg-red-500/10 p-2 font-mono text-xs text-red-300">
              {connection.detail}
            </p>
          )}
        </Panel>

        <Panel>
          <div className="flex items-center gap-2">
            <TicketCheck size={18} className="text-brand" />
            <h3 className="font-semibold text-ink">Übertragen</h3>
          </div>
          <p className="mt-2 text-3xl font-bold text-ink">{inquiries.synced ?? 0}</p>
          <p className="mt-1 text-xs text-faint">von {inquiries.total ?? 0} Website-Anfragen</p>
        </Panel>

        <Panel>
          <div className="flex items-center gap-2">
            <CircleAlert size={18} className={(inquiries.failed ?? 0) > 0 ? "text-amber-400" : "text-brand"} />
            <h3 className="font-semibold text-ink">Lokal/offen</h3>
          </div>
          <p className="mt-2 text-3xl font-bold text-ink">{inquiries.pending ?? 0}</p>
          <p className="mt-1 text-xs text-faint">davon {inquiries.failed ?? 0} mit Übertragungsfehler</p>
        </Panel>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Panel>
          <div className="flex items-center gap-2">
            <Users size={18} className="text-brand" />
            <h3 className="font-semibold text-ink">So funktioniert die Übergabe</h3>
          </div>
          <ol className="mt-4 space-y-3 text-sm text-muted">
            <li>1. Die Anfrage wird zuerst sicher lokal gespeichert.</li>
            <li>2. Ein vorhandener Interessent wird anhand der E-Mail wiederverwendet, sonst neu angelegt.</li>
            <li>3. Danach wird ein Ticket mit allen Angaben erstellt und mit dem Interessenten verknüpft.</li>
            <li>4. Fehlerhafte Übertragungen können unter „Anfragen“ erneut gestartet werden.</li>
          </ol>
        </Panel>

        <Panel>
          <h3 className="font-semibold text-ink">Benötigte Dolibarr-Rechte</h3>
          <p className="mt-3 text-sm leading-relaxed text-muted">
            Der API-Benutzer benötigt Lese- und Schreibrechte für Dritte/Firmen sowie Tickets.
            Das Ticket-Modul und die REST-API müssen in Dolibarr aktiviert sein. Produktrechte
            sind für diesen Anfrage-Ablauf nicht erforderlich.
          </p>
          <p className="mt-2 text-xs leading-relaxed text-faint">
            Die Verbindungsprüfung bestätigt Erreichbarkeit und Leserechte. Schreibrechte werden
            erst beim Übertragen einer Anfrage verwendet und dort mit einer konkreten Fehlermeldung geprüft.
          </p>
          {connection.checks && (
            <div className="mt-4 flex flex-wrap gap-2">
              <Badge tone={connection.checks.api ? "success" : "warning"}>API</Badge>
              <Badge tone={connection.checks.thirdparties ? "success" : "warning"}>Interessenten/Firmen lesen</Badge>
              <Badge tone={connection.checks.tickets ? "success" : "warning"}>Tickets lesen</Badge>
            </div>
          )}
          {!status.enabled && (
            <p className="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-300">
              Unter „Einstellungen“ Dolibarr aktivieren und Basis-URL sowie API-Key hinterlegen.
            </p>
          )}
        </Panel>
      </div>

      {latest && (
        <Panel className="mt-6">
          <h3 className="font-semibold text-ink">Letzte Übergabe</h3>
          <div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm">
            <div>
              <p className="font-mono text-ink">{latest.ref}</p>
              <p className="mt-1 text-xs text-faint">
                {latest.dolibarr?.synced
                  ? `Interessent ${latest.dolibarr.thirdparty_id} · Ticket ${latest.dolibarr.ticket_id}`
                  : latest.dolibarr?.error?.message || "Noch nicht übertragen"}
              </p>
            </div>
            <Button as={Link} to="/admin/anfragen" variant="outline" size="sm">Anfragen öffnen</Button>
          </div>
        </Panel>
      )}
    </>
  );
}

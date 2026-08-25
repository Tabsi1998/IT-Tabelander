import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Inbox, Mail, Layers, Star, RefreshCw, ArrowRight } from "lucide-react";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel } from "../../components/admin/AdminUI";
import { Badge } from "../../components/ui/badge";

const STAT_META = [
  { key: "new_repairs", label: "Neue Anfragen", icon: Inbox, to: "/admin/anfragen", tone: "brand" },
  { key: "contact_new", label: "Neue Nachrichten", icon: Mail, to: "/admin/kontakt" },
  { key: "active_services", label: "Aktive Leistungen", icon: Layers, to: "/admin/leistungen" },
  { key: "reviews_visible", label: "Sichtbare Reviews", icon: Star, to: "/admin/bewertungen" },
];

const STATUS_LABEL = {
  eingegangen: "Eingegangen", in_bearbeitung: "In Bearbeitung", wartet_auf_kunde: "Wartet auf Kunde",
  angebot_erstellt: "Angebot erstellt", beauftragt: "Beauftragt", abgeschlossen: "Abgeschlossen",
  abgelehnt: "Abgelehnt", in_diagnose: "In Diagnose", warten_auf_teile: "Warten auf Teile",
  in_reparatur: "In Reparatur", fertig: "Fertig",
};

const REQUEST_TYPE_LABEL = {
  repair: "Reparatur", pc_build: "PC-Neubau", pc_upgrade: "PC-/Notebook-Upgrade",
  controller_custom: "Controller-Umbau", consulting: "Beratung", other: "Sonstiges",
};

const DEVICE_TYPE_LABEL = {
  pc: "Desktop-PC", notebook: "Notebook", playstation: "PlayStation", xbox: "Xbox",
  switch: "Nintendo Switch", controller: "Controller", storage: "Datenträger", other: "Sonstiges",
};

export default function Dashboard() {
  const [data, setData] = useState(null);

  useEffect(() => {
    api.get("/admin/dashboard").then(({ data }) => setData(data)).catch(() => {});
  }, []);

  if (!data) {
    return (
      <>
        <AdminHeader title="Dashboard" desc="Überblick" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-28" />)}</div>
      </>
    );
  }

  return (
    <>
      <AdminHeader title="Dashboard" desc="Überblick über deine Website & Anfragen" />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {STAT_META.map((s) => (
          <Link key={s.key} to={s.to} className="group rounded-2xl border border-subtle bg-surface p-5 transition-colors hover:border-brand/40" data-testid={`stat-${s.key}`}>
            <div className="flex items-center justify-between">
              <span className={`flex h-10 w-10 items-center justify-center rounded-xl ${s.tone === "brand" ? "bg-brand/15 text-brand" : "bg-white/5 text-muted"}`}>
                <s.icon size={18} />
              </span>
              <ArrowRight size={16} className="text-faint transition-transform group-hover:translate-x-1 group-hover:text-brand" />
            </div>
            <p className="mt-4 text-3xl font-bold text-ink">{data[s.key] ?? 0}</p>
            <p className="text-sm text-muted">{s.label}</p>
          </Link>
        ))}
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Panel className="lg:col-span-2">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="font-heading font-semibold text-ink">Letzte Anfragen</h2>
            <Link to="/admin/anfragen" className="text-sm text-brand hover:underline">Alle</Link>
          </div>
          {data.recent_repairs.length === 0 ? (
            <p className="text-sm text-faint">Noch keine Anfragen.</p>
          ) : (
            <div className="divide-y divide-subtle">
              {data.recent_repairs.map((r) => (
                <div key={r.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">{r.ref} · {REQUEST_TYPE_LABEL[r.request_type] || r.request_type || "Anfrage"} {r.device_type && `– ${DEVICE_TYPE_LABEL[r.device_type] || r.device_type}`} {r.model && `· ${r.model}`}</p>
                    <p className="truncate text-xs text-faint">{r.contact?.name} · {r.contact?.email}</p>
                  </div>
                  <Badge tone={r.status === "eingegangen" ? "brand" : "neutral"}>{STATUS_LABEL[r.status] || r.status}</Badge>
                </div>
              ))}
            </div>
          )}
        </Panel>

        <Panel>
          <h2 className="mb-4 font-heading font-semibold text-ink">Dolibarr</h2>
          <div className="flex items-center gap-2">
            <RefreshCw size={16} className={data.dolibarr_enabled ? "text-emerald-400" : "text-faint"} />
            <span className="text-sm text-muted">{data.dolibarr_enabled ? "Aktiv" : "Nicht aktiviert"}</span>
          </div>
          <p className="mt-2 text-xs text-faint">
            {data.dolibarr_enabled ? "Neue Anfragen werden als Interessent und Ticket übertragen." : "Die Verbindung kann in den Einstellungen aktiviert werden."}
          </p>
          <Link to="/admin/dolibarr" className="mt-4 inline-flex items-center gap-1 text-sm text-brand hover:underline">Zur Dolibarr-Seite <ArrowRight size={14} /></Link>
        </Panel>
      </div>
    </>
  );
}

import React, { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  RefreshCw, CheckCircle2, XCircle, Loader2, Users, TicketCheck, CircleAlert, Clock, DatabaseZap, Search,
  BookOpen, Save,
} from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError } from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Badge } from "../../components/ui/badge";
import { Field } from "../../components/admin/AdminUI";
import { Select } from "../../components/ui/input";
import { useAuth } from "../../context/AuthContext";

const CONTENT_FIELDS = [
  ["dolibarr_content_category_id", "Kategorie der Website-Artikel"],
  ["dolibarr_imprint_article_id", "Ergänzung zum Impressum (optional)"],
  ["dolibarr_privacy_article_id", "Datenschutzerklärung"],
  ["dolibarr_terms_article_id", "Nutzungsbedingungen"],
];

const TYPE_LABELS = {
  repair: "Reparatur", pc_build: "PC-Neubau", pc_upgrade: "PC-/Notebook-Upgrade",
  controller_custom: "Controller-Umbau", consulting: "Beratung", other: "Sonstiges", contact: "Kontaktnachricht",
  faq: "FAQ",
};

function when(value) {
  if (!value) return "–";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "–" : date.toLocaleString("de-AT", { dateStyle: "short", timeStyle: "short" });
}

function QueuePanel() {
  const [queue, setQueue] = useState(null);
  const [running, setRunning] = useState(false);

  const load = useCallback(async () => {
    try {
      const { data } = await api.get("/admin/dolibarr/queue");
      setQueue(data);
    } catch (error) {
      setQueue({ error: formatApiError(error.response?.data?.detail || "Warteschlange konnte nicht geladen werden.") });
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  const run = async () => {
    setRunning(true);
    try {
      const { data } = await api.post("/admin/dolibarr/queue/run");
      if (!data.dolibarr_enabled) toast.error("Dolibarr ist nicht aktiviert.");
      else if (data.failed) toast.error(`${data.synced} übergeben, ${data.failed} weiterhin offen.`);
      else toast.success(data.tried ? `${data.synced} Anfrage(n) an Dolibarr übergeben.` : "Nichts zu tun.");
      await load();
    } catch (error) {
      toast.error(formatApiError(error.response?.data?.detail || "Erneuter Versuch fehlgeschlagen."));
    } finally { setRunning(false); }
  };

  if (!queue) return <Skeleton className="mt-6 h-40" />;
  const waiting = queue.waiting || 0;
  return (
    <Panel className="mt-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <Clock size={18} className={waiting ? "text-amber-400" : "text-emerald-400"} />
            <h3 className="font-semibold text-ink">Warteschlange</h3>
          </div>
          <p className="mt-1 text-sm text-muted">
            {queue.error || (waiting === 0
              ? "Alle Anfragen sind in Dolibarr angekommen."
              : `${waiting} ${waiting === 1 ? "Anfrage wartet" : "Anfragen warten"} auf Dolibarr. Die Website versucht es automatisch alle 5 bis 60 Minuten; nach 30 Minuten bekommst du einmal eine Warn-Mail.`)}
          </p>
        </div>
        <Button onClick={run} disabled={running || waiting === 0} variant="outline" data-testid="dolibarr-queue-run">
          {running ? <Loader2 className="animate-spin" size={16} /> : <RefreshCw size={16} />}
          Jetzt erneut versuchen
        </Button>
      </div>
      {queue.warning_mail?.last_error && (
        <p className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-300">
          Warn-Mail konnte nicht gesendet werden: {queue.warning_mail.last_error}{" "}
          <Link to="/admin/einstellungen" className="underline">E-Mail-Versand einrichten</Link>
        </p>
      )}
      {queue.items?.length > 0 && (
        <ul className="mt-4 divide-y divide-subtle text-sm">
          {queue.items.map((item) => (
            <li key={item.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between">
              <div className="min-w-0">
                <p className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-ink">{item.ref}</span>
                  <Badge tone="brand">{TYPE_LABELS[item.request_type] || "Anfrage"}</Badge>
                  {item.gave_up && <Badge tone="warning">Automatik angehalten</Badge>}
                  {item.warned_at && <Badge tone="neutral">Warnung gesendet</Badge>}
                </p>
                <p className="mt-1 break-words text-xs text-amber-300">{item.reason}</p>
              </div>
              <p className="shrink-0 text-xs text-faint sm:text-right">
                seit {when(item.created_at)} · {item.attempts} Versuch(e)
                {!item.gave_up && <span className="block">nächster: {when(item.next_attempt_at)}</span>}
              </p>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

function ContentPanel() {
  const [overview, setOverview] = useState(null);
  const [choice, setChoice] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setBusy(true);
    try {
      const [{ data }, { data: settings }] = await Promise.all([
        api.get("/admin/dolibarr/content"), api.get("/admin/settings"),
      ]);
      setOverview(data);
      setChoice(Object.fromEntries(CONTENT_FIELDS.map(([key]) => [key, settings[key] || 0])));
    } catch (error) {
      setOverview({ errors: { load: formatApiError(error.response?.data?.detail || "Inhalte aus Dolibarr konnten nicht geladen werden.") } });
    } finally { setBusy(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  const save = async () => {
    setBusy(true);
    try {
      await api.put("/admin/settings", choice);
      toast.success("Auswahl gespeichert");
      await load();
    } catch (error) {
      toast.error(formatApiError(error.response?.data?.detail || "Auswahl konnte nicht gespeichert werden."));
      setBusy(false);
    }
  };

  if (!overview) return <Skeleton className="mt-6 h-48" />;
  const company = overview.company || {};
  const errors = Object.values(overview.errors || {});
  const articles = overview.articles || [];
  return (
    <Panel className="mt-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-center gap-2">
          <BookOpen size={18} className="text-brand" />
          <h3 className="font-semibold text-ink">Inhalte aus Dolibarr</h3>
        </div>
        <Button variant="outline" size="sm" onClick={load} disabled={busy}>
          {busy ? <Loader2 className="animate-spin" size={14} /> : <RefreshCw size={14} />} Neu laden
        </Button>
      </div>
      <p className="mt-2 text-sm text-muted">
        Firmendaten, Öffnungszeiten und Social Media kommen aus Dolibarr (Start → Einstellungen → Firma/Organisation).
        Rechtstexte und FAQ sind Artikel der Wissensdatenbank; öffentlich ist nur, was die unten gewählte Kategorie hat und freigegeben ist.
      </p>
      {errors.length > 0 && (
        <ul className="mt-3 space-y-1 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-300">
          {errors.map((message) => <li key={message}>{message}</li>)}
        </ul>
      )}
      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <div className="text-sm">
          <p className="font-medium text-ink">{company.name || "Keine Firmendaten"}</p>
          <p className="text-muted">{[company.address, [company.zip, company.town].filter(Boolean).join(" ")].filter(Boolean).join(", ") || "–"}</p>
          {company.managers && <p className="text-muted">Inhaber/Geschäftsführung: {company.managers}</p>}
          {company.tva_intra && <p className="text-muted">UID: {company.tva_intra}</p>}
          <p className="mt-2 text-xs text-faint">
            Öffnungszeiten: {(overview.opening_hours || []).map((item) => `${item.day} ${item.hours}`).join(" · ") || "keine eingetragen"}
          </p>
          <p className="mt-1 text-xs text-faint">{overview.faq_count || 0} FAQ freigegeben</p>
        </div>
        {choice && (
          <div className="space-y-3">
            {CONTENT_FIELDS.map(([key, label]) => (
              <Field key={key} label={label}>
                <Select value={choice[key] || 0} onChange={(event) => setChoice((current) => ({ ...current, [key]: Number(event.target.value) }))}>
                  <option value={0}>{key === "dolibarr_content_category_id" ? "– keine Kategorie –" : "– kein Artikel –"}</option>
                  {key === "dolibarr_content_category_id"
                    ? (overview.categories || []).map((category) => <option key={category.id} value={category.id}>{category.label}</option>)
                    : articles.map((article) => <option key={article.id} value={article.id}>{article.question}{article.status === 0 ? " (Entwurf)" : ""}</option>)}
                </Select>
              </Field>
            ))}
            <Button onClick={save} disabled={busy} data-testid="dolibarr-content-save">
              {busy ? <Loader2 className="animate-spin" size={16} /> : <Save size={16} />} Auswahl speichern
            </Button>
            <p className="text-xs text-faint">Die Artikel-Liste zeigt die Artikel der gewählten Kategorie; nach einem Kategorie-Wechsel erst speichern.</p>
          </div>
        )}
      </div>
    </Panel>
  );
}

function MigrationPanel({ isSuperAdmin }) {
  const [plan, setPlan] = useState(null);
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);

  const dryRun = async () => {
    setBusy(true);
    try {
      const { data } = await api.get("/admin/dolibarr/migration");
      setPlan(data);
    } catch (error) {
      toast.error(formatApiError(error.response?.data?.detail || "Probelauf fehlgeschlagen."));
    } finally { setBusy(false); }
  };

  const apply = async () => {
    if (!window.confirm("Alte Anfragen und Nachrichten jetzt an Dolibarr übergeben? Kunden bekommen dabei keine Mail. Danach stehen die Daten nur noch in Dolibarr.")) return;
    setBusy(true);
    try {
      const { data } = await api.post("/admin/dolibarr/migration");
      setResult(data);
      toast[data.failed?.length ? "error" : "success"](`${data.sent + data.finished + data.contact_messages + (data.faqs || 0)} erledigt, ${data.remaining} übrig.`);
      const { data: next } = await api.get("/admin/dolibarr/migration");
      setPlan(next);
    } catch (error) {
      toast.error(formatApiError(error.response?.data?.detail || "Übergabe fehlgeschlagen."));
    } finally { setBusy(false); }
  };

  const total = plan ? plan.inquiries_to_send + plan.inquiries_to_finish + plan.contact_messages + (plan.faqs || 0) : 0;
  return (
    <Panel className="mt-6">
      <div className="flex items-center gap-2">
        <DatabaseZap size={18} className="text-brand" />
        <h3 className="font-semibold text-ink">Altdaten umziehen (einmalig)</h3>
      </div>
      <p className="mt-2 text-sm text-muted">
        Anfragen und Kontaktnachrichten von vor dieser Version liegen noch auf der Website. Der Umzug übergibt sie an
        Dolibarr – ohne Mail an die Kunden – und löscht sie danach hier. Die bisherigen FAQ kommen als Entwurf in die
        Wissensdatenbank: dort durchsehen, Kategorie setzen, freigeben. Der Probelauf zeigt vorher, was passieren würde, und ändert nichts.
      </p>
      <div className="mt-4 flex flex-wrap gap-2">
        <Button variant="outline" onClick={dryRun} disabled={busy} data-testid="dolibarr-migration-dry-run">
          {busy ? <Loader2 className="animate-spin" size={16} /> : <Search size={16} />} Probelauf
        </Button>
        {plan && total > 0 && isSuperAdmin && (
          <Button onClick={apply} disabled={busy} data-testid="dolibarr-migration-run">
            {busy ? <Loader2 className="animate-spin" size={16} /> : <TicketCheck size={16} />} Jetzt übergeben
          </Button>
        )}
      </div>
      {plan && total > 0 && !isSuperAdmin && <p className="mt-3 text-xs text-amber-300">Übergeben kann nur der Super-Admin.</p>}
      {plan && (
        <div className="mt-4 text-sm">
          {total === 0 ? <p className="text-emerald-400">Keine Altdaten mehr – der Umzug ist erledigt.</p> : (
            <>
              <p className="text-muted">
                {plan.inquiries_to_send} Anfrage(n) neu an Dolibarr · {plan.inquiries_to_finish} schon übergeben, Fotos und Daten noch hier · {plan.contact_messages} alte Kontaktnachricht(en) · {plan.faqs || 0} FAQ
              </p>
              <ul className="mt-3 max-h-72 divide-y divide-subtle overflow-y-auto">
                {plan.items.map((item, index) => (
                  <li key={`${item.ref}-${index}`} className="py-2">
                    <span className="font-mono text-ink">{item.ref}</span>
                    <span className="ml-2 text-xs text-faint">{TYPE_LABELS[item.request_type] || "Anfrage"} · {when(item.created_at)}{item.photos ? ` · ${item.photos} Foto(s)` : ""}</span>
                    <span className="block text-xs text-muted">{item.what}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}
      {result && (
        <div className="mt-4 rounded-lg border border-subtle p-3 text-xs text-muted">
          Übergeben: {result.sent} · abgeschlossen: {result.finished} · Kontaktnachrichten: {result.contact_messages} · FAQ kopiert: {result.faqs || 0} · noch übrig: {result.remaining}
          {result.failed?.map((item) => <p key={item.ref} className="mt-1 text-amber-300">{item.ref}: {item.reason}</p>)}
          {result.remaining > 0 && <p className="mt-1">Noch einmal „Jetzt übergeben“ klicken für den nächsten Schwung.</p>}
        </div>
      )}
    </Panel>
  );
}

export default function AdminDolibarr() {
  const { user } = useAuth();
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
            <li>1. Die Anfrage wird zuerst sicher auf der Website gespeichert.</li>
            <li>2. Ein vorhandener Kunde wird anhand der E-Mail verknüpft und nie verändert; sonst entsteht ein neuer Interessent. Kontaktnachrichten legen keinen neuen Interessenten an.</li>
            <li>3. Ein Ticket mit allen Angaben entsteht; Fotos hängen am Ticket, ein Rückruf-Wunsch steht als Termin im Kalender. Dolibarr schickt dem Kunden die Bestätigung.</li>
            <li>4. Danach löscht die Website ihre Kopie: Kundendaten stehen nur noch in Dolibarr.</li>
            <li>5. Klappt die Übergabe nicht, versucht es die Warteschlange unten automatisch weiter.</li>
          </ol>
        </Panel>

        <Panel>
          <h3 className="font-semibold text-ink">Benötigte Dolibarr-Rechte</h3>
          <p className="mt-3 text-sm leading-relaxed text-muted">
            Der API-Benutzer braucht: Geschäftspartner einsehen, anlegen und <strong>alle einsehen</strong>
            (sonst werden Stammkunden doppelt angelegt), Tickets lesen und anlegen/ändern sowie im Kalender
            eigene Termine einsehen und anlegen. Module: Geschäftspartner, Tickets, Kalender und REST-API.
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

      <ContentPanel />
      <QueuePanel />
      <MigrationPanel isSuperAdmin={user?.role === "super_admin"} />

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

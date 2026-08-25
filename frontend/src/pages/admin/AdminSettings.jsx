import React, { useEffect, useState } from "react";
import { Save, Loader2 } from "lucide-react";
import { toast } from "sonner";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Field } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Input, Textarea } from "../../components/ui/input";
import { useAuth } from "../../context/AuthContext";

const WEEKDAYS = ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag", "Sonntag"];
const DOLIBARR_CATEGORIES = [
  ["repair", "Reparatur"], ["pc_build", "PC-Neubau"], ["pc_upgrade", "PC-Upgrade"],
  ["controller_custom", "Controller-Umbau"], ["consulting", "Beratung"], ["other", "Sonstiges"],
];

export default function AdminSettings() {
  const { user, refresh } = useAuth();
  const canManageDolibarrCredentials = user?.role === "super_admin";
  const [s, setS] = useState(null);
  const [saving, setSaving] = useState(false);
  const [savingAccount, setSavingAccount] = useState(false);
  const [account, setAccount] = useState({ email: "", current_password: "", new_password: "", confirm_password: "" });

  useEffect(() => {
    api.get("/admin/settings").then(({ data }) => setS({
      ...data,
      social_links: data.social_links || {},
      dolibarr_ticket_categories: data.dolibarr_ticket_categories || {},
      opening_hours: WEEKDAYS.map((day) => data.opening_hours?.find((item) => item.day === day) || { day, hours: "" }),
    })).catch(() => setS({ opening_hours: WEEKDAYS.map((day) => ({ day, hours: "" })), social_links: {}, dolibarr_ticket_categories: {} }));
  }, []);

  useEffect(() => {
    if (user?.email) setAccount((x) => ({ ...x, email: user.email }));
  }, [user]);

  const set = (k) => (e) => setS((x) => ({ ...x, [k]: e.target.value }));
  const setSocial = (k) => (e) => setS((x) => ({ ...x, social_links: { ...x.social_links, [k]: e.target.value } }));
  const setHours = (day) => (e) => setS((x) => ({ ...x, opening_hours: x.opening_hours.map((item) => item.day === day ? { ...item, hours: e.target.value } : item) }));

  const save = async () => {
    setSaving(true);
    try {
      const payload = { ...s, opening_hours: s.opening_hours.filter((item) => item.hours.trim()) };
      const { data } = await api.put("/admin/settings", payload);
      setS({
        ...data,
        social_links: data.social_links || {},
        dolibarr_ticket_categories: data.dolibarr_ticket_categories || {},
        opening_hours: WEEKDAYS.map((day) => data.opening_hours?.find((item) => item.day === day) || { day, hours: "" }),
      });
      toast.success("Einstellungen gespeichert");
    } catch { toast.error("Fehler beim Speichern"); } finally { setSaving(false); }
  };

  const saveAccount = async () => {
    if (account.new_password && account.new_password !== account.confirm_password) {
      toast.error("Die neuen Passwörter stimmen nicht überein");
      return;
    }
    setSavingAccount(true);
    try {
      await api.put("/auth/account", {
        current_password: account.current_password,
        email: account.email || undefined,
        new_password: account.new_password || undefined,
      });
      await refresh();
      setAccount((x) => ({ ...x, current_password: "", new_password: "", confirm_password: "" }));
      toast.success("Admin-Zugang aktualisiert");
    } catch (error) {
      toast.error(error.response?.data?.detail || "Admin-Zugang konnte nicht geändert werden");
    } finally { setSavingAccount(false); }
  };

  if (!s) return (<><AdminHeader title="Einstellungen" /><Skeleton className="h-96" /></>);

  return (
    <>
      <AdminHeader title="Einstellungen" desc="Unternehmensdaten, SEO, Analytics & rechtliche Texte"
        action={<Button onClick={save} disabled={saving} data-testid="settings-save">{saving ? <Loader2 className="animate-spin" size={16} /> : <Save size={16} />} Speichern</Button>} />

      <div className="grid gap-6 lg:grid-cols-2">
        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Unternehmen & Kontakt</h3>
          <div className="space-y-3">
            <Field label="Unternehmensname"><Input value={s.company_name || ""} onChange={set("company_name")} data-testid="settings-company" /></Field>
            <Field label="Tagline"><Input value={s.tagline || ""} onChange={set("tagline")} /></Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="E-Mail"><Input value={s.email || ""} onChange={set("email")} data-testid="settings-email" /></Field>
              <Field label="Telefon"><Input value={s.phone || ""} onChange={set("phone")} /></Field>
            </div>
            <Field label="Adresse"><Input value={s.address || ""} onChange={set("address")} /></Field>
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="PLZ"><Input value={s.postal_code || ""} onChange={set("postal_code")} /></Field>
              <Field label="Ort"><Input value={s.city || ""} onChange={set("city")} /></Field>
              <Field label="Region"><Input value={s.region || ""} onChange={set("region")} /></Field>
            </div>
            <Field label="Land"><Input value={s.country || ""} onChange={set("country")} /></Field>
            <Field label="Servicegebiet"><Input value={s.service_area || ""} onChange={set("service_area")} /></Field>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">SEO & Analytics</h3>
          <div className="space-y-3">
            <Field label="SEO Standard-Titel"><Input value={s.seo_default_title || ""} onChange={set("seo_default_title")} /></Field>
            <Field label="SEO Standard-Beschreibung"><Textarea value={s.seo_default_description || ""} onChange={set("seo_default_description")} /></Field>
            <Field label="Google Analytics 4 Measurement ID"><Input value={s.ga_measurement_id || ""} onChange={set("ga_measurement_id")} placeholder="G-XXXXXXX" data-testid="settings-ga" /></Field>
            <Field label="Öffentliche Website-URL"><Input value={s.canonical_base_url || ""} onChange={set("canonical_base_url")} placeholder="https://it.tabelander.co.at" /></Field>
            <Field label="Google Place ID (für Reviews)"><Input value={s.google_place_id || ""} onChange={set("google_place_id")} placeholder="ChIJ..." /></Field>
            <p className="text-xs text-faint">Die öffentliche URL wird für Sitemap und robots.txt verwendet.</p>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Integrationen</h3>
          <p className="mb-3 text-xs text-faint">API-Keys sind reine Schreibfelder. Gespeicherte Werte werden niemals wieder an den Browser ausgegeben.</p>
          <div className="space-y-3">
            <label className="flex items-center gap-2 text-sm text-muted"><input type="checkbox" checked={!!s.dolibarr_enabled} onChange={(e) => setS((x) => ({ ...x, dolibarr_enabled: e.target.checked }))} className="h-4 w-4 accent-[#F26522]" data-testid="settings-dolibarr-enabled" /> Dolibarr aktivieren</label>
            <Field label="Dolibarr Basis-URL"><Input value={s.dolibarr_base_url || ""} onChange={set("dolibarr_base_url")} placeholder="https://erp.tabelander.co.at" disabled={!canManageDolibarrCredentials} /></Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Timeout in Sekunden"><Input type="number" min="1" max="60" value={s.dolibarr_timeout_seconds || 8} onChange={(e) => setS((x) => ({ ...x, dolibarr_timeout_seconds: Number(e.target.value) }))} /></Field>
              <Field label="Ländercode"><Input maxLength={2} value={s.dolibarr_country_code || "AT"} onChange={(e) => setS((x) => ({ ...x, dolibarr_country_code: e.target.value.toUpperCase() }))} /></Field>
            </div>
            <label className="flex items-start gap-2 text-sm text-muted"><input type="checkbox" checked={!!s.dolibarr_public_ticket_enabled} onChange={(e) => setS((x) => ({ ...x, dolibarr_public_ticket_enabled: e.target.checked }))} className="mt-0.5 h-4 w-4 accent-[#F26522]" /><span>Öffentlichen Dolibarr-Ticketlink nach dem Absenden anzeigen<span className="mt-1 block text-xs text-faint">Nur aktivieren, wenn in Dolibarr unter Ticket → Einstellungen → Öffentliches Interface ebenfalls aktiviert.</span></span></label>
            <div className="rounded-xl border border-subtle p-3">
              <p className="mb-3 text-sm font-medium text-ink">Dolibarr-Themengruppen <span className="font-normal text-faint">(optional)</span></p>
              <div className="grid gap-3 sm:grid-cols-2">
                {DOLIBARR_CATEGORIES.map(([key, label]) => <Field key={key} label={label}><Input value={s.dolibarr_ticket_categories?.[key] || ""} onChange={(e) => setS((x) => ({ ...x, dolibarr_ticket_categories: { ...(x.dolibarr_ticket_categories || {}), [key]: e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, "") } }))} placeholder="z. B. REPARATUR" maxLength={32} /></Field>)}
              </div>
              <p className="mt-3 text-xs text-faint">Leer lassen, wenn nur „Sonstige“ vorhanden ist. Sobald eigene Themengruppen in Dolibarr angelegt sind, hier deren Codes eintragen.</p>
            </div>
            <Field label={`Dolibarr API-Key (${s.clear_dolibarr_api_key ? "wird entfernt" : s.dolibarr_api_key_configured ? "gespeichert" : "nicht gesetzt"})`}><Input type="password" value={s.dolibarr_api_key || ""} onChange={(e) => setS((x) => ({ ...x, dolibarr_api_key: e.target.value, clear_dolibarr_api_key: false }))} placeholder={s.dolibarr_api_key_configured ? "Neuen Key eingeben, um ihn zu ersetzen" : "DOLAPIKEY"} autoComplete="new-password" data-testid="settings-dolibarr-key" disabled={!canManageDolibarrCredentials} /></Field>
            {!canManageDolibarrCredentials && <p className="text-xs text-amber-300">Dolibarr-URL und API-Key können nur vom Super-Admin geändert werden.</p>}
            {canManageDolibarrCredentials && s.dolibarr_api_key_configured && <Button type="button" variant="outline" onClick={() => setS((x) => ({ ...x, dolibarr_api_key: "", clear_dolibarr_api_key: true }))}>Dolibarr-Key entfernen</Button>}
            <Field label={`Google Places API-Key (${s.clear_google_places_api_key ? "wird entfernt" : s.google_places_api_key_configured ? "gespeichert" : "nicht gesetzt"})`}><Input type="password" value={s.google_places_api_key || ""} onChange={(e) => setS((x) => ({ ...x, google_places_api_key: e.target.value, clear_google_places_api_key: false }))} placeholder={s.google_places_api_key_configured ? "Neuen Key eingeben, um ihn zu ersetzen" : "API-Key"} autoComplete="new-password" /></Field>
            {s.google_places_api_key_configured && <Button type="button" variant="outline" onClick={() => setS((x) => ({ ...x, google_places_api_key: "", clear_google_places_api_key: true }))}>Google-Key entfernen</Button>}
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Öffnungszeiten</h3>
          <div className="space-y-2">
            {s.opening_hours.map((item) => (
              <Field key={item.day} label={item.day}><Input value={item.hours || ""} onChange={setHours(item.day)} placeholder="z. B. 09:00–17:00 oder nach Vereinbarung" /></Field>
            ))}
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Admin-Zugang</h3>
          <p className="mb-3 text-xs text-faint">Hier änderst du die automatisch angelegten Start-Zugangsdaten. Das aktuelle Passwort ist zur Bestätigung erforderlich.</p>
          <div className="space-y-3">
            <Field label="Login-E-Mail"><Input type="email" value={account.email} onChange={(e) => setAccount((x) => ({ ...x, email: e.target.value }))} autoComplete="email" /></Field>
            <Field label="Aktuelles Passwort"><Input type="password" value={account.current_password} onChange={(e) => setAccount((x) => ({ ...x, current_password: e.target.value }))} autoComplete="current-password" /></Field>
            <Field label="Neues Passwort (optional, mindestens 12 Zeichen)"><Input type="password" value={account.new_password} onChange={(e) => setAccount((x) => ({ ...x, new_password: e.target.value }))} autoComplete="new-password" /></Field>
            <Field label="Neues Passwort wiederholen"><Input type="password" value={account.confirm_password} onChange={(e) => setAccount((x) => ({ ...x, confirm_password: e.target.value }))} autoComplete="new-password" /></Field>
            <Button onClick={saveAccount} disabled={savingAccount || !account.current_password}>{savingAccount ? <Loader2 className="animate-spin" size={16} /> : <Save size={16} />} Zugang speichern</Button>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Logos (Light / Dark)</h3>
          <p className="mb-3 text-xs text-faint">URL eines im Medienmanager hochgeladenen Logos eintragen. Leer = mitgeliefertes Logo.</p>
          <div className="space-y-3">
            <Field label="Logo für Light Mode (dunkles Logo)"><Input value={s.logo_light_url || ""} onChange={set("logo_light_url")} placeholder="/api/media/…" /></Field>
            <Field label="Logo für Dark Mode (helles Logo)"><Input value={s.logo_dark_url || ""} onChange={set("logo_dark_url")} placeholder="/api/media/…" /></Field>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Social Media</h3>
          <div className="space-y-3">
            <Field label="Instagram"><Input value={s.social_links?.instagram || ""} onChange={setSocial("instagram")} /></Field>
            <Field label="Facebook"><Input value={s.social_links?.facebook || ""} onChange={setSocial("facebook")} /></Field>
            <Field label="YouTube"><Input value={s.social_links?.youtube || ""} onChange={setSocial("youtube")} /></Field>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Rechtliche Texte</h3>
          <p className="mb-3 text-xs text-amber-300">Diese Texte sind vom Betreiber rechtlich zu prüfen.</p>
          <div className="space-y-3">
            <Field label="Impressum (HTML)"><Textarea value={s.impressum_html || ""} onChange={set("impressum_html")} className="min-h-[120px]" /></Field>
            <Field label="Datenschutz (HTML)"><Textarea value={s.datenschutz_html || ""} onChange={set("datenschutz_html")} className="min-h-[120px]" /></Field>
            <label className="flex items-center gap-2 text-sm text-muted"><input type="checkbox" checked={!!s.legal_reviewed} onChange={(e) => setS((x) => ({ ...x, legal_reviewed: e.target.checked }))} className="h-4 w-4 accent-[#F26522]" /> Rechtliche Texte wurden geprüft</label>
          </div>
        </Panel>
      </div>
    </>
  );
}

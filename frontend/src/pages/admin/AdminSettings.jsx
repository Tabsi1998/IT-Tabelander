import React, { useEffect, useState } from "react";
import { Save, Loader2, Send } from "lucide-react";
import { toast } from "sonner";
import api, { formatApiError } from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Field } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Input, Select, Textarea } from "../../components/ui/input";
import { useAuth } from "../../context/AuthContext";

const WEEKDAYS = ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag", "Sonntag"];
const DOLIBARR_CATEGORIES = [
  ["repair", "Reparatur"], ["pc_build", "PC-Neubau"], ["pc_upgrade", "PC-Upgrade"],
  ["controller_custom", "Controller-Umbau"], ["consulting", "Beratung"], ["other", "Sonstiges"],
  ["contact", "Kontaktnachricht"],
];

export default function AdminSettings() {
  const { user, refresh } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const [s, setS] = useState(null);
  const [saving, setSaving] = useState(false);
  const [savingAccount, setSavingAccount] = useState(false);
  const [account, setAccount] = useState({ email: "", current_password: "", new_password: "", confirm_password: "" });
  const [testTo, setTestTo] = useState("");
  const [testing, setTesting] = useState(false);

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

  const sendTestMail = async () => {
    setTesting(true);
    try {
      const { data } = await api.post("/admin/settings/test-mail", testTo ? { to: testTo } : {});
      toast.success(data.message || "Test-Mail gesendet");
    } catch (error) {
      toast.error(formatApiError(error.response?.data?.detail || "Test-Mail konnte nicht gesendet werden"));
    } finally { setTesting(false); }
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
            <p className="text-xs text-faint">Die öffentliche URL wird für Sitemap und robots.txt verwendet.</p>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Integrationen</h3>
          <p className="mb-3 text-xs text-faint">API-Keys sind reine Schreibfelder. Gespeicherte Werte werden niemals wieder an den Browser ausgegeben.</p>
          <div className="space-y-3">
            <label className="flex items-center gap-2 text-sm text-muted"><input type="checkbox" checked={!!s.dolibarr_enabled} onChange={(e) => setS((x) => ({ ...x, dolibarr_enabled: e.target.checked }))} className="h-4 w-4 accent-[#F26522]" data-testid="settings-dolibarr-enabled" /> Dolibarr aktivieren</label>
            <Field label="Dolibarr Basis-URL"><Input value={s.dolibarr_base_url || ""} onChange={set("dolibarr_base_url")} placeholder="https://erp.tabelander.co.at" disabled={!isSuperAdmin} /></Field>
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
            <Field label={`Dolibarr API-Key (${s.clear_dolibarr_api_key ? "wird entfernt" : s.dolibarr_api_key_configured ? "gespeichert" : "nicht gesetzt"})`}><Input type="password" value={s.dolibarr_api_key || ""} onChange={(e) => setS((x) => ({ ...x, dolibarr_api_key: e.target.value, clear_dolibarr_api_key: false }))} placeholder={s.dolibarr_api_key_configured ? "Neuen Key eingeben, um ihn zu ersetzen" : "DOLAPIKEY"} autoComplete="new-password" data-testid="settings-dolibarr-key" disabled={!isSuperAdmin} /></Field>
            {!isSuperAdmin && <p className="text-xs text-amber-300">Dolibarr-URL und API-Key können nur vom Super-Admin geändert werden.</p>}
            {isSuperAdmin && s.dolibarr_api_key_configured && <Button type="button" variant="outline" onClick={() => setS((x) => ({ ...x, dolibarr_api_key: "", clear_dolibarr_api_key: true }))}>Dolibarr-Key entfernen</Button>}
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-1 font-semibold text-ink">E-Mail-Versand der Website</h3>
          <p className="mb-3 text-xs text-faint">Die Website schickt dir Warnungen, wenn Anfragen nicht in Dolibarr ankommen. Bestätigungen an Kunden verschickt Dolibarr selbst. Das Passwort ist ein reines Schreibfeld.</p>
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-[1fr_7rem]">
              <Field label="Mailserver (SMTP)"><Input value={s.smtp_host || ""} onChange={set("smtp_host")} placeholder="z. B. smtp.dein-anbieter.at" disabled={!isSuperAdmin} data-testid="settings-smtp-host" /></Field>
              <Field label="Port"><Input type="number" min="1" max="65535" value={s.smtp_port || ""} onChange={(e) => setS((x) => ({ ...x, smtp_port: e.target.value ? Number(e.target.value) : undefined }))} placeholder="587" disabled={!isSuperAdmin} /></Field>
            </div>
            <Field label="Verschlüsselung"><Select value={s.smtp_security || "starttls"} onChange={set("smtp_security")} disabled={!isSuperAdmin}>
              <option value="starttls">STARTTLS (meist Port 587)</option>
              <option value="ssl">SSL/TLS (meist Port 465)</option>
              <option value="none">Keine (nur im eigenen Netz)</option>
            </Select></Field>
            <Field label="Benutzername"><Input value={s.smtp_username || ""} onChange={set("smtp_username")} autoComplete="off" disabled={!isSuperAdmin} /></Field>
            <Field label={`Passwort (${s.clear_smtp_password ? "wird entfernt" : s.smtp_password_configured ? "gespeichert" : "nicht gesetzt"})`}><Input type="password" value={s.smtp_password || ""} onChange={(e) => setS((x) => ({ ...x, smtp_password: e.target.value, clear_smtp_password: false }))} placeholder={s.smtp_password_configured ? "Neues Passwort eingeben, um es zu ersetzen" : ""} autoComplete="new-password" disabled={!isSuperAdmin} /></Field>
            {isSuperAdmin && s.smtp_password_configured && <Button type="button" variant="outline" onClick={() => setS((x) => ({ ...x, smtp_password: "", clear_smtp_password: true }))}>Mail-Passwort entfernen</Button>}
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Absender-Adresse"><Input type="email" value={s.smtp_from || ""} onChange={set("smtp_from")} placeholder="office@tabelander.co.at" /></Field>
              <Field label="Absender-Name"><Input value={s.smtp_from_name || ""} onChange={set("smtp_from_name")} placeholder="IT-Tabelander" /></Field>
            </div>
            <Field label="Warnungen gehen an"><Input type="email" value={s.warning_email || ""} onChange={set("warning_email")} placeholder="leer = E-Mail der Super-Admins" /></Field>
            {!isSuperAdmin && <p className="text-xs text-amber-300">Mailserver, Benutzername und Passwort kann nur der Super-Admin ändern.</p>}
            <div className="rounded-xl border border-subtle p-3">
              <p className="mb-2 text-sm font-medium text-ink">Test-Mail</p>
              <div className="flex flex-col gap-2 sm:flex-row">
                <Input type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder={`Empfänger (leer = ${user?.email || "deine Login-E-Mail"})`} />
                <Button type="button" variant="outline" onClick={sendTestMail} disabled={testing} data-testid="settings-test-mail">{testing ? <Loader2 className="animate-spin" size={16} /> : <Send size={16} />} Senden</Button>
              </div>
              <p className="mt-2 text-xs text-faint">Nutzt die gespeicherten Werte: erst oben „Speichern“, dann testen.</p>
            </div>
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

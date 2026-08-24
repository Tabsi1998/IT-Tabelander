import React, { useEffect, useState } from "react";
import { Save, Loader2 } from "lucide-react";
import { toast } from "sonner";
import api from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Panel, Field } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";
import { Input, Textarea } from "../../components/ui/input";

export default function AdminSettings() {
  const [s, setS] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get("/admin/settings").then(({ data }) => setS({ opening_hours: [], social_links: {}, ...data })).catch(() => setS({}));
  }, []);

  const set = (k) => (e) => setS((x) => ({ ...x, [k]: e.target.value }));
  const setSocial = (k) => (e) => setS((x) => ({ ...x, social_links: { ...x.social_links, [k]: e.target.value } }));

  const save = async () => {
    setSaving(true);
    try {
      await api.put("/admin/settings", s);
      toast.success("Einstellungen gespeichert");
    } catch { toast.error("Fehler beim Speichern"); } finally { setSaving(false); }
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
            <Field label="Servicegebiet"><Input value={s.service_area || ""} onChange={set("service_area")} /></Field>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">SEO & Analytics</h3>
          <div className="space-y-3">
            <Field label="SEO Standard-Titel"><Input value={s.seo_default_title || ""} onChange={set("seo_default_title")} /></Field>
            <Field label="SEO Standard-Beschreibung"><Textarea value={s.seo_default_description || ""} onChange={set("seo_default_description")} /></Field>
            <Field label="Google Analytics 4 Measurement ID"><Input value={s.ga_measurement_id || ""} onChange={set("ga_measurement_id")} placeholder="G-XXXXXXX" data-testid="settings-ga" /></Field>
            <Field label="Google Place ID (für Reviews)"><Input value={s.google_place_id || ""} onChange={set("google_place_id")} placeholder="ChIJ..." /></Field>
            <p className="text-xs text-faint">Der Google Places API-Key wird ausschließlich serverseitig (Env) gesetzt und nie im Browser gespeichert.</p>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">PC Builder – Inhalte</h3>
          <p className="mb-3 text-xs text-faint">Überschrift, Untertitel und Hinweis der PC-Builder-Seite. Kategorien &amp; Komponenten verwaltest du unter „PC Builder“.</p>
          <div className="space-y-3">
            <Field label="Titel"><Input value={s.pc_builder_title || ""} onChange={set("pc_builder_title")} placeholder="PC Builder" data-testid="settings-pcb-title" /></Field>
            <Field label="Untertitel"><Textarea value={s.pc_builder_subtitle || ""} onChange={set("pc_builder_subtitle")} data-testid="settings-pcb-subtitle" /></Field>
            <Field label="Hinweis-Box (optional)"><Textarea value={s.pc_builder_note || ""} onChange={set("pc_builder_note")} placeholder="z. B. Lieferzeiten, Beratung, Aktionen …" data-testid="settings-pcb-note" /></Field>
          </div>
        </Panel>

        <Panel>
          <h3 className="mb-4 font-semibold text-ink">Integrationen (serverseitig, nie im Browser)</h3>
          <div className="space-y-3">
            <label className="flex items-center gap-2 text-sm text-muted"><input type="checkbox" checked={!!s.dolibarr_enabled} onChange={(e) => setS((x) => ({ ...x, dolibarr_enabled: e.target.checked }))} className="h-4 w-4 accent-[#F26522]" data-testid="settings-dolibarr-enabled" /> Dolibarr aktivieren</label>
            <Field label="Dolibarr Basis-URL"><Input value={s.dolibarr_base_url || ""} onChange={set("dolibarr_base_url")} placeholder="https://erp.tabelander.co.at" /></Field>
            <Field label="Dolibarr API-Key (DOLAPIKEY)"><Input type="password" value={s.dolibarr_api_key || ""} onChange={set("dolibarr_api_key")} placeholder="••••••" data-testid="settings-dolibarr-key" /></Field>
            <Field label="Google Places API-Key"><Input type="password" value={s.google_places_api_key || ""} onChange={set("google_places_api_key")} placeholder="••••••" /></Field>
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
          </div>
        </Panel>
      </div>
    </>
  );
}

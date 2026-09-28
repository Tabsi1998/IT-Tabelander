import { useState } from "react";
import { Field } from "../../forms/fields.jsx";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { useSession } from "../session.jsx";
import { LoadState, Notice, PageHeader, Section, Toggle } from "../ui.jsx";

const TYPES = [["repair", "Reparatur"], ["pc_build", "PC-Neubau"], ["pc_upgrade", "Upgrade"], ["controller_custom", "Controller-Umbau"],
  ["consulting", "Beratung"], ["other", "Sonstiges"], ["contact", "Kontaktnachricht"]];
const CONTENT = [["dolibarr_content_category_id", "Kategorie der Website-Artikel (FAQ)"]];

function when(value) {
  const date = value ? new Date(value) : null;
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString("de-AT", { dateStyle: "short", timeStyle: "short" }) : "–";
}

function useSaver() {
  const [state, setState] = useState({ busy: false, error: "", done: "" });
  const run = async (request, done) => {
    setState({ busy: true, error: "", done: "" });
    try {
      const result = await request();
      setState({ busy: false, error: "", done });
      return result;
    } catch (error) {
      setState({ busy: false, error: errorText(error), done: "" });
      return null;
    }
  };
  return [state, run];
}

function Connection({ settings }) {
  const { user } = useSession();
  const superAdmin = user?.role === "super_admin";
  const [form, setForm] = useState({
    dolibarr_enabled: Boolean(settings.dolibarr_enabled), dolibarr_base_url: settings.dolibarr_base_url || "",
    dolibarr_api_key: "", dolibarr_timeout_seconds: settings.dolibarr_timeout_seconds || 8,
    dolibarr_country_code: settings.dolibarr_country_code || "AT",
  });
  const [check, setCheck] = useState(null);
  const [state, run] = useSaver();
  const [checking, setChecking] = useState(false);

  const save = () => run(() => {
    const body = { dolibarr_enabled: form.dolibarr_enabled, dolibarr_timeout_seconds: Number(form.dolibarr_timeout_seconds),
      dolibarr_country_code: form.dolibarr_country_code.toUpperCase() };
    if (superAdmin) {
      body.dolibarr_base_url = form.dolibarr_base_url.trim();
      if (form.dolibarr_api_key.trim()) body.dolibarr_api_key = form.dolibarr_api_key.trim();
    }
    return adminRequest("PUT", "/admin/settings", body);
  }, "Gespeichert.");

  const test = async () => {
    setChecking(true);
    try {
      setCheck((await adminRequest("GET", "/admin/dolibarr/status")).connection);
    } catch (error) {
      setCheck({ connected: false, message: errorText(error) });
    } finally {
      setChecking(false);
    }
  };

  return (
    <Section title="Verbindung" description="Die Website spricht mit Dolibarr über einen eigenen Benutzer ohne Admin-Rechte (Klickwege im README).">
      <Toggle checked={form.dolibarr_enabled} onChange={(dolibarr_enabled) => setForm((current) => ({ ...current, dolibarr_enabled }))} label="Dolibarr verwenden" />
      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Adresse von Dolibarr" placeholder="https://erp.tabelander.co.at" value={form.dolibarr_base_url} disabled={!superAdmin}
          onChange={(event) => setForm((current) => ({ ...current, dolibarr_base_url: event.target.value }))} />
        <Field label={`API-Schlüssel (${settings.dolibarr_api_key_configured ? "gespeichert" : "fehlt"})`} type="password" autoComplete="new-password"
          placeholder={settings.dolibarr_api_key_configured ? "Neuen eingeben, um ihn zu ersetzen" : ""} value={form.dolibarr_api_key} disabled={!superAdmin}
          onChange={(event) => setForm((current) => ({ ...current, dolibarr_api_key: event.target.value }))} />
        <Field label="Wartezeit in Sekunden" type="number" min={1} max={60} value={form.dolibarr_timeout_seconds}
          onChange={(event) => setForm((current) => ({ ...current, dolibarr_timeout_seconds: event.target.value }))} />
        <Field label="Land neuer Kunden" maxLength={2} value={form.dolibarr_country_code}
          onChange={(event) => setForm((current) => ({ ...current, dolibarr_country_code: event.target.value }))} />
      </div>
      {!superAdmin && <Notice tone="info">Adresse und API-Schlüssel kann nur der Super-Admin ändern.</Notice>}
      <Notice tone="success">{state.done}</Notice>
      <Notice tone="error">{state.error}</Notice>
      <div className="flex flex-wrap gap-3">
        <button type="button" className="btn-primary" disabled={state.busy} onClick={save}>{state.busy ? "Wird gespeichert …" : "Speichern"}</button>
        <button type="button" className="btn-outline" disabled={checking} onClick={test}>{checking ? "Wird geprüft …" : "Verbindung prüfen"}</button>
      </div>
      {check && (
        <Notice tone={check.connected ? "success" : "error"}>
          {check.connected ? `Verbunden${check.version ? ` mit Dolibarr ${check.version}` : ""}. ${check.message}` : check.message}
          {check.detail ? ` (${check.detail})` : ""}
        </Notice>
      )}
    </Section>
  );
}

function Categories({ settings }) {
  const [codes, setCodes] = useState(settings.dolibarr_ticket_categories || {});
  const [state, run] = useSaver();
  return (
    <Section title="Themengruppen je Anfrageart" description="Optional: der Code einer Themengruppe aus Dolibarr (Tickets → Einstellungen), z. B. REPARATUR. Leer = ohne Themengruppe.">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {TYPES.map(([key, label]) => (
          <Field key={key} label={label} maxLength={32} value={codes[key] || ""}
            onChange={(event) => setCodes((current) => ({ ...current, [key]: event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, "") }))} />
        ))}
      </div>
      <Notice tone="success">{state.done}</Notice>
      <Notice tone="error">{state.error}</Notice>
      <button type="button" className="btn-primary self-start" disabled={state.busy}
        onClick={() => run(() => adminRequest("PUT", "/admin/settings", { dolibarr_ticket_categories: codes }), "Gespeichert.")}>
        Speichern
      </button>
    </Section>
  );
}

function Content({ overview, settings }) {
  const [choice, setChoice] = useState(Object.fromEntries(CONTENT.map(([key]) => [key, settings[key] || 0])));
  const [state, run] = useSaver();
  return (
    <Section title="Inhalte aus Dolibarr" description="Firmendaten und Öffnungszeiten kommen aus Unternehmen/Institution, die FAQ aus der Wissensbasis. Öffentlich ist nur, was die gewählte Kategorie hat und freigegeben ist. Die Rechtstexte stehen im nächsten Block.">
      <LoadState state={overview}>
        {(data) => (
          <>
            {Object.values(data.errors || {}).map((message) => <Notice key={message} tone="warning">{message}</Notice>)}
            <div className="grid gap-6 lg:grid-cols-2">
              <div className="text-[15px]">
                <p className="m-0 font-semibold">{data.company?.name || "Keine Firmendaten"}</p>
                <p className="m-0 text-body">{[data.company?.address, [data.company?.zip, data.company?.town].filter(Boolean).join(" ")].filter(Boolean).join(", ") || "–"}</p>
                <p className="m-0 mt-2 text-sm text-muted">Öffnungszeiten: {(data.opening_hours || []).map((item) => `${item.day} ${item.hours}`).join(" · ") || "keine eingetragen"}</p>
                <p className="m-0 text-sm text-muted">{data.faq_count || 0} FAQ freigegeben</p>
              </div>
              <div className="flex flex-col gap-3">
                {CONTENT.map(([key, label]) => (
                  <Field key={key} label={label} as="select" value={choice[key] || 0}
                    onChange={(event) => setChoice((current) => ({ ...current, [key]: Number(event.target.value) }))}>
                    <option value={0}>– keine Kategorie –</option>
                    {(data.categories || []).map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}
                  </Field>
                ))}
                <Notice tone="success">{state.done}</Notice>
                <Notice tone="error">{state.error}</Notice>
                <div className="flex flex-wrap gap-3">
                  <button type="button" className="btn-primary" disabled={state.busy}
                    onClick={async () => { if (await run(() => adminRequest("PUT", "/admin/settings", choice), "Gespeichert.")) overview.reload(); }}>
                    Auswahl speichern
                  </button>
                  <button type="button" className="btn-outline" onClick={overview.reload}>Neu laden</button>
                </div>
              </div>
            </div>
          </>
        )}
      </LoadState>
    </Section>
  );
}

const LEGAL_HELP = {
  impressum: "Gewerbe, Behörde, Kammer und Rechtsform – dafür hat Dolibarr kein Feld. Der Rest des Impressums kommt aus den Firmendaten.",
  datenschutz: "Was mit den Daten deiner Kunden passiert, wie lange und welche Rechte sie haben.",
  nutzungsbedingungen: "Angebot, Daten auf dem Gerät, Abholung, Bezahlung, Gewährleistung und das Rücktrittsrecht bei Online-Zusagen.",
};
const LEGAL_STATES = {
  missing: ["fehlt", "bg-badge text-badge-ink"],
  draft: ["Entwurf", "bg-badge text-badge-ink"],
  released: ["freigegeben", "bg-[#E3F1EA] text-[#1E6B45]"],
};

function Check({ ok, required }) {
  const [sign, colours, words] = ok ? ["✓", "bg-[#E3F1EA] text-[#1E6B45]", "erledigt:"]
    : required ? ["!", "bg-badge text-badge-ink", "fehlt:"] : ["–", "bg-line-soft text-ink", "leer, nicht nötig:"];
  return (
    <span className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-sm font-bold ${colours}`}>
      <span aria-hidden="true">{sign}</span>
      <span className="sr-only">{words}</span>
    </span>
  );
}

/** Imprint, privacy policy and terms (#68, #69, #75): what is missing, and drafts to start from. */
function LegalTexts({ overview, settings }) {
  const [choice, setChoice] = useState(Object.fromEntries(
    ["dolibarr_imprint_article_id", "dolibarr_privacy_article_id", "dolibarr_terms_article_id"].map((key) => [key, settings[key] || 0])));
  const [state, run] = useSaver();

  const draft = async (text) => {
    const created = await run(() => adminRequest("POST", `/admin/legal/${text.kind}/draft`),
      `Entwurf angelegt. Jetzt in Dolibarr die markierten Stellen ergänzen und den Artikel freigeben.`);
    if (created) {
      setChoice((current) => ({ ...current, [text.setting]: created.article_id }));
      overview.reload();
    }
  };

  return (
    <Section title="Rechtstexte"
      description="Das Impressum entsteht aus den Firmendaten in Dolibarr, Datenschutzerklärung und Nutzungsbedingungen sind Artikel der Wissensbasis. Die Entwürfe sind in einfacher Sprache; was nur du weißt, ist mit „BITTE …“ markiert. Vor der Freigabe am besten von der WKO prüfen lassen.">
      <LoadState state={overview}>
        {({ legal }) => (
          <>
            {legal.error && <Notice tone="warning">{legal.error}</Notice>}
            {legal.todo.length === 0
              ? <Notice tone="success">Impressum, Datenschutzerklärung und Nutzungsbedingungen sind vollständig und freigegeben.</Notice>
              : <Notice tone="warning">Noch offen: {legal.todo.join(" · ")}</Notice>}
            <div className="flex flex-col gap-3">
              <h3 className="m-0 text-lg font-bold">Impressum aus den Firmendaten</h3>
              <ul className="m-0 flex list-none flex-col gap-2 p-0">
                {legal.imprint.map((item) => (
                  <li key={item.label} className="flex items-start gap-3 text-[15px]">
                    <Check ok={item.ok} required={item.required} />
                    <span>
                      <b>{item.label}</b>{!item.required && <span className="text-muted"> ({item.note})</span>}
                      {!item.ok && <span className="block text-sm text-body">{item.where}</span>}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
            <div className="grid gap-4 xl:grid-cols-3">
              {legal.texts.map((text) => (
                <div key={text.kind} className="flex flex-col gap-3 rounded-xl border border-line p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="m-0 text-lg font-bold">{text.label}</h3>
                    <span className={`rounded-full px-2.5 py-1 text-[13px] font-semibold ${LEGAL_STATES[text.state][1]}`}>{LEGAL_STATES[text.state][0]}</span>
                  </div>
                  <p className="m-0 text-sm text-body">{LEGAL_HELP[text.kind]}</p>
                  {text.gone && <Notice tone="warning">Der gewählte Artikel ist in Dolibarr nicht mehr da oder veraltet.</Notice>}
                  {text.open_points.length > 0 && (
                    <details className="text-sm">
                      <summary className="cursor-pointer font-semibold">
                        {text.open_points.length} {text.open_points.length === 1 ? "Stelle" : "Stellen"} noch zu ergänzen
                      </summary>
                      <ul className="mb-0 mt-2 flex flex-col gap-1 pl-5 text-body">
                        {text.open_points.map((point, index) => <li key={`${text.kind}-${index}`}>{point}</li>)}
                      </ul>
                    </details>
                  )}
                  <Field label="Artikel in Dolibarr" as="select" value={choice[text.setting] || 0}
                    onChange={(event) => setChoice((current) => ({ ...current, [text.setting]: Number(event.target.value) }))}>
                    <option value={0}>– kein Artikel –</option>
                    {legal.choices.map((item) => <option key={item.id} value={item.id}>{item.question}{item.status === 0 ? " (Entwurf)" : ""}</option>)}
                  </Field>
                  {text.state === "missing" && !choice[text.setting] && (
                    <button type="button" className="btn-outline self-start border-line" disabled={state.busy} onClick={() => draft(text)}>
                      Entwurf in Dolibarr anlegen
                    </button>
                  )}
                </div>
              ))}
            </div>
            <p className="m-0 text-[15px] text-body">
              So gibst du einen Text frei: in Dolibarr <b>Wissensbasis</b> → Artikel öffnen → <b>Ändern</b> → markierte Stellen ergänzen → <b>Speichern</b> → <b>Freigeben</b>.
              Danach hier <b>Neu laden</b>.
            </p>
            <Notice tone="success">{state.done}</Notice>
            <Notice tone="error">{state.error}</Notice>
            <div className="flex flex-wrap gap-3">
              <button type="button" className="btn-primary" disabled={state.busy}
                onClick={async () => { if (await run(() => adminRequest("PUT", "/admin/settings", choice), "Gespeichert.")) overview.reload(); }}>
                Auswahl speichern
              </button>
              <button type="button" className="btn-outline" onClick={overview.reload}>Neu laden</button>
            </div>
          </>
        )}
      </LoadState>
    </Section>
  );
}

function Queue() {
  const queue = useAdminData("/admin/dolibarr/queue");
  const [state, run] = useSaver();
  return (
    <Section title="Warteschlange" description="Anfragen, die noch nicht in Dolibarr sind. Die Website versucht es automatisch alle 5 bis 60 Minuten; nach 30 Minuten kommt einmal eine Warn-Mail.">
      <LoadState state={queue}>
        {(data) => (
          <>
            {data.warning_mail?.last_error && <Notice tone="warning">Warn-Mail konnte nicht gesendet werden: {data.warning_mail.last_error}</Notice>}
            {data.waiting === 0 ? <p className="m-0 text-body">Alle Anfragen sind in Dolibarr angekommen.</p> : (
              <ul className="m-0 flex list-none flex-col divide-y divide-line-soft p-0">
                {data.items.map((item) => (
                  <li key={item.id} className="flex flex-col gap-1 py-3">
                    <b className="font-display">{item.ref}{item.gave_up ? " · angehalten" : ""}</b>
                    <span className="text-[15px] text-badge-ink">{item.reason}</span>
                    <span className="text-sm text-muted">seit {when(item.created_at)} · {item.attempts} Versuch(e){item.gave_up ? "" : ` · nächster ${when(item.next_attempt_at)}`}</span>
                  </li>
                ))}
              </ul>
            )}
            <Notice tone="success">{state.done}</Notice>
            <Notice tone="error">{state.error}</Notice>
            {data.waiting > 0 && (
              <button type="button" className="btn-primary self-start" disabled={state.busy}
                onClick={async () => {
                  const result = await run(() => adminRequest("POST", "/admin/dolibarr/queue/run"), "");
                  if (result) queue.reload();
                }}>
                {state.busy ? "Wird gesendet …" : "Jetzt erneut senden"}
              </button>
            )}
          </>
        )}
      </LoadState>
    </Section>
  );
}

function Migration() {
  const { user } = useSession();
  const [plan, setPlan] = useState(null);
  const [result, setResult] = useState(null);
  const [state, run] = useSaver();
  const total = plan ? plan.inquiries_to_send + plan.inquiries_to_finish + plan.contact_messages + (plan.faqs || 0) : 0;
  const dryRun = async () => setPlan(await run(() => adminRequest("GET", "/admin/dolibarr/migration"), ""));
  return (
    <Section title="Altdaten umziehen (einmalig)" description="Anfragen und Nachrichten von vor der Umstellung kommen nach Dolibarr – ohne Mail an die Kunden – und werden danach hier gelöscht. Die bisherigen FAQ kommen als Entwurf in die Wissensdatenbank. Der Probelauf ändert nichts.">
      <div className="flex flex-wrap gap-3">
        <button type="button" className="btn-outline" disabled={state.busy} onClick={dryRun}>Probelauf</button>
        {plan && total > 0 && user?.role === "super_admin" && (
          <button type="button" className="btn-primary" disabled={state.busy}
            onClick={async () => { setResult(await run(() => adminRequest("POST", "/admin/dolibarr/migration"), "")); dryRun(); }}>
            Jetzt übergeben
          </button>
        )}
      </div>
      <Notice tone="error">{state.error}</Notice>
      {plan && (total === 0 ? <Notice tone="success">Keine Altdaten mehr – der Umzug ist erledigt.</Notice> : (
        <ul className="m-0 flex max-h-72 list-none flex-col divide-y divide-line-soft overflow-y-auto p-0">
          {plan.items.map((item, index) => (
            <li key={`${item.ref}-${index}`} className="py-2 text-[15px]"><b className="font-display">{item.ref}</b> <span className="text-body">– {item.what}</span></li>
          ))}
        </ul>
      ))}
      {result && (
        <Notice tone={result.failed?.length ? "warning" : "success"}>
          Übergeben: {result.sent} · abgeschlossen: {result.finished} · Nachrichten: {result.contact_messages} · FAQ: {result.faqs || 0} · übrig: {result.remaining}
          {result.failed?.map((item) => ` · ${item.ref}: ${item.reason}`).join("")}
        </Notice>
      )}
    </Section>
  );
}

/** Every Dolibarr switch on one page (#62). */
export default function Dolibarr() {
  const settings = useAdminData("/admin/settings");
  // Read once, freshly from Dolibarr, for both the content and the legal texts.
  const overview = useAdminData("/admin/dolibarr/content");
  return (
    <>
      <PageHeader title="Dolibarr" description="Kunden, Anfragen, Firmendaten, Rechtstexte und FAQ liegen in Dolibarr. Hier stellst du ein, wie die Website damit spricht." />
      <LoadState state={settings}>
        {(data) => (
          <>
            <Connection settings={data} />
            <Content overview={overview} settings={data} />
            <LegalTexts overview={overview} settings={data} />
            <Categories settings={data} />
          </>
        )}
      </LoadState>
      <Queue />
      <Migration />
    </>
  );
}

import { useState } from "react";
import { Field } from "../../forms/fields.jsx";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { useSession } from "../session.jsx";
import { LoadState, Notice, PageHeader, Section } from "../ui.jsx";

function useSaver() {
  const [state, setState] = useState({ busy: false, error: "", done: "" });
  const run = async (request, done) => {
    setState({ busy: true, error: "", done: "" });
    try {
      const result = await request();
      setState({ busy: false, error: "", done: typeof done === "function" ? done(result) : done });
      return result;
    } catch (error) {
      setState({ busy: false, error: errorText(error), done: "" });
      return null;
    }
  };
  return [state, run];
}

function Status({ state }) {
  return (
    <>
      <Notice tone="success">{state.done}</Notice>
      <Notice tone="error">{state.error}</Notice>
    </>
  );
}

function Website({ settings }) {
  const [form, setForm] = useState({
    canonical_base_url: settings.canonical_base_url || "", service_area: settings.service_area || "",
    seo_default_title: settings.seo_default_title || "", seo_default_description: settings.seo_default_description || "",
    google_review_url: settings.google_review_url || "",
  });
  const [state, run] = useSaver();
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));
  return (
    <Section title="Website" description="Was Google und Link-Vorschauen über die Seite erfahren. Firmendaten, Öffnungszeiten und Social Media kommen aus Dolibarr.">
      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Öffentliche Adresse" placeholder="https://it.tabelander.co.at" value={form.canonical_base_url} onChange={set("canonical_base_url")} />
        <Field label="Einzugsgebiet" placeholder="z. B. Tirol und ganz Österreich per Versand" maxLength={200} value={form.service_area} onChange={set("service_area")} />
        <Field label="Titel der Startseite" hint="(leer = Standard)" maxLength={70} value={form.seo_default_title} onChange={set("seo_default_title")} />
        <Field label="Google-Profil" hint="(Link „Auf Google bewerten“)" type="url" placeholder="https://g.page/r/…/review" value={form.google_review_url} onChange={set("google_review_url")} />
      </div>
      <Field label="Beschreibung der Startseite" hint="(leer = Standard; erscheint bei Google und in Vorschauen)" as="textarea" rows={2} maxLength={180}
        value={form.seo_default_description} onChange={set("seo_default_description")} />
      <Status state={state} />
      <button type="button" className="btn-primary self-start" disabled={state.busy}
        onClick={() => run(() => adminRequest("PUT", "/admin/settings", Object.fromEntries(
          Object.entries(form).map(([key, value]) => [key, value.trim()]))), "Gespeichert.")}>
        Speichern
      </button>
    </Section>
  );
}

function Mail({ settings }) {
  const { user } = useSession();
  const superAdmin = user?.role === "super_admin";
  const [form, setForm] = useState({
    smtp_host: settings.smtp_host || "", smtp_port: settings.smtp_port || "", smtp_security: settings.smtp_security || "starttls",
    smtp_username: settings.smtp_username || "", smtp_password: "", smtp_from: settings.smtp_from || "",
    smtp_from_name: settings.smtp_from_name || "", warning_email: settings.warning_email || "",
  });
  const [testTo, setTestTo] = useState("");
  const [state, run] = useSaver();
  const [testState, runTest] = useSaver();
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const save = () => run(() => {
    const body = { smtp_from: form.smtp_from.trim(), smtp_from_name: form.smtp_from_name.trim(), warning_email: form.warning_email.trim() };
    if (superAdmin) {
      Object.assign(body, { smtp_host: form.smtp_host.trim(), smtp_security: form.smtp_security, smtp_username: form.smtp_username.trim() });
      if (form.smtp_port) body.smtp_port = Number(form.smtp_port);
      if (form.smtp_password) body.smtp_password = form.smtp_password;
    }
    return adminRequest("PUT", "/admin/settings", body);
  }, "Gespeichert. Mit „Test-Mail senden“ prüfst du, ob alles stimmt.");

  return (
    <Section title="E-Mail-Versand der Website" description="Für Warnungen, wenn Anfragen nicht in Dolibarr ankommen. Bestätigungen an Kunden verschickt Dolibarr selbst. Das Passwort wird nie wieder angezeigt.">
      <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_120px_200px]">
        <Field label="Mailserver (SMTP)" placeholder="z. B. smtp.dein-anbieter.at" value={form.smtp_host} onChange={set("smtp_host")} disabled={!superAdmin} />
        <Field label="Port" type="number" min={1} max={65535} placeholder="587" value={form.smtp_port} onChange={set("smtp_port")} disabled={!superAdmin} />
        <Field label="Verschlüsselung" as="select" value={form.smtp_security} onChange={set("smtp_security")} disabled={!superAdmin}>
          <option value="starttls">STARTTLS (meist 587)</option>
          <option value="ssl">SSL/TLS (meist 465)</option>
          <option value="none">Keine (nur im eigenen Netz)</option>
        </Field>
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Benutzername" autoComplete="off" value={form.smtp_username} onChange={set("smtp_username")} disabled={!superAdmin} />
        <Field label={`Passwort (${settings.smtp_password_configured ? "gespeichert" : "nicht gesetzt"})`} type="password" autoComplete="new-password"
          placeholder={settings.smtp_password_configured ? "Neues eingeben, um es zu ersetzen" : ""} value={form.smtp_password} onChange={set("smtp_password")} disabled={!superAdmin} />
        <Field label="Absender-Adresse" type="email" placeholder="office@tabelander.co.at" value={form.smtp_from} onChange={set("smtp_from")} />
        <Field label="Absender-Name" placeholder="IT-Tabelander" maxLength={120} value={form.smtp_from_name} onChange={set("smtp_from_name")} />
        <Field label="Warnungen gehen an" hint="(leer = Super-Admins)" type="email" value={form.warning_email} onChange={set("warning_email")} />
      </div>
      {!superAdmin && <Notice tone="info">Mailserver, Benutzername und Passwort kann nur der Super-Admin ändern.</Notice>}
      <Status state={state} />
      <button type="button" className="btn-primary self-start" disabled={state.busy} onClick={save}>Speichern</button>
      <div className="flex flex-col gap-2 border-t border-line-soft pt-4 sm:flex-row sm:items-end">
        <Field label="Test-Mail an" hint="(leer = deine Login-E-Mail)" type="email" className="sm:flex-1" value={testTo} onChange={(event) => setTestTo(event.target.value)} />
        <button type="button" className="btn-outline" disabled={testState.busy}
          onClick={() => runTest(() => adminRequest("POST", "/admin/settings/test-mail", testTo ? { to: testTo } : {}), (result) => result?.message || "Gesendet.")}>
          {testState.busy ? "Wird gesendet …" : "Test-Mail senden"}
        </button>
      </div>
      <Status state={testState} />
    </Section>
  );
}

function Account() {
  const { user, setUser } = useSession();
  const [form, setForm] = useState({ email: user?.email || "", current_password: "", new_password: "", repeat: "" });
  const [state, run] = useSaver();
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));
  const save = (event) => {
    event.preventDefault();
    if (form.new_password && form.new_password !== form.repeat) {
      run(() => Promise.reject(new Error("Die neuen Passwörter stimmen nicht überein.")), "");
      return;
    }
    run(async () => {
      const result = await adminRequest("PUT", "/auth/account", {
        current_password: form.current_password, email: form.email.trim() || undefined, new_password: form.new_password || undefined,
      });
      setUser(result.user);
      setForm((current) => ({ ...current, current_password: "", new_password: "", repeat: "" }));
      return result;
    }, "Gespeichert.");
  };
  return (
    <Section title="Dein Zugang" description="E-Mail und Passwort zum Anmelden. Das aktuelle Passwort bestätigt die Änderung.">
      <form onSubmit={save} className="grid gap-4 md:grid-cols-2">
        <Field label="Login-E-Mail" type="email" autoComplete="username" value={form.email} onChange={set("email")} />
        <Field label="Aktuelles Passwort" type="password" autoComplete="current-password" required value={form.current_password} onChange={set("current_password")} />
        <Field label="Neues Passwort" hint="(optional, mindestens 12 Zeichen)" type="password" autoComplete="new-password" minLength={12} value={form.new_password} onChange={set("new_password")} />
        <Field label="Neues Passwort wiederholen" type="password" autoComplete="new-password" value={form.repeat} onChange={set("repeat")} />
        <div className="flex flex-col gap-3 md:col-span-2">
          <Status state={state} />
          <button type="submit" className="btn-primary self-start" disabled={state.busy}>Zugang speichern</button>
        </div>
      </form>
    </Section>
  );
}

/** Settings of the website itself (#61); each topic lives in one place only. */
export default function Technik() {
  const settings = useAdminData("/admin/settings");
  return (
    <>
      <PageHeader title="Technik" description="Einstellungen der Website selbst. Dolibarr hat seine eigene Seite." />
      <LoadState state={settings}>
        {(data) => (
          <>
            <Website settings={data} />
            <Mail settings={data} />
          </>
        )}
      </LoadState>
      <Account />
    </>
  );
}

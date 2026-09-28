import { useState } from "react";
import { ABOUT_TEXT, QUALIFICATIONS } from "../../content/about.js";
import { Field } from "../../forms/fields.jsx";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { ImagePicker, LoadState, Notice, PageHeader, Section } from "../ui.jsx";

function AboutForm({ settings }) {
  const [form, setForm] = useState({
    about_text: settings.about_text || "",
    about_qualifications: (settings.about_qualifications || []).join("\n"),
    about_photo_url: settings.about_photo_url || "",
  });
  const [state, setState] = useState({ busy: false, error: "", done: "" });

  const save = async (event) => {
    event.preventDefault();
    setState({ busy: true, error: "", done: "" });
    try {
      await adminRequest("PUT", "/admin/settings", {
        about_text: form.about_text.trim(),
        about_qualifications: form.about_qualifications.split("\n").map((line) => line.trim()).filter(Boolean),
        about_photo_url: form.about_photo_url,
      });
      setState({ busy: false, error: "", done: "Gespeichert. Die Website zeigt den neuen Stand sofort." });
    } catch (error) {
      setState({ busy: false, error: errorText(error), done: "" });
    }
  };

  return (
    <form onSubmit={save} className="flex flex-col gap-4">
      <Field label="Text" hint="(leer = der bisherige Text)" as="textarea" rows={7} maxLength={4000}
        placeholder={ABOUT_TEXT} value={form.about_text}
        onChange={(event) => setForm((current) => ({ ...current, about_text: event.target.value }))} />
      <Field label="Qualifikationen" hint="(eine pro Zeile, leer = die bisherigen)" as="textarea" rows={4}
        placeholder={QUALIFICATIONS.join("\n")} value={form.about_qualifications}
        onChange={(event) => setForm((current) => ({ ...current, about_qualifications: event.target.value }))} />
      <ImagePicker label="Foto von dir" hint="(optional, z. B. in der Werkstatt)" url={form.about_photo_url}
        onChange={(url) => setForm((current) => ({ ...current, about_photo_url: url }))} />
      <Notice tone="success">{state.done}</Notice>
      <Notice tone="error">{state.error}</Notice>
      <div className="flex flex-wrap gap-3">
        <button type="submit" disabled={state.busy} className="btn-primary">{state.busy ? "Wird gespeichert …" : "Speichern"}</button>
        <a href="/#ueber" target="_blank" rel="noopener noreferrer" className="btn-outline">Auf der Website ansehen ↗</a>
      </div>
    </form>
  );
}

/** Texts of the website that are no service (#58). */
export default function Texts() {
  const state = useAdminData("/admin/settings");
  return (
    <>
      <PageHeader title="Texte" description="Was auf der Website steht und nicht zu einer Leistung gehört." />
      <Section title="Über mich">
        <LoadState state={state}>{(settings) => <AboutForm settings={settings} />}</LoadState>
      </Section>
    </>
  );
}

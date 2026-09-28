import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router";
import { Field } from "../../forms/fields.jsx";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { ConfirmButton, ImagePicker, LoadState, Notice, PageHeader, Toggle } from "../ui.jsx";

const EMPTY = { title: "", heading: "", long_description: "", bullets: [], image_url: "", active: true };

/** Every field here shows on the website; nothing without an effect (#58). */
function Editor({ service, isNew }) {
  const navigate = useNavigate();
  const [form, setForm] = useState({ ...EMPTY, ...service, bullets: (service.bullets || []).join("\n") });
  const [state, setState] = useState({ busy: false, error: "", done: "" });
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const save = async (event) => {
    event.preventDefault();
    setState({ busy: true, error: "", done: "" });
    const body = {
      title: form.title.trim(), heading: form.heading.trim(), long_description: form.long_description.trim(),
      bullets: form.bullets.split("\n").map((line) => line.trim()).filter(Boolean),
      image_url: form.image_url || "", active: form.active,
    };
    try {
      const saved = isNew
        ? await adminRequest("POST", "/admin/services", body)
        : await adminRequest("PUT", `/admin/services/${service.id}`, body);
      if (isNew) navigate(`/admin/leistungen/${saved.id}`, { replace: true, state: { saved: true } });
      else setState({ busy: false, error: "", done: "Gespeichert. Die Website zeigt den neuen Stand sofort." });
    } catch (error) {
      setState({ busy: false, error: errorText(error), done: "" });
    }
  };

  const remove = async () => {
    try {
      await adminRequest("DELETE", `/admin/services/${service.id}`);
      navigate("/admin/leistungen", { replace: true });
    } catch (error) {
      setState({ busy: false, error: errorText(error), done: "" });
    }
  };

  return (
    <form onSubmit={save} className="card flex flex-col gap-4 p-5 md:p-6">
      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Name im Tab" required maxLength={60} value={form.title} onChange={set("title")} placeholder="z. B. PC-Reparatur" />
        <Field label="Überschrift" maxLength={120} value={form.heading} onChange={set("heading")} placeholder="z. B. Strukturierte Diagnose statt Rätselraten" />
      </div>
      <Field label="Text" as="textarea" rows={5} maxLength={2000} value={form.long_description} onChange={set("long_description")} />
      <Field label="Stichpunkte" hint="(einer pro Zeile)" as="textarea" rows={6} value={form.bullets} onChange={set("bullets")} />
      <ImagePicker label="Bild" url={form.image_url} onChange={(url) => setForm((current) => ({ ...current, image_url: url }))} />
      <Toggle checked={form.active} onChange={(active) => setForm((current) => ({ ...current, active }))} label="Auf der Website zeigen" />
      <Notice tone="success">{state.done}</Notice>
      <Notice tone="error">{state.error}</Notice>
      <div className="flex flex-wrap items-center gap-3">
        <button type="submit" disabled={state.busy} className="btn-primary">{state.busy ? "Wird gespeichert …" : "Speichern"}</button>
        {!isNew && <a href={`/leistungen/${service.slug}`} target="_blank" rel="noopener noreferrer" className="btn-outline">Vorschau ↗</a>}
        {!isNew && <ConfirmButton label="Löschen" confirm="Wirklich löschen" onConfirm={remove} className="ml-auto" />}
      </div>
    </form>
  );
}

export default function ServiceEditor() {
  const { id } = useParams();
  const isNew = id === "neu";
  const state = useAdminData("/admin/services");
  return (
    <>
      <PageHeader title={isNew ? "Neue Leistung" : "Leistung bearbeiten"} actions={<Link to="/admin/leistungen" className="btn-outline">← Alle Leistungen</Link>} />
      {isNew ? <Editor service={EMPTY} isNew /> : (
        <LoadState state={state}>
          {(services) => {
            const service = services.find((item) => item.id === id);
            return service ? <Editor key={service.id} service={service} /> : <Notice tone="error">Diese Leistung gibt es nicht mehr.</Notice>;
          }}
        </LoadState>
      )}
    </>
  );
}

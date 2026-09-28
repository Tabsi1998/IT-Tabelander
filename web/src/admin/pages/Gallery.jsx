import { useEffect, useId, useState } from "react";
import { Field } from "../../forms/fields.jsx";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { Chips, ConfirmButton, LoadState, Notice, PageHeader, Section, Toggle } from "../ui.jsx";

const AREAS = [["pc_build", "PC-Bau"], ["repair", "Reparatur"], ["upgrade", "Upgrade"], ["controller", "Controller"],
  ["console", "Konsole"], ["other", "Sonstiges"]];

/** A new photo, from the phone camera or its gallery (Design A, #60). */
function NewPhoto({ onAdded }) {
  const cameraId = useId();
  const pickId = useId();
  const [file, setFile] = useState(null);
  const [preview, setPreview] = useState("");
  const [form, setForm] = useState({ caption: "", category: "pc_build", visible: true });
  const [state, setState] = useState({ busy: false, error: "", done: "" });

  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

  const choose = (event) => {
    const chosen = event.target.files?.[0];
    event.target.value = "";
    if (!chosen) return;
    setFile(chosen);
    setPreview(URL.createObjectURL(chosen));
    setState({ busy: false, error: "", done: "" });
  };

  const save = async () => {
    setState({ busy: true, error: "", done: "" });
    const body = new FormData();
    body.append("file", file);
    body.append("caption", form.caption.trim());
    body.append("category", form.category);
    body.append("visible", String(form.visible));
    try {
      const item = await adminRequest("POST", "/admin/gallery", body);
      onAdded(item);
      setFile(null);
      setPreview("");
      setForm((current) => ({ ...current, caption: "" }));
      setState({ busy: false, error: "", done: form.visible
        ? "Gespeichert. Das Foto ist jetzt in „Aus der Werkstatt“."
        : "Gespeichert. Das Foto ist versteckt, bis du es einschaltest." });
    } catch (error) {
      setState({ busy: false, error: errorText(error), done: "" });
    }
  };

  return (
    <Section title="Neues Foto">
      {preview && <img src={preview} alt="Vorschau des gewählten Fotos" className="max-h-[320px] w-full max-w-[480px] rounded-xl object-cover" />}
      <div className="grid max-w-[480px] grid-cols-2 gap-2">
        <label htmlFor={cameraId} className="btn-outline min-h-[48px] cursor-pointer text-center text-[15px]">{preview ? "Neu aufnehmen" : "Foto aufnehmen"}</label>
        <input id={cameraId} type="file" accept="image/*" capture="environment" className="sr-only" onChange={choose} />
        <label htmlFor={pickId} className="btn-outline min-h-[48px] cursor-pointer border-line text-[15px]">Aus Galerie</label>
        <input id={pickId} type="file" accept="image/*" className="sr-only" onChange={choose} />
      </div>
      {file && (
        <div className="flex max-w-[640px] flex-col gap-4">
          <Field label="Titel" placeholder="z. B. Gaming-PC mit Wasserkühlung" maxLength={160} value={form.caption}
            onChange={(event) => setForm((current) => ({ ...current, caption: event.target.value }))} />
          <div className="flex flex-col gap-2">
            <span className="text-[15px] font-semibold">Bereich</span>
            <Chips label="Bereich" options={AREAS} value={form.category} onChange={(category) => setForm((current) => ({ ...current, category }))} />
          </div>
          <Toggle checked={form.visible} onChange={(visible) => setForm((current) => ({ ...current, visible }))} label="Auf der Website zeigen" />
          <button type="button" onClick={save} disabled={state.busy} className="btn-primary min-h-[54px] text-[17px]">
            {state.busy ? "Wird hochgeladen …" : "Speichern"}
          </button>
        </div>
      )}
      <Notice tone="success">{state.done}</Notice>
      <Notice tone="error">{state.error}</Notice>
    </Section>
  );
}

function PhotoCard({ item, index, count, onChange, onMove, onDelete }) {
  const [caption, setCaption] = useState(item.caption || "");
  return (
    <li className="card flex flex-col overflow-hidden">
      <img src={item.thumb_url || item.image_url} alt={item.caption || item.category_label} loading="lazy"
        className={`aspect-[4/3] w-full object-cover ${item.visible === false ? "opacity-50" : ""}`} />
      <div className="flex flex-col gap-3 p-4">
        <Field label="Titel" maxLength={160} value={caption} onChange={(event) => setCaption(event.target.value)}
          onBlur={() => { if (caption.trim() !== (item.caption || "")) onChange({ caption: caption.trim() }); }} />
        <Chips label={`Bereich von Foto ${index + 1}`} options={AREAS} value={item.category} onChange={(category) => onChange({ category })} />
        <Toggle checked={item.visible !== false} onChange={(visible) => onChange({ visible })} label="Auf der Website" />
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" className="btn-outline min-h-[44px] border-line px-3" disabled={index === 0}
            aria-label={`Foto ${index + 1} nach vorne`} onClick={() => onMove(-1)}>←</button>
          <button type="button" className="btn-outline min-h-[44px] border-line px-3" disabled={index === count - 1}
            aria-label={`Foto ${index + 1} nach hinten`} onClick={() => onMove(1)}>→</button>
          <ConfirmButton label="Löschen" confirm="Foto löschen" onConfirm={onDelete} className="ml-auto" />
        </div>
      </div>
    </li>
  );
}

/** "Aus der Werkstatt" (#60): photos in the order of the website. */
export default function Gallery() {
  const state = useAdminData("/admin/gallery");
  const [error, setError] = useState("");

  const run = async (change, request) => {
    setError("");
    const before = state.data;
    state.setData(change);
    try {
      await request();
    } catch (failure) {
      state.setData(before);
      setError(errorText(failure));
    }
  };

  return (
    <>
      <PageHeader title="Galerie" description="Fotos deiner Arbeiten für „Aus der Werkstatt“. Ohne sichtbare Fotos ist der Bereich auf der Website ausgeblendet." />
      <NewPhoto onAdded={(item) => state.setData((current) => ({ ...current, items: [item, ...(current?.items || [])] }))} />
      <Notice tone="error">{error}</Notice>
      <LoadState state={state}>
        {({ items }) => (items.length === 0 ? <p className="card m-0 p-5 text-body">Noch keine Fotos.</p> : (
          <ul className="m-0 grid list-none gap-4 p-0 sm:grid-cols-2 xl:grid-cols-3">
            {items.map((item, index) => (
              <PhotoCard key={item.id} item={item} index={index} count={items.length}
                onChange={(changes) => run(
                  (current) => ({ ...current, items: current.items.map((entry) => (entry.id === item.id ? { ...entry, ...changes } : entry)) }),
                  () => adminRequest("PUT", `/admin/gallery/${item.id}`, changes))}
                onMove={(step) => {
                  const list = [...items];
                  const [moved] = list.splice(index, 1);
                  list.splice(index + step, 0, moved);
                  run((current) => ({ ...current, items: list }),
                    () => adminRequest("POST", "/admin/gallery/order", { ids: list.map((entry) => entry.id) }));
                }}
                onDelete={() => run((current) => ({ ...current, items: current.items.filter((entry) => entry.id !== item.id) }),
                  () => adminRequest("DELETE", `/admin/gallery/${item.id}`))} />
            ))}
          </ul>
        ))}
      </LoadState>
    </>
  );
}

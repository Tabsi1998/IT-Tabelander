import { useRef, useState } from "react";
import { Link, useNavigate } from "react-router";
import { Choice, Field, FormError } from "../../forms/fields.jsx";
import { DEVICES, NEEDS_DEVICE, REQUEST_TYPES, SOURCES, WITH_BUDGET, WITHOUT_DEVICE } from "../../forms/InquiryForm.jsx";
import { portalRequest } from "../api.js";
import { FilePicker, uploadAll } from "../files.jsx";
import { usePortal } from "../PortalApp.jsx";
import { PageTitle } from "./parts.jsx";

const EMPTY = { device_type: "", device_source: "", manufacturer: "", model: "", topic: "", description: "", budget: "", timeframe: "" };

/** A new inquiry of a signed-in customer (#123): the kinds of the website's
 *  form on one page, no contact details - those are known - and files right
 *  away. The ticket goes onto the customer's own customer in Dolibarr. */
export default function NewInquiry() {
  const navigate = useNavigate();
  const { me, overview } = usePortal();
  const customers = overview.data?.accounts || [];
  const [type, setType] = useState("");
  const [form, setForm] = useState(EMPTY);
  const [customer, setCustomer] = useState("");
  const [picked, setPicked] = useState([]);
  const [state, setState] = useState({ busy: false, error: "" });
  const created = useRef(null);
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const problem = () => {
    if (!type) return "Bitte wähle, worum es geht.";
    if (NEEDS_DEVICE.has(type) && !form.device_type) return "Bitte wähle das Gerät.";
    if (type === "controller_custom" && (!form.device_source || !form.manufacturer.trim() || !form.model.trim())) {
      return "Für einen Controller-Umbau brauche ich Herkunft, Hersteller und Modell.";
    }
    if (form.description.trim().length < 10) return "Bitte beschreib kurz, worum es geht (mindestens 10 Zeichen).";
    return "";
  };

  const send = async (event) => {
    event.preventDefault();
    const wrong = problem();
    if (wrong) { setState({ busy: false, error: wrong }); return; }
    setState({ busy: true, error: "" });
    try {
      // A retry after a failed upload must not open a second inquiry.
      created.current = created.current || await portalRequest("POST", "/inquiries", {
        request_type: type,
        ...(customer ? { customer_id: Number(customer) } : {}),
        device_type: form.device_type || (type === "controller_custom" ? "controller" : ""),
        ...(type === "controller_custom" ? { device_source: form.device_source } : {}),
        manufacturer: form.manufacturer.trim(), model: form.model.trim(), description: form.description.trim(),
        ...(WITHOUT_DEVICE.has(type) ? { topic: form.topic.trim() } : {}),
        ...(WITH_BUDGET.has(type) ? { budget: form.budget.trim(), timeframe: form.timeframe.trim() } : {}),
      });
      let failed = "";
      try {
        await uploadAll(created.current.id, picked, setPicked);
      } catch {
        failed = "Nicht alle Dateien kamen an – häng sie unten einfach noch einmal an.";
      }
      overview.reload();
      navigate(`/kundenbereich/anfragen/${created.current.id}`, { state: { created: true, failed } });
    } catch (error) {
      setState({ busy: false, error: error.message });
    }
  };

  return (
    <>
      <Link to="/kundenbereich/anfragen" className="self-start text-[15px] font-semibold no-underline">← Alle Anfragen</Link>
      <PageTitle text={`Deine Kontaktdaten kenne ich schon. Du bekommst eine Bestätigung an ${me.email} und siehst die Anfrage danach hier.`}>
        Neue Anfrage
      </PageTitle>
      <form onSubmit={send} noValidate aria-label="Neue Anfrage" className="card flex flex-col gap-6 p-5 md:p-7">
        <fieldset className="m-0 flex flex-col gap-3 border-0 p-0">
          <legend className="mb-3 p-0 text-xl font-bold">Worum geht&apos;s?</legend>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="group" aria-label="Anliegen">
            {REQUEST_TYPES.map((item) => (
              <Choice key={item.key} pressed={type === item.key} onClick={() => setType(item.key)}
                className="flex min-h-[76px] flex-col gap-1 rounded-xl p-4 text-left">
                <span className="font-display text-[17px] font-bold">{item.label}</span>
                <span className="text-sm text-muted">{item.text}</span>
              </Choice>
            ))}
          </div>
        </fieldset>

        {type && (
          <div className="flex flex-col gap-4">
            {type !== "controller_custom" && (
              <div className="flex flex-col gap-2">
                <span className="text-[15px] font-semibold">Gerät{NEEDS_DEVICE.has(type) ? "" : <span className="font-normal text-muted"> (optional)</span>}</span>
                <div className="flex flex-wrap gap-2" role="group" aria-label={NEEDS_DEVICE.has(type) ? "Gerät" : "Gerät (optional)"}>
                  {DEVICES.map(([key, label]) => (
                    <Choice key={key} pressed={form.device_type === key} onClick={() => setForm((current) => ({ ...current, device_type: key }))}
                      className="min-h-[44px] rounded-full px-4 text-[15px] font-semibold">{label}</Choice>
                  ))}
                </div>
              </div>
            )}
            {type === "controller_custom" && (
              <div className="flex flex-wrap gap-2" role="group" aria-label="Controller">
                {SOURCES.map(([key, label]) => (
                  <Choice key={key} pressed={form.device_source === key} onClick={() => setForm((current) => ({ ...current, device_source: key }))}
                    className="min-h-[44px] rounded-full px-4 text-[15px] font-semibold">{label}</Choice>
                ))}
              </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Hersteller" hint={type === "controller_custom" ? "" : "(optional)"} placeholder="z. B. Lenovo" maxLength={160}
                value={form.manufacturer} onChange={set("manufacturer")} />
              <Field label="Modell" hint={type === "controller_custom" ? "" : "(optional)"} placeholder="z. B. ThinkPad T14" maxLength={160}
                value={form.model} onChange={set("model")} />
            </div>
            {WITHOUT_DEVICE.has(type) && (
              <Field label="Worum geht es?" hint="(optional)" placeholder={type === "pc_build" ? "z. B. Gaming-PC" : "z. B. Laptop fürs Studium"}
                maxLength={80} value={form.topic} onChange={set("topic")} />
            )}
            <Field label={type === "pc_build" ? "Wofür soll der PC sein?" : "Was ist passiert?"} as="textarea" rows={5} maxLength={10000}
              placeholder={type === "pc_build" ? "z. B. Gaming in WQHD, leise, Budget grob 1.500 €" : "z. B. Startet seit gestern nicht mehr, Lüfter läuft kurz an und geht wieder aus."}
              value={form.description} onChange={set("description")} />
            {WITH_BUDGET.has(type) && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Budget" hint="(optional)" maxLength={120} value={form.budget} onChange={set("budget")} />
                <Field label="Bis wann?" hint="(optional)" maxLength={120} value={form.timeframe} onChange={set("timeframe")} />
              </div>
            )}
            {customers.length > 1 && (
              <Field label="Für" as="select" value={customer || String(customers[0].id)} onChange={(event) => setCustomer(event.target.value)}>
                {customers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </Field>
            )}
            <FilePicker picked={picked} setPicked={setPicked} busy={state.busy} onRefuse={(text) => setState({ busy: false, error: text })} />
          </div>
        )}

        <FormError message={state.error} />
        <button type="submit" disabled={state.busy} className="btn-primary min-h-[54px] self-stretch text-[17px] disabled:opacity-60 sm:self-start">
          {state.busy ? (created.current ? "Dateien werden hochgeladen …" : "Wird gesendet …") : "Anfrage senden"}
        </button>
      </form>
    </>
  );
}

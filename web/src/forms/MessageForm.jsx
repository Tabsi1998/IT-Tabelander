import { useState } from "react";
import { postJson, requestId } from "../lib/api.js";
import { Consent, Field, FormError, Honeypot, explain } from "./fields.jsx";

/** Short questions, appointments, anything that is no repair (#49). The
 *  message becomes a Dolibarr ticket without a new customer record (#42). */
export default function MessageForm() {
  const [form, setForm] = useState({ name: "", email: "", phone: "", message: "", honeypot: "" });
  const [consent, setConsent] = useState(false);
  const [id] = useState(() => requestId("kontakt"));
  const [state, setState] = useState({ sending: false, error: "", done: null });
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event) => {
    event.preventDefault();
    setState({ sending: true, error: "", done: null });
    try {
      const answer = await postJson("/contact", { ...form, request_id: id, consent });
      setState({ sending: false, error: "", done: answer });
    } catch (error) {
      setState({ sending: false, error: explain(error), done: null });
    }
  };

  if (state.done) {
    return (
      <div className="flex flex-col gap-3" role="status">
        <h3 className="m-0 text-2xl font-bold">Danke, deine Nachricht ist da.</h3>
        <p className="m-0 text-[17px] leading-normal text-body">
          Du bekommst gleich eine Bestätigung per E-Mail. Deine Nummer: <b className="font-display">{state.done.ref}</b>
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="relative flex flex-col gap-4" noValidate={false}>
      <p className="m-0 text-base text-body">Für kurze Fragen, Termine oder alles, was keine Reparatur ist.</p>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Name" autoComplete="name" required minLength={2} maxLength={128} value={form.name} onChange={set("name")} />
        <Field label="E-Mail" type="email" autoComplete="email" required value={form.email} onChange={set("email")} />
      </div>
      <Field label="Telefon" hint="(optional)" type="tel" autoComplete="tel" maxLength={40} value={form.phone} onChange={set("phone")} />
      <Field label="Nachricht" as="textarea" rows={5} required minLength={10} maxLength={5000} value={form.message} onChange={set("message")} />
      <Honeypot value={form.honeypot} onChange={(value) => setForm((current) => ({ ...current, honeypot: value }))} />
      <Consent checked={consent} onChange={setConsent}>
        Meine Angaben dürfen zur Beantwortung verwendet werden. Details in der <a href="/rechtliches/datenschutz" className="underline">Datenschutzerklärung</a>.
      </Consent>
      <FormError message={state.error} />
      <button type="submit" disabled={state.sending} className="btn-primary min-h-[52px] self-stretch text-[17px] md:self-start">
        {state.sending ? "Wird gesendet …" : "Nachricht senden"}
      </button>
    </form>
  );
}

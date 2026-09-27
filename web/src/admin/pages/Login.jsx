import { useState } from "react";
import { Field } from "../../forms/fields.jsx";
import { errorText } from "../api.js";
import { useSession } from "../session.jsx";
import { Notice } from "../ui.jsx";

export default function Login() {
  const { login, notice } = useSession();
  const [form, setForm] = useState({ email: "", password: "" });
  const [state, setState] = useState({ busy: false, error: "" });

  const submit = async (event) => {
    event.preventDefault();
    setState({ busy: true, error: "" });
    try {
      await login(form.email.trim(), form.password);
    } catch (error) {
      setState({ busy: false, error: errorText(error) });
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-page px-4 py-10">
      <main id="inhalt" className="card flex w-full max-w-[420px] flex-col gap-5 p-6 md:p-8">
        <img src="/brand/banner-on-light.webp" alt="IT-Tabelander" width="705" height="136" className="h-8 w-auto self-start" />
        <h1 className="m-0 text-2xl font-bold">Verwaltung</h1>
        <Notice tone="warning">{notice}</Notice>
        <form onSubmit={submit} className="flex flex-col gap-4">
          <Field label="E-Mail" type="email" autoComplete="username" required value={form.email}
            onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} />
          <Field label="Passwort" type="password" autoComplete="current-password" required value={form.password}
            onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} />
          <Notice tone="error">{state.error}</Notice>
          <button type="submit" disabled={state.busy} className="btn-primary min-h-[52px] text-[17px]">
            {state.busy ? "Wird angemeldet …" : "Anmelden"}
          </button>
        </form>
        <a href="/" className="text-[15px]">← Zur Website</a>
      </main>
    </div>
  );
}

import { useEffect, useState } from "react";
import { Link } from "react-router";
import Footer from "../components/Footer.jsx";
import { Consent, Field, FormError, Honeypot, StarInput, explain } from "../forms/fields.jsx";
import { postJson } from "../lib/api.js";
import SimpleHeader from "./SimpleHeader.jsx";

/** The personal link from the mail after a closed ticket (#71): /bewertung#<token>.
 *  What follows the "#" never reaches the server; the page sends it along itself. */
export default function Review() {
  const [token, setToken] = useState("");
  const [invite, setInvite] = useState({ status: "checking", ref: "", kind: "" });
  const [form, setForm] = useState({ rating: 0, text: "", author: "" });
  const [publishOk, setPublishOk] = useState(false);
  const [honeypot, setHoneypot] = useState("");
  const [state, setState] = useState({ busy: false, error: "", done: false });

  useEffect(() => {
    const found = window.location.hash.replace(/^#/, "");
    if (!/^[A-Za-z0-9_-]{20,100}$/.test(found)) {
      setInvite({ status: "gone", ref: "", kind: "" });
      return;
    }
    setToken(found);
    postJson("/review-invites/check", { token: found })
      .then((answer) => setInvite({ status: "open", ref: answer.ref, kind: answer.request_type_label }))
      .catch((error) => setInvite({ status: error?.status === 404 ? "gone" : "error", ref: "", kind: "", message: explain(error) }));
  }, []);

  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const send = async (event) => {
    event.preventDefault();
    let problem = "";
    if (!form.rating) problem = "Bitte wähle, wie viele Sterne du vergibst.";
    else if (form.text.trim().length < 2) problem = "Bitte schreib ein paar Worte dazu.";
    else if (form.author.trim().length < 2) problem = "Bitte gib an, mit welchem Namen die Bewertung erscheinen soll.";
    else if (!publishOk) problem = "Bitte bestätige, dass die Bewertung auf der Website erscheinen darf.";
    if (problem) { setState({ busy: false, error: problem, done: false }); return; }
    setState({ busy: true, error: "", done: false });
    try {
      await postJson("/review-invites/submit", {
        token, rating: form.rating, text: form.text.trim(), author: form.author.trim(), publish_ok: true, honeypot,
      });
      setState({ busy: false, error: "", done: true });
    } catch (error) {
      if (error?.status === 404) setInvite({ status: "gone", ref: "", kind: "" });
      setState({ busy: false, error: error?.status === 404 ? "" : explain(error), done: false });
    }
  };

  let content;
  if (state.done) {
    content = (
      <div role="status" className="flex flex-col gap-3">
        <h2 className="m-0 text-2xl font-bold md:text-[30px]">Danke für deine Bewertung!</h2>
        <p className="m-0 text-[17px] leading-normal text-body">
          Sie ist angekommen und erscheint auf der Website, sobald ich sie freigegeben habe.
        </p>
        <Link to="/" className="btn-primary self-start text-base">Zur Startseite</Link>
      </div>
    );
  } else if (invite.status === "checking") {
    content = <p className="m-0 text-body">Link wird geprüft …</p>;
  } else if (invite.status !== "open") {
    content = (
      <div className="flex flex-col gap-3">
        <h2 className="m-0 text-2xl font-bold md:text-[30px]">
          {invite.status === "gone" ? "Dieser Link ist abgelaufen oder wurde schon verwendet." : "Der Link lässt sich gerade nicht prüfen."}
        </h2>
        <p className="m-0 text-[17px] leading-normal text-body">
          {invite.status === "gone"
            ? "Jeder Link gilt 60 Tage und für eine Bewertung. Wenn du noch etwas sagen willst, schreib mir einfach über das Kontaktformular."
            : invite.message}
        </p>
        <Link to="/?kontakt=nachricht#kontakt" className="btn-outline self-start text-base">Zum Kontaktformular</Link>
      </div>
    );
  } else {
    content = (
      <form onSubmit={send} noValidate className="relative flex flex-col gap-5">
        <p className="m-0 text-[17px] leading-normal text-body">
          Zu deiner Anfrage <b className="text-ink">{invite.ref}</b> ({invite.kind}). Danke, dass du dir kurz Zeit nimmst!
        </p>
        <div className="flex flex-col gap-1.5">
          <span className="text-[15px] font-semibold">Wie zufrieden warst du?</span>
          <StarInput label="Wie zufrieden warst du?" value={form.rating} onChange={(rating) => setForm((current) => ({ ...current, rating }))} />
        </div>
        <Field label="Deine Bewertung" as="textarea" rows={5} maxLength={2000} value={form.text} onChange={set("text")}
          placeholder="Was hat gepasst, was könnte besser sein?" />
        <Field label="Name, der angezeigt wird" hint="(z. B. Vorname und erster Buchstabe des Nachnamens)" maxLength={60}
          autoComplete="off" value={form.author} onChange={set("author")} />
        <Honeypot value={honeypot} onChange={setHoneypot} />
        <Consent checked={publishOk} onChange={setPublishOk}>
          Meine Bewertung darf mit diesem Namen auf der Website erscheinen. Sie wird vorher geprüft. Details in der{" "}
          <a href="/rechtliches/datenschutz" className="underline">Datenschutzerklärung</a>.
        </Consent>
        <FormError message={state.error} />
        <button type="submit" disabled={state.busy} className="btn-primary self-start px-6 text-[17px] disabled:opacity-60">
          {state.busy ? "Wird gesendet …" : "Bewertung senden"}
        </button>
      </form>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-page">
      <SimpleHeader />
      <main id="inhalt" className="mx-auto flex w-full max-w-[720px] flex-1 flex-col gap-5 px-5 py-8 md:gap-6 md:px-6 md:py-14">
        <div className="kicker">Bewertung</div>
        <h1 className="m-0 text-[30px] font-bold md:text-[40px]">Wie war’s?</h1>
        <div className="card flex flex-col gap-4 p-5 md:p-8">{content}</div>
      </main>
      <Footer onHome={false} />
    </div>
  );
}

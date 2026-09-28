import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { Link, NavLink, Route, Routes, useLocation, useNavigate } from "react-router";
import Footer from "../components/Footer.jsx";
import Logo from "../components/Logo.jsx";
import { Field, FormError } from "../forms/fields.jsx";
import { portalRequest, usePortalData } from "./api.js";
import Inquiries from "./pages/Inquiries.jsx";
import { OfferDetail, Offers } from "./pages/Offers.jsx";
import { InvoiceDetail, Invoices } from "./pages/Invoices.jsx";
import Overview from "./pages/Overview.jsx";

const PortalContext = createContext(null);
export const usePortal = () => useContext(PortalContext);

const NAV = [
  { to: "/kundenbereich", label: "Übersicht", end: true },
  { to: "/kundenbereich/anfragen", label: "Anfragen", count: "repairs_running" },
  { to: "/kundenbereich/angebote", label: "Angebote", count: "offers_open" },
  { to: "/kundenbereich/rechnungen", label: "Rechnungen", count: "invoices_open" },
];

function Header({ email, onSignOut }) {
  return (
    <header className="border-b border-line bg-surface">
      <div className="mx-auto flex h-[60px] max-w-[1440px] items-center gap-3 px-4 md:h-[72px] md:gap-6 md:px-8 lg:px-12">
        <Link to="/" aria-label="IT-Tabelander – zur Startseite" className="flex shrink-0"><Logo className="h-6 w-auto md:h-[30px]" /></Link>
        <span className="truncate border-l border-line pl-3 font-display text-[17px] font-bold md:pl-6 md:text-lg">Kundenbereich</span>
        {onSignOut ? (
          <div className="ml-auto flex items-center gap-4 text-[15px]">
            <span className="hidden text-muted md:inline">Angemeldet als <b className="text-ink">{email}</b></span>
            <button type="button" onClick={onSignOut} className="btn-outline min-h-[40px] border-line px-3.5 text-[15px]">Abmelden</button>
          </div>
        ) : (
          <Link to="/" className="btn-outline ml-auto min-h-[40px] border-line px-3.5 text-[15px]">Zur Startseite</Link>
        )}
      </div>
    </header>
  );
}

function Frame({ email, onSignOut, padded = false, children }) {
  return (
    <div className={`flex min-h-screen flex-col bg-page ${padded ? "pb-16 lg:pb-0" : ""}`}>
      <a href="#inhalt" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-[60] focus:rounded focus:bg-surface focus:p-3">
        Zum Inhalt springen
      </a>
      <Header email={email} onSignOut={onSignOut} />
      {children}
      <Footer onHome={false} />
    </div>
  );
}

/** The e-mail link; whether the address is known is never said (#63). */
function SignIn() {
  const [email, setEmail] = useState("");
  const [state, setState] = useState({ busy: false, error: "", sent: false });

  const send = async (event) => {
    event.preventDefault();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) {
      setState({ busy: false, error: "Bitte gib eine gültige E-Mail-Adresse ein.", sent: false });
      return;
    }
    setState({ busy: true, error: "", sent: false });
    try {
      await portalRequest("POST", "/login", { email: email.trim() });
      setState({ busy: false, error: "", sent: true });
    } catch (error) {
      setState({ busy: false, error: error.message, sent: false });
    }
  };

  if (state.sent) {
    return (
      <div className="flex flex-col items-start gap-4" role="status">
        <span aria-hidden="true" className="flex h-14 w-14 items-center justify-center rounded-full bg-navy">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FE8122" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" />
          </svg>
        </span>
        <h1 className="m-0 text-[28px] font-bold">Schau in dein Postfach</h1>
        <p className="m-0 text-[17px] leading-normal text-body">
          Wenn die Adresse bei mir bekannt ist, ist der Link unterwegs. Er gilt 15 Minuten – tippe in der Mail einfach darauf.
        </p>
        <button type="button" className="btn-outline border-line" onClick={() => setState({ busy: false, error: "", sent: false })}>Andere Adresse eingeben</button>
      </div>
    );
  }
  return (
    <form onSubmit={send} noValidate className="flex flex-col gap-4">
      <h1 className="m-0 text-[28px] font-bold md:text-[34px]">Anmelden ohne Passwort</h1>
      <p className="m-0 text-[17px] leading-normal text-body">
        Gib die E-Mail-Adresse ein, die du bei deiner Anfrage verwendet hast. Du bekommst einen Link, der 15 Minuten gilt.
      </p>
      <Field label="E-Mail" type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} />
      <FormError message={state.error} />
      <button type="submit" disabled={state.busy} className="btn-primary min-h-[54px] text-[17px] disabled:opacity-60">
        {state.busy ? "Wird gesendet …" : "Anmelde-Link senden"}
      </button>
    </form>
  );
}

/** /kundenbereich/anmelden#<token>: the link from the mail. The part after
 *  the "#" never reaches a server log; the page sends it once. */
function Redeem({ onSignedIn }) {
  const navigate = useNavigate();
  const [error, setError] = useState("");
  const started = useRef(false);
  useEffect(() => {
    // A link opens once: never send it twice, whatever renders again.
    if (started.current) return;
    started.current = true;
    const token = window.location.hash.replace(/^#/, "");
    if (!/^[A-Za-z0-9_-]{20,100}$/.test(token)) {
      setError("Der Link ist unvollständig. Fordere einfach einen neuen an.");
      return;
    }
    portalRequest("POST", "/session", { token })
      .then((me) => { onSignedIn(me); navigate("/kundenbereich", { replace: true }); })
      .catch((failure) => setError(failure.message));
  }, [navigate, onSignedIn]);
  if (!error) return <p className="m-0 text-body" role="status">Anmeldung läuft …</p>;
  return (
    <div className="flex flex-col items-start gap-4">
      <h1 className="m-0 text-[28px] font-bold">Das hat nicht geklappt</h1>
      <p className="m-0 text-[17px] leading-normal text-body" role="alert">{error}</p>
      <Link to="/kundenbereich" className="btn-primary">Neuen Link anfordern</Link>
    </div>
  );
}

function Nav({ counts }) {
  const item = (active) => `flex min-h-[46px] items-center justify-between rounded-[10px] px-4 text-base font-semibold no-underline ${
    active ? "bg-navy text-on-navy hover:text-on-navy" : "text-ink"}`;
  return (
    <>
      <nav aria-label="Kundenbereich" className="hidden flex-col gap-1 border-r border-line bg-surface px-4 py-7 lg:flex">
        {NAV.map((entry) => (
          <NavLink key={entry.to} to={entry.to} end={entry.end} className={({ isActive }) => item(isActive)}>
            {entry.label}
            {entry.count && counts?.[entry.count] > 0 && (
              <span className="rounded-full bg-accent px-2 text-[13px] font-bold text-on-accent">{counts[entry.count]}</span>
            )}
          </NavLink>
        ))}
      </nav>
      <nav aria-label="Kundenbereich unten" className="fixed inset-x-0 bottom-0 z-40 grid h-16 grid-cols-4 border-t border-line bg-surface lg:hidden">
        {NAV.map((entry) => (
          <NavLink key={entry.to} to={entry.to} end={entry.end}
            className={({ isActive }) => `flex items-center justify-center text-sm font-bold no-underline ${isActive ? "text-link-hover" : "text-muted"}`}>
            {entry.label}
          </NavLink>
        ))}
      </nav>
    </>
  );
}

function SignedIn({ me, onSignOut }) {
  const overview = usePortalData("/overview");
  return (
    <PortalContext.Provider value={{ me, overview }}>
      <Frame email={me.email} onSignOut={onSignOut} padded>
        <div className="mx-auto w-full max-w-[1440px] flex-1 lg:grid lg:grid-cols-[260px_minmax(0,1fr)]">
          <Nav counts={overview.data?.counts} />
          <main id="inhalt" className="flex min-w-0 flex-col gap-6 px-4 pb-24 pt-6 md:px-8 lg:px-14 lg:pb-14 lg:pt-10">
            <Routes>
              <Route index element={<Overview />} />
              <Route path="anfragen" element={<Inquiries />} />
              <Route path="angebote" element={<Offers />} />
              <Route path="angebote/:id" element={<OfferDetail />} />
              <Route path="rechnungen" element={<Invoices />} />
              <Route path="rechnungen/:id" element={<InvoiceDetail />} />
              <Route path="*" element={<Overview />} />
            </Routes>
          </main>
        </div>
      </Frame>
    </PortalContext.Provider>
  );
}

function Alone({ children }) {
  return (
    <Frame>
      <main id="inhalt" className="mx-auto flex w-full max-w-[560px] flex-1 flex-col gap-5 px-5 py-10 md:py-16">
        <div className="card p-6 md:p-8">{children}</div>
      </main>
    </Frame>
  );
}

/** The customer area (#63-#66): its own part of the site, loaded only here. */
export default function PortalApp() {
  const location = useLocation();
  const navigate = useNavigate();
  const [session, setSession] = useState({ status: "checking", me: null });

  useEffect(() => {
    portalRequest("GET", "/me")
      .then((me) => setSession({ status: "in", me }))
      .catch((error) => setSession({ status: error.status === 404 ? "off" : error.status === 401 ? "out" : "error", me: null }));
  }, []);
  useEffect(() => {
    const out = () => setSession({ status: "out", me: null });
    window.addEventListener("portal-signed-out", out);
    return () => window.removeEventListener("portal-signed-out", out);
  }, []);

  const signedIn = useCallback((me) => setSession({ status: "in", me }), []);
  const signOut = async () => {
    await portalRequest("POST", "/logout").catch(() => {});
    setSession({ status: "out", me: null });
    navigate("/kundenbereich");
  };

  if (location.pathname.startsWith("/kundenbereich/anmelden")) {
    return <Alone><Redeem onSignedIn={signedIn} /></Alone>;
  }
  if (session.status === "checking") return <div aria-busy="true" aria-label="Wird geladen" className="min-h-screen bg-page" />;
  if (session.status === "in") return <SignedIn me={session.me} onSignOut={signOut} />;
  if (session.status === "off") {
    return (
      <Alone>
        <h1 className="m-0 text-[28px] font-bold">Der Kundenbereich kommt bald</h1>
        <p className="mb-0 text-[17px] leading-normal text-body">Bis dahin siehst du den Stand deiner Anfrage über die Status-Seite.</p>
        <Link to="/?kontakt=status#kontakt" className="btn-primary mt-2">Status prüfen</Link>
      </Alone>
    );
  }
  if (session.status === "error") {
    return (
      <Alone>
        <h1 className="m-0 text-[28px] font-bold">Gerade nicht erreichbar</h1>
        <p className="mb-0 text-[17px] leading-normal text-body">Bitte versuch es in ein paar Minuten noch einmal.</p>
      </Alone>
    );
  }
  return <Alone><SignIn /></Alone>;
}

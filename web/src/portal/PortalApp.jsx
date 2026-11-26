import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { Link, Route, Routes, useLocation, useNavigate } from "react-router";
import Footer from "../components/Footer.jsx";
import Logo from "../components/Logo.jsx";
import { Field, FormError } from "../forms/fields.jsx";
import { portalRequest, usePortalData } from "./api.js";
import Inquiries from "./pages/Inquiries.jsx";
import InquiryDetail from "./pages/InquiryDetail.jsx";
import NewInquiry from "./pages/NewInquiry.jsx";
import { OfferDetail, Offers } from "./pages/Offers.jsx";
import { DocumentDetail, Documents } from "./pages/Documents.jsx";
import { InvoiceDetail } from "./pages/Invoices.jsx";
import Overview from "./pages/Overview.jsx";
import Unterlagen, { waitsForYou } from "./pages/Unterlagen.jsx";

const PortalContext = createContext(null);
export const usePortal = () => useContext(PortalContext);

const NAV = [
  { to: "/kundenbereich", label: "Übersicht", end: true },
  // The number on Anfragen: inquiries waiting for the customer's answer (#122).
  { to: "/kundenbereich/anfragen", label: "Anfragen", count: "inquiries_waiting" },
  { to: "/kundenbereich/angebote", label: "Angebote", count: "offers_open" },
  // Invoices, credit notes and every other document under one tab (#100);
  // /kundenbereich/rechnungen stays the address of the invoices from the mails.
  { to: "/kundenbereich/belege", label: "Belege", count: "invoices_open", also: "/kundenbereich/rechnungen" },
  // AGB, AVV and the like to confirm or sign (#187).
  { to: "/kundenbereich/unterlagen", label: "Unterlagen", count: "documents_waiting" },
];

function Header({ email, onSignOut }) {
  return (
    <header className="border-b border-line bg-surface">
      <div className="mx-auto flex h-[60px] max-w-[1440px] items-center gap-2.5 px-4 md:h-[72px] md:gap-6 md:px-8 lg:px-12">
        <Link to="/" aria-label="IT-Tabelander – zur Startseite" className="flex shrink-0"><Logo className="h-5 w-auto md:h-[30px]" /></Link>
        <span className="truncate border-l border-line pl-2.5 font-display text-base font-bold md:pl-6 md:text-lg">Kundenbereich</span>
        {onSignOut ? (
          <div className="ml-auto flex items-center gap-4 text-[15px]">
            <span className="hidden text-muted md:inline">Angemeldet als <b className="text-ink">{email}</b></span>
            <button type="button" onClick={onSignOut} className="btn-outline min-h-[40px] border-line px-3 text-[15px] md:px-3.5">Abmelden</button>
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

// A page of the customer area to open after signing in: the inquiry from a
// Dolibarr mail, at most with its tracking id (#122). The server checks the same.
const NEXT_PAGE = /^\/kundenbereich(\/[A-Za-z0-9_-]{1,40}){0,3}(\?track_id=[A-Za-z0-9]{8,64})?$/;

function nextPage(value) {
  return value && NEXT_PAGE.test(value) && value !== "/kundenbereich" ? value : "";
}

/** The e-mail link; whether the address is known is never said (#63). */
function SignIn() {
  const location = useLocation();
  const next = nextPage(`${location.pathname.replace(/\/$/, "")}${location.search}`);
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
      await portalRequest("POST", "/login", next ? { email: email.trim(), next } : { email: email.trim() });
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
        {next && " Danach geht es direkt zu deiner Anfrage."}
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
      .then((me) => {
        onSignedIn(me);
        navigate(nextPage(new URLSearchParams(window.location.search).get("ziel")) || "/kundenbereich", { replace: true });
      })
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
  const { pathname } = useLocation();
  // Active also below its address, and for Belege on the invoice pages (#100).
  const on = (entry) => (entry.end ? pathname.replace(/\/$/, "") === entry.to
    : [entry.to, entry.also].some((base) => base && (pathname === base || pathname.startsWith(`${base}/`))));
  const item = (active) => `flex min-h-[46px] items-center justify-between rounded-[10px] px-4 text-base font-semibold no-underline ${
    active ? "bg-navy text-on-navy hover:text-on-navy" : "text-ink"}`;
  return (
    <>
      <nav aria-label="Kundenbereich" className="hidden flex-col gap-1 border-r border-line bg-surface px-4 py-7 lg:flex">
        {NAV.map((entry) => (
          <Link key={entry.to} to={entry.to} aria-current={on(entry) ? "page" : undefined} className={item(on(entry))}>
            {entry.label}
            {entry.count && counts?.[entry.count] > 0 && (
              <span className="rounded-full bg-accent px-2 text-[13px] font-bold text-on-accent">{counts[entry.count]}</span>
            )}
          </Link>
        ))}
      </nav>
      <nav aria-label="Kundenbereich unten" className="fixed inset-x-0 bottom-0 z-40 grid h-16 grid-cols-5 border-t border-line bg-surface lg:hidden">
        {NAV.map((entry) => (
          <Link key={entry.to} to={entry.to} aria-current={on(entry) ? "page" : undefined}
            className={`flex items-center justify-center px-0.5 text-[13px] font-bold no-underline sm:text-sm ${on(entry) ? "text-link-hover" : "text-muted"}`}>
            {entry.label}
          </Link>
        ))}
      </nav>
    </>
  );
}

function SignedIn({ me, onSignOut }) {
  const overview = usePortalData("/overview");
  const documents = usePortalData("/unterlagen");
  const counts = overview.data?.counts && { ...overview.data.counts, documents_waiting: (documents.data || []).filter(waitsForYou).length };
  return (
    <PortalContext.Provider value={{ me, overview, documents }}>
      <Frame email={me.email} onSignOut={onSignOut} padded>
        <div className="mx-auto w-full max-w-[1440px] flex-1 lg:grid lg:grid-cols-[260px_minmax(0,1fr)]">
          <Nav counts={counts} />
          <main id="inhalt" className="flex min-w-0 flex-col gap-6 px-4 pb-24 pt-6 md:px-8 lg:px-14 lg:pb-14 lg:pt-10">
            <Routes>
              <Route index element={<Overview />} />
              <Route path="anfragen" element={<Inquiries />} />
              <Route path="anfragen/neu" element={<NewInquiry />} />
              <Route path="anfragen/:id" element={<InquiryDetail />} />
              <Route path="angebote" element={<Offers />} />
              <Route path="angebote/:id" element={<OfferDetail />} />
              <Route path="rechnungen" element={<Documents art="rechnungen" />} />
              <Route path="rechnungen/:id" element={<InvoiceDetail />} />
              <Route path="belege" element={<Documents />} />
              <Route path="belege/:art" element={<Documents />} />
              <Route path="belege/:art/:id" element={<DocumentDetail />} />
              <Route path="unterlagen" element={<Unterlagen />} />
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
        <p className="mb-0 text-[17px] leading-normal text-body">
          Bis dahin bekommst du alles zu deiner Anfrage per E-Mail. Hast du etwas zu ergänzen, antworte einfach darauf.
        </p>
        <Link to="/" className="btn-outline mt-2 border-line">Zur Startseite</Link>
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

import { useEffect, useRef, useState } from "react";
import { NavLink, useLocation } from "react-router";
import { CloseIcon, MenuIcon } from "../components/Icons.jsx";
import { useAdminData } from "./api.js";
import { useSession } from "./session.jsx";

export const NAV = [
  { to: "/admin", label: "Übersicht", end: true },
  { to: "/admin/texte", label: "Texte" },
  { to: "/admin/leistungen", label: "Leistungen" },
  { to: "/admin/galerie", label: "Galerie" },
  { to: "/admin/bewertungen", label: "Bewertungen" },
  { to: "/admin/dolibarr", label: "Dolibarr", badge: "waiting" },
  { to: "/admin/technik", label: "Technik" },
];

function Nav({ waiting, onNavigate }) {
  const { user, logout } = useSession();
  return (
    <div className="flex h-full flex-col gap-1">
      <nav aria-label="Verwaltung" className="flex flex-col gap-1">
        {NAV.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} onClick={onNavigate}
            className={({ isActive }) => `flex min-h-[44px] items-center justify-between rounded-lg px-3.5 text-[15px] font-semibold no-underline ${
              isActive ? "bg-surface text-ink" : "text-on-navy hover:bg-navy-line hover:text-on-navy"}`}>
            {item.label}
            {item.badge === "waiting" && waiting > 0 && (
              <span className="rounded-full bg-accent px-2 text-xs font-bold text-on-accent">{waiting} wartend</span>
            )}
          </NavLink>
        ))}
      </nav>
      <div className="mt-auto flex flex-col gap-1 border-t border-navy-line pt-3 text-sm">
        <a href="/" target="_blank" rel="noopener noreferrer" className="flex min-h-[44px] items-center px-3.5 text-on-navy-muted no-underline hover:text-on-navy">
          Website ansehen ↗
        </a>
        {user && <span className="px-3.5 text-on-navy-muted">{user.email}</span>}
        <button type="button" onClick={logout} className="flex min-h-[44px] items-center px-3.5 text-left text-on-navy-muted hover:text-on-navy">
          Abmelden
        </button>
      </div>
    </div>
  );
}

/** Sidebar on the desktop, a bar with a menu on phone and tablet (Design A). */
export default function Layout({ children }) {
  const location = useLocation();
  const [open, setOpen] = useState(false);
  const closeRef = useRef(null);
  const overview = useAdminData("/admin/dashboard");
  const waiting = overview.data?.inquiries?.waiting || 0;
  const current = NAV.find((item) => (item.end ? location.pathname === item.to : location.pathname.startsWith(item.to)));

  useEffect(() => { setOpen(false); }, [location.pathname]);
  useEffect(() => {
    if (!open) return undefined;
    closeRef.current?.focus();
    const onKey = (event) => { if (event.key === "Escape") setOpen(false); };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open]);

  return (
    <div className="min-h-screen bg-page lg:grid lg:grid-cols-[250px_minmax(0,1fr)] print:block print:min-h-0 print:bg-white">
      <a href="#inhalt" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-[60] focus:rounded focus:bg-surface focus:p-3">
        Zum Inhalt springen
      </a>
      <aside className="sticky top-0 hidden h-screen flex-col gap-6 bg-navy px-3.5 py-6 lg:flex print:!hidden">
        <img src="/brand/banner-on-dark.webp" alt="IT-Tabelander" width="651" height="136" className="mx-2.5 h-[26px] w-auto self-start" />
        <Nav waiting={waiting} />
      </aside>
      <header className="sticky top-0 z-40 flex h-[60px] items-center gap-3 bg-navy px-4 text-on-navy lg:hidden print:!hidden">
        <img src="/brand/banner-on-dark.webp" alt="IT-Tabelander" width="651" height="136" className="h-5 w-auto" />
        <span className="truncate font-display text-[17px] font-bold">{current?.label || "Verwaltung"}</span>
        <button type="button" onClick={() => setOpen(true)} aria-label="Menü öffnen" aria-expanded={open}
          className="ml-auto flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-on-navy">
          <MenuIcon />
        </button>
      </header>
      {open && (
        <div role="dialog" aria-modal="true" aria-label="Menü" className="fixed inset-0 z-50 flex flex-col bg-navy px-4 pb-5 text-on-navy lg:hidden">
          <div className="flex h-[60px] shrink-0 items-center">
            <span className="font-display text-[17px] font-bold">Verwaltung</span>
            <button ref={closeRef} type="button" onClick={() => setOpen(false)} aria-label="Menü schließen"
              className="ml-auto flex h-11 w-11 items-center justify-center rounded-lg text-on-navy">
              <CloseIcon />
            </button>
          </div>
          <Nav waiting={waiting} onNavigate={() => setOpen(false)} />
        </div>
      )}
      <main id="inhalt" className="mx-auto flex w-full max-w-[1200px] flex-col gap-6 px-4 py-6 md:px-8 lg:px-12 lg:py-9 print:!m-0 print:!block print:!max-w-none print:!p-0">
        {children}
      </main>
    </div>
  );
}

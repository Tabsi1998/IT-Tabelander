import { useEffect, useRef, useState } from "react";
import { Link } from "react-router";
import { ContactLink } from "../lib/contact.jsx";
import { telHref, useSite } from "../lib/site.jsx";
import { CloseIcon, MenuIcon } from "./Icons.jsx";
import Logo from "./Logo.jsx";

/** Header of the one-pager: section links on the desktop, a full-screen menu
 *  on the phone. `sections` lists only the sections that are shown. */
export default function Header({ sections }) {
  const [open, setOpen] = useState(false);
  const { settings } = useSite();

  return (
    <header className="sticky top-0 z-40 border-b border-line bg-surface">
      <div className="mx-auto flex h-16 max-w-page items-center gap-2 px-4 md:h-[84px] md:gap-9 md:px-6">
        <Link to="/" aria-label="IT-Tabelander – zur Startseite" className="flex shrink-0">
          <Logo className="h-6 w-auto md:h-[34px]" />
        </Link>
        <nav aria-label="Bereiche" className="ml-5 hidden gap-6 text-base font-semibold lg:flex">
          {sections.map((section) => (
            section.key === "kontakt"
              ? <ContactLink key={section.key} tab="nachricht" className="no-underline">{section.label}</ContactLink>
              : <a key={section.key} href={`/#${section.key}`} className="no-underline">{section.label}</a>
          ))}
        </nav>
        <div className="ml-auto hidden items-center gap-3.5 md:flex">
          {settings.portal_enabled && <Link to="/kundenbereich" className="whitespace-nowrap px-1 text-[15px] font-semibold no-underline">Kundenbereich</Link>}
          <ContactLink tab="status" className="btn-outline min-h-[44px] px-[18px] text-[15px]">Status prüfen</ContactLink>
          <ContactLink tab="anfrage" className="btn-primary min-h-[44px] px-5 text-[15px]">Anfrage starten</ContactLink>
        </div>
        <ContactLink tab="anfrage" className="btn-primary ml-auto min-h-[44px] px-3.5 text-[15px] md:hidden">Anfrage</ContactLink>
        <button
          type="button"
          onClick={() => setOpen(true)}
          aria-label="Menü öffnen"
          aria-expanded={open}
          aria-controls="hauptmenue"
          className="flex h-11 w-11 items-center justify-center rounded-lg border-[1.5px] border-line text-ink lg:hidden"
        >
          <MenuIcon />
        </button>
      </div>
      {open && <MobileMenu sections={sections} onClose={() => setOpen(false)} />}
    </header>
  );
}

function MobileMenu({ sections, onClose }) {
  const { company, settings } = useSite();
  const closeRef = useRef(null);

  useEffect(() => {
    closeRef.current?.focus();
    const onKey = (event) => { if (event.key === "Escape") onClose(); };
    document.addEventListener("keydown", onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = overflow;
    };
  }, [onClose]);

  const itemClass = "flex min-h-[56px] items-center justify-between border-b border-line-soft font-display text-xl font-bold no-underline";
  return (
    <div id="hauptmenue" role="dialog" aria-modal="true" aria-label="Menü" className="fixed inset-0 z-50 flex flex-col overflow-y-auto bg-surface">
      <div className="flex h-16 shrink-0 items-center border-b border-line px-4">
        <Logo className="h-6 w-auto" />
        <button ref={closeRef} type="button" onClick={onClose} aria-label="Menü schließen"
          className="ml-auto flex h-11 w-11 items-center justify-center rounded-lg border-[1.5px] border-line text-ink">
          <CloseIcon />
        </button>
      </div>
      <nav aria-label="Hauptmenü" className="flex flex-col px-5 py-3">
        {sections.map((section) => (
          section.key === "kontakt"
            ? <ContactLink key={section.key} tab="nachricht" onNavigate={onClose} className={itemClass}>{section.label}<span aria-hidden="true" className="text-link-hover">›</span></ContactLink>
            : <a key={section.key} href={`/#${section.key}`} onClick={onClose} className={itemClass}>{section.label}<span aria-hidden="true" className="text-link-hover">›</span></a>
        ))}
      </nav>
      <div className="flex flex-col gap-2.5 px-5 py-3">
        <ContactLink tab="status" onNavigate={onClose} className="btn-outline min-h-[52px] text-[17px]">Status prüfen</ContactLink>
        {settings.portal_enabled && <Link to="/kundenbereich" onClick={onClose} className="btn-outline min-h-[52px] text-[17px]">Kundenbereich</Link>}
        <ContactLink tab="anfrage" onNavigate={onClose} className="btn-primary min-h-[54px] text-[17px]">Anfrage starten</ContactLink>
      </div>
      {company && (company.phone || company.email) && (
        <div className="mt-auto flex flex-col gap-1 bg-page p-5 text-[15px] text-body">
          {company.phone && <a href={telHref(company.phone)} className="font-bold">{company.phone}</a>}
          {company.email && <a href={`mailto:${company.email}`}>{company.email}</a>}
        </div>
      )}
    </div>
  );
}

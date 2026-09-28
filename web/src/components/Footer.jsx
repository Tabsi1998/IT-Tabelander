import { Link } from "react-router";
import { ContactLink } from "../lib/contact.jsx";
import { telHref, useSite } from "../lib/site.jsx";
import Logo from "./Logo.jsx";

export default function Footer({ onHome = true }) {
  const { company, settings } = useSite();
  const name = company?.name || "IT-Tabelander";
  const place = [company?.town, "Tirol"].filter(Boolean).join(" · ");
  const customerLink = (tab, label) => (onHome
    ? <ContactLink tab={tab} className="flex min-h-[44px] items-center text-on-navy-muted no-underline md:min-h-0">{label}</ContactLink>
    : <Link to="/#kontakt" className="flex min-h-[44px] items-center text-on-navy-muted no-underline md:min-h-0">{label}</Link>);
  const legal = (kind, label) => (
    <Link to={`/rechtliches/${kind}`} className="flex min-h-[44px] items-center text-on-navy-muted no-underline md:min-h-0">{label}</Link>
  );

  return (
    <footer className="bg-navy text-on-navy-muted">
      <div className="mx-auto flex max-w-page flex-col gap-8 px-5 pb-8 pt-10 md:gap-10 md:px-6 md:pb-9 md:pt-16">
        <div className="grid gap-7 md:grid-cols-[1.4fr_1fr_1fr_1fr] md:gap-10">
          <div className="flex flex-col gap-4">
            <Logo variant="on-navy" className="h-[26px] w-auto self-start md:h-8" />
            <p className="m-0 max-w-[26em] text-[15px] leading-normal">
              IT-Service, Reparatur und PCs nach Wunsch aus Tirol, für ganz Österreich.
            </p>
          </div>
          <div className="flex flex-col gap-1 text-base md:gap-2.5 md:text-[15px]">
            <b className="mb-1.5 text-on-navy">Kontakt</b>
            {company?.email && <a href={`mailto:${company.email}`} className="flex min-h-[44px] items-center text-on-navy no-underline md:min-h-0 md:text-on-navy-muted">{company.email}</a>}
            {company?.phone && <a href={telHref(company.phone)} className="flex min-h-[44px] items-center text-on-navy no-underline md:min-h-0 md:text-on-navy-muted">{company.phone}</a>}
            <span>{place}</span>
          </div>
          <nav aria-label="Für Kunden" className="flex flex-col gap-0 text-base md:gap-2.5 md:text-[15px]">
            <b className="mb-1.5 text-on-navy">Für Kunden</b>
            {customerLink("anfrage", "Anfrage starten")}
            {customerLink("status", "Status prüfen")}
            {settings.portal_enabled && (
              <Link to="/kundenbereich" className="flex min-h-[44px] items-center text-on-navy-muted no-underline md:min-h-0">Kundenbereich</Link>
            )}
          </nav>
          <nav aria-label="Rechtliches" className="flex flex-col gap-0 text-base md:gap-2.5 md:text-[15px]">
            <b className="mb-1.5 text-on-navy">Rechtliches</b>
            {legal("impressum", "Impressum")}
            {legal("datenschutz", "Datenschutz")}
            {legal("nutzungsbedingungen", "Nutzungsbedingungen")}
          </nav>
        </div>
        <div className="flex flex-col gap-1 border-t border-navy-line pt-5 text-sm text-on-navy-muted md:flex-row md:justify-between">
          <span>© {new Date().getFullYear()} {name}</span>
          <span>CompTIA A+ zertifiziert · Ausbildung am WIFI Tirol</span>
        </div>
      </div>
    </footer>
  );
}

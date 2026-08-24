import React from "react";
import { Link } from "react-router-dom";
import { Mail, Phone, MapPin, ShieldCheck } from "lucide-react";
import { useSettings } from "../context/SettingsContext";

export function Footer() {
  const { settings } = useSettings();
  const year = new Date().getFullYear();

  return (
    <footer className="relative mt-24 border-t border-subtle bg-[#070d18] text-slate-300">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 md:grid-cols-4 lg:px-8">
        <div className="md:col-span-2">
          <img src="/assets/img/logo/banner-light.png" alt="IT-Tabelander" className="h-9" />
          <p className="mt-4 max-w-md text-sm leading-relaxed text-slate-400">
            {settings.tagline || "IT-Technik, die funktioniert."} PCs, Notebooks, Konsolen und
            Gaming-Hardware – Reparatur, Aufrüstung und individuelle Systeme.
          </p>
          <div className="mt-5 flex flex-wrap items-center gap-3">
            <img src="/assets/img/certs/comptia-aplus-white.png" alt="CompTIA A+ Certified" className="h-14" />
            <span className="inline-flex items-center gap-1.5 rounded-full border border-white/10 px-3 py-1 text-xs text-slate-400">
              <ShieldCheck size={14} className="text-brand" /> WIFI Tirol Ausbildung
            </span>
          </div>
        </div>

        <div>
          <h4 className="mb-4 font-heading text-sm font-semibold uppercase tracking-wider text-white">
            Leistungen
          </h4>
          <ul className="space-y-2.5 text-sm text-slate-400">
            <li><Link to="/pc-reparatur" className="hover:text-brand">PC-Reparatur</Link></li>
            <li><Link to="/notebook-reparatur" className="hover:text-brand">Notebook-Reparatur</Link></li>
            <li><Link to="/pc-aufruestung" className="hover:text-brand">PC-Aufrüstung</Link></li>
            <li><Link to="/konsolen-reparatur" className="hover:text-brand">Konsolen-Reparatur</Link></li>
            <li><Link to="/controller-reparatur" className="hover:text-brand">Controller-Reparatur</Link></li>
            <li><Link to="/gaming-pc-konfigurator" className="hover:text-brand">Gaming-PC konfigurieren</Link></li>
          </ul>
        </div>

        <div>
          <h4 className="mb-4 font-heading text-sm font-semibold uppercase tracking-wider text-white">
            Kontakt
          </h4>
          <ul className="space-y-2.5 text-sm text-slate-400">
            {settings.email && (
              <li className="flex items-center gap-2">
                <Mail size={15} className="text-brand" />
                <a href={`mailto:${settings.email}`} className="hover:text-brand">{settings.email}</a>
              </li>
            )}
            {settings.phone && (
              <li className="flex items-center gap-2">
                <Phone size={15} className="text-brand" />
                <a href={`tel:${settings.phone}`} className="hover:text-brand">{settings.phone}</a>
              </li>
            )}
            <li className="flex items-center gap-2">
              <MapPin size={15} className="text-brand" />
              {settings.service_area || "Tirol & Österreich"}
            </li>
          </ul>
          {!settings.email && !settings.phone && (
            <p className="mt-2 text-xs text-slate-500">
              Kontaktdaten werden im Admin-Bereich gepflegt.
            </p>
          )}
        </div>
      </div>

      <div className="border-t border-white/5">
        <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-4 py-6 text-xs text-slate-500 sm:flex-row sm:px-6 lg:px-8">
          <p>© {year} IT-Tabelander. Alle Rechte vorbehalten.</p>
          <div className="flex items-center gap-4">
            <Link to="/impressum" className="hover:text-brand">Impressum</Link>
            <Link to="/datenschutz" className="hover:text-brand">Datenschutz</Link>
            <Link to="/datenschutz#cookies" className="hover:text-brand">Cookie-Einstellungen</Link>
            <Link to="/admin" className="text-slate-600 hover:text-slate-400" data-testid="footer-admin-link" aria-label="Admin">
              •
            </Link>
          </div>
        </div>
      </div>
    </footer>
  );
}

export default Footer;

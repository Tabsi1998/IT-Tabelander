import React, { useState } from "react";
import { Link, NavLink, useNavigate } from "react-router-dom";
import {
  LayoutDashboard, Wrench, Mail, Star, Layers, Cpu, Settings, RefreshCw, Gamepad2,
  Image as ImageIcon, HelpCircle, LogOut, Menu, X, ExternalLink,
} from "lucide-react";
import { useAuth } from "../../context/AuthContext";
import Logo from "../../components/Logo";

const NAV = [
  { to: "/admin", label: "Dashboard", icon: LayoutDashboard, end: true },
  { to: "/admin/reparaturen", label: "Reparaturen", icon: Wrench },
  { to: "/admin/kontakt", label: "Nachrichten", icon: Mail },
  { to: "/admin/leistungen", label: "Leistungen", icon: Layers },
  { to: "/admin/faqs", label: "FAQs", icon: HelpCircle },
  { to: "/admin/bewertungen", label: "Bewertungen", icon: Star },
  { to: "/admin/konfigurator", label: "PC Builder", icon: Cpu },
  { to: "/admin/controller-builder", label: "Controller Builder", icon: Gamepad2 },
  { to: "/admin/medien", label: "Medien", icon: ImageIcon },
  { to: "/admin/dolibarr", label: "Dolibarr Sync", icon: RefreshCw },
  { to: "/admin/einstellungen", label: "Einstellungen", icon: Settings },
];

const NavItems = ({ onNavigate }) => (
  <nav className="flex flex-col gap-1">
    {NAV.map((n) => (
      <NavLink
        key={n.to}
        to={n.to}
        end={n.end}
        onClick={onNavigate}
        className={({ isActive }) =>
          `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
            isActive ? "bg-brand/15 text-brand" : "text-muted hover:bg-elevated hover:text-ink"
          }`
        }
        data-testid={`admin-nav-${n.to.replace("/admin", "").replace("/", "") || "dashboard"}`}
      >
        <n.icon size={17} /> {n.label}
      </NavLink>
    ))}
  </nav>
);

export default function AdminLayout({ children }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);

  const doLogout = async () => {
    await logout();
    navigate("/admin");
  };

  return (
    <div className="min-h-screen bg-canvas text-ink">
      {/* mobile top bar */}
      <div className="flex items-center justify-between border-b border-subtle px-4 py-3 lg:hidden">
        <Logo variant="banner" className="h-7" />
        <button onClick={() => setOpen(true)} data-testid="admin-mobile-menu"><Menu size={22} /></button>
      </div>

      <div className="flex">
        {/* sidebar desktop */}
        <aside className="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-subtle bg-surface p-4 lg:flex">
          <Link to="/admin" className="mb-6 mt-2 block"><Logo variant="banner" className="h-8" /></Link>
          <NavItems onNavigate={() => setOpen(false)} />
          <div className="mt-auto space-y-2 border-t border-subtle pt-4">
            <a href="/" target="_blank" rel="noreferrer" className="flex items-center gap-2 px-3 text-xs text-faint hover:text-brand">
              <ExternalLink size={13} /> Website ansehen
            </a>
            <div className="px-3 text-xs text-faint">{user?.email}</div>
            <button onClick={doLogout} className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted hover:bg-elevated hover:text-red-400" data-testid="admin-logout">
              <LogOut size={16} /> Abmelden
            </button>
          </div>
        </aside>

        {/* mobile drawer */}
        {open && (
          <div className="fixed inset-0 z-50 lg:hidden">
            <div className="absolute inset-0 bg-black/70" onClick={() => setOpen(false)} />
            <div className="absolute left-0 top-0 h-full w-72 bg-surface p-4">
              <div className="mb-6 flex items-center justify-between">
                <Logo variant="banner" className="h-7" />
                <button onClick={() => setOpen(false)}><X size={20} /></button>
              </div>
              <NavItems onNavigate={() => setOpen(false)} />
              <button onClick={doLogout} className="mt-6 flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-red-400"><LogOut size={16} /> Abmelden</button>
            </div>
          </div>
        )}

        <main className="min-w-0 flex-1 p-4 md:p-8">{children}</main>
      </div>
    </div>
  );
}

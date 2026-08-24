import React, { useEffect, useState } from "react";
import { Link, NavLink, useLocation } from "react-router-dom";
import { Menu, X, ChevronDown, Wrench } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import Logo from "./Logo";
import ThemeToggle from "./ThemeToggle";
import { Button } from "./ui/button";

const MAIN = [
  { label: "Leistungen", to: "/leistungen" },
  { label: "PC Builder", to: "/gaming-pc-konfigurator" },
  { label: "PS5 Controller", to: "/ps5-controller-konfigurator" },
  { label: "Über mich", to: "/ueber-mich" },
  { label: "Bewertungen", to: "/bewertungen" },
  { label: "Kontakt", to: "/kontakt" },
];

const MORE = [
  { label: "PC-Reparatur", to: "/pc-reparatur" },
  { label: "Notebook-Reparatur", to: "/notebook-reparatur" },
  { label: "PC- & Notebook-Upgrades", to: "/pc-aufruestung" },
  { label: "Konsolen-Reparatur", to: "/konsolen-reparatur" },
  { label: "Controller-Reparatur", to: "/controller-reparatur" },
  { label: "PC Builder (Info)", to: "/gaming-pc" },
];

export function Header() {
  const [scrolled, setScrolled] = useState(false);
  const [mobile, setMobile] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const location = useLocation();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 12);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    setMobile(false);
    setMoreOpen(false);
  }, [location.pathname]);

  const linkClass = ({ isActive }) =>
    `px-3 py-2 text-sm font-medium rounded-lg transition-colors ${
      isActive ? "text-brand" : "text-muted hover:text-ink"
    }`;

  return (
    <header
      className={`fixed inset-x-0 top-0 z-50 transition-all duration-300 ${
        scrolled ? "glass border-b border-subtle" : "bg-transparent"
      }`}
    >
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <Link to="/" className="flex items-center" data-testid="logo-home-link">
          <Logo variant="banner" className="h-8 md:h-9" />
        </Link>

        <nav className="hidden items-center gap-1 lg:flex">
          {MAIN.map((l) => (
            <NavLink key={l.to} to={l.to} className={linkClass} data-testid={`nav-${l.to.slice(1)}`}>
              {l.label}
            </NavLink>
          ))}
          <div
            className="relative"
            onMouseEnter={() => setMoreOpen(true)}
            onMouseLeave={() => setMoreOpen(false)}
          >
            <button
              className="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-muted transition-colors hover:text-ink"
              data-testid="nav-more"
            >
              Mehr <ChevronDown size={15} />
            </button>
            <AnimatePresence>
              {moreOpen && (
                <motion.div
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: 8 }}
                  className="absolute right-0 top-full w-64 pt-2"
                >
                  <div className="glass overflow-hidden rounded-xl border border-subtle shadow-card">
                    {MORE.map((l) => (
                      <Link
                        key={l.to}
                        to={l.to}
                        className="block px-4 py-2.5 text-sm text-muted transition-colors hover:bg-elevated hover:text-brand"
                        data-testid={`nav-more-${l.to.slice(1)}`}
                      >
                        {l.label}
                      </Link>
                    ))}
                  </div>
                </motion.div>
              )}
            </AnimatePresence>
          </div>
        </nav>

        <div className="flex items-center gap-2">
          <ThemeToggle />
          <Button as={Link} to="/reparatur" size="sm" className="hidden sm:inline-flex" data-testid="header-cta-repair">
            <Wrench size={16} /> Reparatur anfragen
          </Button>
          <button
            className="rounded-xl border border-subtle p-2 text-ink lg:hidden"
            onClick={() => setMobile(true)}
            aria-label="Menü öffnen"
            data-testid="mobile-menu-open"
          >
            <Menu size={20} />
          </button>
        </div>
      </div>

      <AnimatePresence>
        {mobile && (
          <motion.div
            className="fixed inset-0 z-50 lg:hidden"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
          >
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setMobile(false)} />
            <motion.div
              className="absolute right-0 top-0 h-full w-[85%] max-w-sm glass border-l border-subtle p-6"
              initial={{ x: "100%" }}
              animate={{ x: 0 }}
              exit={{ x: "100%" }}
              transition={{ type: "spring", damping: 30, stiffness: 320 }}
            >
              <div className="mb-6 flex items-center justify-between">
                <Logo variant="banner" className="h-8" />
                <button onClick={() => setMobile(false)} aria-label="Menü schließen" data-testid="mobile-menu-close">
                  <X size={22} className="text-ink" />
                </button>
              </div>
              <nav className="flex flex-col gap-1">
                {[...MAIN, ...MORE].map((l) => (
                  <NavLink
                    key={l.to}
                    to={l.to}
                    className="rounded-lg px-3 py-3 text-base font-medium text-muted transition-colors hover:bg-elevated hover:text-brand"
                    data-testid={`mobile-nav-${l.to.slice(1)}`}
                  >
                    {l.label}
                  </NavLink>
                ))}
              </nav>
              <Button as={Link} to="/reparatur" className="mt-6 w-full" data-testid="mobile-cta-repair">
                <Wrench size={18} /> Reparatur anfragen
              </Button>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </header>
  );
}

export default Header;

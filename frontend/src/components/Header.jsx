import React, { useEffect, useRef, useState } from "react";
import { Link, NavLink, useLocation } from "react-router-dom";
import { Menu, X, ChevronDown, Send } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import Logo from "./Logo";
import ThemeToggle from "./ThemeToggle";
import { Button } from "./ui/button";

const MAIN = [
  { label: "Leistungen", to: "/leistungen" },
  { label: "Gaming-PC", to: "/gaming-pc" },
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
  { label: "Anfrage senden", to: "/anfrage" },
];

export function Header() {
  const [scrolled, setScrolled] = useState(false);
  const [mobile, setMobile] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const moreContainerRef = useRef(null);
  const moreButtonRef = useRef(null);
  const mobilePanelRef = useRef(null);
  const mobileCloseRef = useRef(null);
  const location = useLocation();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 12);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    setMobile(false);
    setMoreOpen(false);
  }, [location.pathname, location.search]);

  useEffect(() => {
    if (!moreOpen) return undefined;
    const closeOutside = (event) => {
      if (!moreContainerRef.current?.contains(event.target)) setMoreOpen(false);
    };
    document.addEventListener("pointerdown", closeOutside);
    return () => document.removeEventListener("pointerdown", closeOutside);
  }, [moreOpen]);

  useEffect(() => {
    if (!mobile) return undefined;
    const previouslyFocused = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.requestAnimationFrame(() => mobileCloseRef.current?.focus());

    const handleKeyDown = (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        setMobile(false);
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = Array.from(mobilePanelRef.current?.querySelectorAll(
        'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
      ) || []);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      document.body.style.overflow = previousOverflow;
      previouslyFocused?.focus?.();
    };
  }, [mobile]);

  const linkClass = ({ isActive }) =>
    `px-3 py-2 text-sm font-medium rounded-lg transition-colors ${
      isActive ? "text-brand" : "text-muted hover:text-ink"
    }`;
  const mainLinks = MAIN;
  const moreLinks = MORE;

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
          {mainLinks.map((l) => (
            <NavLink key={l.to} to={l.to} className={linkClass} data-testid={`nav-${l.to.slice(1)}`}>
              {l.label}
            </NavLink>
          ))}
          <div
            ref={moreContainerRef}
            className="relative"
            onBlur={(event) => {
              if (!event.currentTarget.contains(event.relatedTarget)) setMoreOpen(false);
            }}
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                event.preventDefault();
                setMoreOpen(false);
                moreButtonRef.current?.focus();
              }
            }}
          >
            <button
              ref={moreButtonRef}
              type="button"
              onClick={() => setMoreOpen((open) => !open)}
              className="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-muted transition-colors hover:text-ink"
              data-testid="nav-more"
              aria-haspopup="true"
              aria-expanded={moreOpen}
              aria-controls="main-more-menu"
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
                  <div id="main-more-menu" className="glass overflow-hidden rounded-xl border border-subtle shadow-card">
                    {moreLinks.map((l) => (
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
          <Button as={Link} to="/anfrage" size="sm" className="hidden sm:inline-flex" data-testid="header-cta-inquiry">
            <Send size={16} /> Anfrage starten
          </Button>
          <button
            type="button"
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
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setMobile(false)} aria-hidden="true" />
            <motion.div
              ref={mobilePanelRef}
              role="dialog"
              aria-modal="true"
              aria-label="Hauptmenü"
              className="absolute right-0 top-0 h-full w-[85%] max-w-sm overflow-y-auto glass border-l border-subtle p-6"
              initial={{ x: "100%" }}
              animate={{ x: 0 }}
              exit={{ x: "100%" }}
              transition={{ type: "spring", damping: 30, stiffness: 320 }}
            >
              <div className="mb-6 flex items-center justify-between">
                <Logo variant="banner" className="h-8" />
                <button ref={mobileCloseRef} type="button" onClick={() => setMobile(false)} aria-label="Menü schließen" data-testid="mobile-menu-close">
                  <X size={22} className="text-ink" />
                </button>
              </div>
              <nav className="flex flex-col gap-1">
                {[...mainLinks, ...moreLinks].map((l) => (
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
              <Button as={Link} to="/anfrage" className="mt-6 w-full" data-testid="mobile-cta-inquiry">
                <Send size={18} /> Anfrage starten
              </Button>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </header>
  );
}

export default Header;

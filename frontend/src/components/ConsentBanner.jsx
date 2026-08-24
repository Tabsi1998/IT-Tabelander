import React from "react";
import { Link } from "react-router-dom";
import { Cookie } from "lucide-react";
import { useConsent } from "../context/ConsentContext";
import { Button } from "./ui/button";

export function ConsentBanner() {
  const { hasChoice, acceptAll, rejectAll } = useConsent();
  if (hasChoice) return null;
  return (
    <div className="fixed inset-x-0 bottom-0 z-[90] p-4" data-testid="consent-banner">
      <div className="glass mx-auto max-w-4xl rounded-2xl border border-subtle p-5 shadow-card">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="flex gap-3">
            <Cookie className="mt-0.5 h-6 w-6 shrink-0 text-brand" />
            <p className="text-sm leading-relaxed text-muted">
              Wir verwenden nur notwendige Cookies. Statistik-Dienste (z. B. Google Analytics) werden
              ausschließlich mit deiner Zustimmung geladen. Details in der{" "}
              <Link to="/datenschutz" className="text-brand underline">Datenschutzerklärung</Link>.
            </p>
          </div>
          <div className="flex shrink-0 gap-2">
            <Button variant="outline" size="sm" onClick={rejectAll} data-testid="consent-reject">
              Nur notwendige
            </Button>
            <Button size="sm" onClick={acceptAll} data-testid="consent-accept">
              Alle akzeptieren
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default ConsentBanner;

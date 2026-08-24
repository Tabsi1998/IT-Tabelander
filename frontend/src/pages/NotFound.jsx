import React from "react";
import { Link } from "react-router-dom";
import { Home } from "lucide-react";
import { Button } from "../components/ui/button";
import Seo from "../components/Seo";

export default function NotFound() {
  return (
    <>
      <Seo title="Seite nicht gefunden" path="/404" />
      <section className="mx-auto flex min-h-[70vh] max-w-xl flex-col items-center justify-center px-4 text-center">
        <p className="font-mono text-7xl font-bold text-brand">404</p>
        <h1 className="mt-4 font-heading text-2xl font-bold text-ink">Seite nicht gefunden</h1>
        <p className="mt-2 text-muted">Die gewünschte Seite existiert nicht oder wurde verschoben.</p>
        <Button as={Link} to="/" className="mt-8" data-testid="notfound-home">
          <Home size={18} /> Zur Startseite
        </Button>
      </section>
    </>
  );
}

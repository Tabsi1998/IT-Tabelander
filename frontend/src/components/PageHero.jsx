import React from "react";
import { cn } from "../lib/utils";

export function PageHero({ eyebrow, title, subtitle, breadcrumbs, children }) {
  return (
    <section className="relative overflow-hidden border-b border-subtle pt-28 pb-14 md:pt-32 md:pb-16">
      <div className="pointer-events-none absolute inset-0 bg-grid-faint bg-[size:44px_44px] opacity-30" />
      <div className="pointer-events-none absolute -top-32 right-10 h-96 w-96 rounded-full bg-brand/10 blur-[110px]" />
      <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {breadcrumbs && (
          <nav className="mb-4 flex flex-wrap items-center gap-2 text-xs text-faint" aria-label="Breadcrumb">
            {breadcrumbs.map((b, i) => (
              <span key={i} className="flex items-center gap-2">
                {b.to ? (
                  <a href={b.to} className="hover:text-brand">{b.name}</a>
                ) : (
                  <span className="text-muted">{b.name}</span>
                )}
                {i < breadcrumbs.length - 1 && <span>/</span>}
              </span>
            ))}
          </nav>
        )}
        {eyebrow && (
          <p className="mb-3 font-mono text-xs uppercase tracking-[0.25em] text-brand">{eyebrow}</p>
        )}
        <h1 className={cn("font-heading text-3xl font-extrabold tracking-tight text-ink sm:text-4xl lg:text-5xl")}>
          {title}
        </h1>
        {subtitle && <p className="mt-4 max-w-2xl text-lg text-muted">{subtitle}</p>}
        {children}
      </div>
    </section>
  );
}

export default PageHero;

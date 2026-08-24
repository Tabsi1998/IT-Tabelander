import React from "react";
import { cn } from "../../lib/utils";

export function AdminHeader({ title, desc, action }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="font-heading text-2xl font-bold text-white">{title}</h1>
        {desc && <p className="mt-1 text-sm text-slate-400">{desc}</p>}
      </div>
      {action}
    </div>
  );
}

export function Panel({ className, children }) {
  return <div className={cn("rounded-2xl border border-subtle bg-[#0b1220] p-5 md:p-6", className)}>{children}</div>;
}

export function Empty({ children }) {
  return <div className="rounded-xl border border-dashed border-subtle p-10 text-center text-sm text-slate-500">{children}</div>;
}

export function Field({ label, children }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400">{label}</span>
      {children}
    </label>
  );
}

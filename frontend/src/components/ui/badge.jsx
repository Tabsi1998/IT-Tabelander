import React from "react";
import { cn } from "../../lib/utils";

const tones = {
  brand: "bg-brand/15 text-brand border-brand/30",
  neutral: "bg-elevated text-muted border-subtle",
  success: "bg-emerald-500/15 text-emerald-400 border-emerald-500/30",
  warning: "bg-amber-500/15 text-amber-400 border-amber-500/30",
  demo: "bg-sky-500/15 text-sky-400 border-sky-500/30",
};

export function Badge({ tone = "neutral", className, children }) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium",
        tones[tone],
        className
      )}
    >
      {children}
    </span>
  );
}

export default Badge;

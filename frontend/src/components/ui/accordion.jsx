import React, { useState } from "react";
import { ChevronDown } from "lucide-react";
import { cn } from "../../lib/utils";

export function Accordion({ items, className }) {
  const [open, setOpen] = useState(null);
  return (
    <div className={cn("divide-y divide-subtle rounded-2xl border border-subtle glass", className)}>
      {items.map((item, i) => (
        <div key={i} data-testid={`faq-item-${i}`}>
          <button
            type="button"
            onClick={() => setOpen(open === i ? null : i)}
            className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left transition-colors hover:bg-elevated/50"
            aria-expanded={open === i}
            data-testid={`faq-toggle-${i}`}
          >
            <span className="font-heading text-base font-semibold text-ink">{item.question}</span>
            <ChevronDown
              className={cn(
                "h-5 w-5 shrink-0 text-brand transition-transform duration-300",
                open === i && "rotate-180"
              )}
            />
          </button>
          <div
            className={cn(
              "grid overflow-hidden px-6 transition-all duration-300",
              open === i ? "grid-rows-[1fr] pb-5 opacity-100" : "grid-rows-[0fr] opacity-0"
            )}
          >
            <div className="min-h-0 text-muted leading-relaxed">{item.answer}</div>
          </div>
        </div>
      ))}
    </div>
  );
}

export default Accordion;

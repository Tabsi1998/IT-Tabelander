import React from "react";
import { cn } from "../../lib/utils";

const base =
  "w-full rounded-xl bg-elevated/60 border border-subtle px-4 py-3 text-ink placeholder:text-faint outline-none transition-colors focus:border-brand/70 focus:bg-elevated";

export const Input = React.forwardRef(({ className, ...props }, ref) => (
  <input ref={ref} className={cn(base, className)} {...props} />
));
Input.displayName = "Input";

export const Textarea = React.forwardRef(({ className, ...props }, ref) => (
  <textarea ref={ref} className={cn(base, "min-h-[120px] resize-y", className)} {...props} />
));
Textarea.displayName = "Textarea";

export const Select = React.forwardRef(({ className, children, ...props }, ref) => (
  <select ref={ref} className={cn(base, "appearance-none", className)} {...props}>
    {children}
  </select>
));
Select.displayName = "Select";

export function Label({ className, children, ...props }) {
  return (
    <label className={cn("mb-1.5 block text-sm font-medium text-muted", className)} {...props}>
      {children}
    </label>
  );
}

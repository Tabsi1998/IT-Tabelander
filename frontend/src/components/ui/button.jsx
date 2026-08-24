import React from "react";
import { cn } from "../../lib/utils";

const variants = {
  primary:
    "bg-brand text-white hover:bg-brand-bright shadow-glow hover:shadow-[0_0_40px_rgba(242,101,34,0.5)]",
  secondary:
    "bg-transparent border border-brand/50 text-ink hover:border-brand hover:bg-brand/10",
  ghost: "bg-transparent text-muted hover:text-ink hover:bg-elevated",
  outline: "border border-subtle text-ink hover:border-brand/60 hover:bg-elevated",
  danger: "bg-red-600 text-white hover:bg-red-500",
  navy: "bg-brand-navy text-white hover:bg-[#1a2942] border border-white/5",
};

const sizes = {
  sm: "px-3 py-1.5 text-sm rounded-lg",
  md: "px-5 py-2.5 text-sm rounded-xl",
  lg: "px-7 py-3.5 text-base rounded-xl",
};

export const Button = React.forwardRef(
  ({ className, variant = "primary", size = "md", as: Comp = "button", ...props }, ref) => (
    <Comp
      ref={ref}
      className={cn(
        "inline-flex items-center justify-center gap-2 font-semibold tracking-tight transition-all duration-200 active:scale-[0.97] disabled:opacity-50 disabled:pointer-events-none",
        variants[variant],
        sizes[size],
        className
      )}
      {...props}
    />
  )
);
Button.displayName = "Button";

export default Button;

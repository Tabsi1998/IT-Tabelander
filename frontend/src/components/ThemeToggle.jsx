import React from "react";
import { Moon, Sun } from "lucide-react";
import { useTheme } from "../context/ThemeContext";

export function ThemeToggle({ className = "" }) {
  const { isDark, toggle } = useTheme();
  return (
    <button
      onClick={toggle}
      aria-label={isDark ? "Zu hellem Modus wechseln" : "Zu dunklem Modus wechseln"}
      className={`relative flex h-9 w-9 items-center justify-center rounded-xl border border-subtle text-muted transition-all hover:border-brand/60 hover:text-brand ${className}`}
      data-testid="theme-toggle"
    >
      {isDark ? <Sun className="h-4.5 w-4.5" size={18} /> : <Moon size={18} />}
    </button>
  );
}

export default ThemeToggle;

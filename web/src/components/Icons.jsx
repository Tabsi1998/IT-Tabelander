// Small line icons from Design A; decorative, so hidden from screen readers.
const base = {
  fill: "none", stroke: "currentColor", strokeLinecap: "round", strokeLinejoin: "round", "aria-hidden": true,
};

export function ArrowRight({ size = 20 }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" strokeWidth="2.2" {...base}><path d="M5 12h14M13 6l6 6-6 6" /></svg>;
}

export function Check({ size = 20, className = "" }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" strokeWidth="2.4" className={className} {...base}><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>;
}

export function MenuIcon() {
  return <svg width="22" height="22" viewBox="0 0 24 24" strokeWidth="2.2" {...base}><path d="M4 7h16M4 12h16M4 17h16" /></svg>;
}

export function CloseIcon() {
  return <svg width="20" height="20" viewBox="0 0 24 24" strokeWidth="2.2" {...base}><path d="M6 6l12 12M18 6L6 18" /></svg>;
}

export function TraceUnderline() {
  // The circuit trace of the logo, under the headline.
  return (
    <svg viewBox="0 0 360 26" className="absolute -bottom-4 left-0 h-[18px] w-[85%] md:-bottom-[18px] md:h-[26px] md:w-[360px]" {...base}>
      <path d="M2 20 H200 L222 6 H330" stroke="var(--c-brand)" strokeWidth="5" />
      <circle cx="342" cy="6" r="9" stroke="var(--c-brand)" strokeWidth="5" />
    </svg>
  );
}

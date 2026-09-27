import { useId, useRef } from "react";

const STYLES = {
  underline: (active) => `min-h-[56px] shrink-0 border-b-[3px] px-2 font-display text-base ${
    active ? "border-brand font-bold text-ink" : "border-transparent font-semibold text-muted"}`,
  pill: (active) => `min-h-[44px] shrink-0 rounded-full border-[1.5px] px-4 text-[15px] font-bold ${
    active ? "border-ink bg-ink text-page" : "border-line bg-surface text-ink"}`,
  segment: (active) => `min-h-[48px] rounded-[9px] px-2 text-[15px] md:text-base ${
    active ? "bg-surface font-bold text-ink shadow-sm" : "font-semibold text-ink"}`,
  card: (active) => `min-h-[60px] border-b-[3px] px-2 font-display text-[15px] md:text-base ${
    active ? "border-brand bg-surface font-bold text-ink" : "border-transparent bg-subtle font-semibold text-ink"}`,
  // Pills on the phone, an underlined bar from the tablet on (Design A).
  services: (active) => `min-h-[44px] shrink-0 rounded-full border-[1.5px] px-4 text-[15px] font-bold md:-mb-[2px] md:min-h-[56px] md:rounded-none md:border-0 md:border-b-[3px] md:bg-transparent md:px-2 md:font-display md:text-base ${
    active ? "border-ink bg-ink text-page md:border-brand md:text-ink" : "border-line bg-surface text-ink md:border-transparent md:font-semibold md:text-muted"}`,
};

/** Accessible tabs with their panel: arrow keys, Home and End move between
 *  tabs; only the selected tab is in the tab order. `variant` picks the look
 *  of Design A. */
export default function Tabs({ label, items, selected, onSelect, variant = "underline", listClassName = "",
  panelClassName = "", children }) {
  const baseId = useId();
  const refs = useRef([]);
  const panelId = `${baseId}-panel`;

  const onKeyDown = (event, index) => {
    const keys = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: items.length - 1 };
    if (!(event.key in keys)) return;
    event.preventDefault();
    const next = (keys[event.key] + items.length) % items.length;
    onSelect(items[next].key);
    refs.current[next]?.focus();
  };

  return (
    <>
      <div role="tablist" aria-label={label} className={listClassName}>
        {items.map((item, index) => {
          const active = item.key === selected;
          return (
            <button
              key={item.key}
              ref={(node) => { refs.current[index] = node; }}
              type="button"
              role="tab"
              id={`${baseId}-tab-${index}`}
              aria-selected={active}
              aria-controls={panelId}
              tabIndex={active ? 0 : -1}
              onClick={() => onSelect(item.key)}
              onKeyDown={(event) => onKeyDown(event, index)}
              className={STYLES[variant](active)}
            >
              {item.label}
            </button>
          );
        })}
      </div>
      <div
        role="tabpanel"
        id={panelId}
        aria-labelledby={`${baseId}-tab-${Math.max(items.findIndex((item) => item.key === selected), 0)}`}
        className={panelClassName}
      >
        {children}
      </div>
    </>
  );
}

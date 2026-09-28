/** Small pieces the pages of the customer area share. */
export function Loading() {
  return <div aria-busy="true" aria-label="Wird geladen" className="card h-48 animate-pulse" />;
}

export function Problem({ error, onRetry }) {
  return (
    <div role="alert" className="card flex flex-col items-start gap-3 p-5">
      <p className="m-0 font-semibold">{error?.message || "Das hat nicht geklappt."}</p>
      {onRetry && <button type="button" className="btn-outline min-h-[44px]" onClick={onRetry}>Noch einmal laden</button>}
    </div>
  );
}

const TONES = {
  warn: "bg-badge text-badge-ink",
  good: "bg-[#E3F1EA] text-[#1E6B45]",
  plain: "bg-line-soft text-ink",
};

export function Badge({ tone = "plain", children }) {
  return <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-[13px] font-bold ${TONES[tone]}`}>{children}</span>;
}

export function PageTitle({ children, text }) {
  return (
    <div className="flex flex-col gap-1">
      <h1 className="m-0 text-[28px] font-bold md:text-[34px]">{children}</h1>
      {text && <p className="m-0 max-w-[48em] text-[15px] text-muted">{text}</p>}
    </div>
  );
}

export function Empty({ children }) {
  return <p className="card m-0 p-5 text-body">{children}</p>;
}

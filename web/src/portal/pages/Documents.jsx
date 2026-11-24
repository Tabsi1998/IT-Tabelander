import { Link, Navigate, useParams } from "react-router";
import { day, money, usePortalData } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { InvoiceState } from "./Invoices.jsx";
import { Badge, Empty, Loading, PageTitle, Problem } from "./parts.jsx";

/** The kinds of documents (#100), by their part of the address. Offers stay
 *  on their own page: they wait for an answer. */
export const KINDS = {
  rechnungen: { source: "invoices", title: "Rechnungen", chip: "Rechnungen", one: "Rechnung",
    text: "Deine Rechnungen und Gutschriften. Offene bezahlst du per Überweisung – am einfachsten mit dem QR-Code." },
  auftraege: { source: "orders", title: "Auftragsbestätigungen", chip: "Aufträge", one: "Auftragsbestätigung",
    text: "Was ich für dich bestellt oder fix eingeplant habe." },
  lieferscheine: { source: "shipments", title: "Lieferscheine", chip: "Lieferscheine", one: "Lieferschein",
    text: "Was ich dir geliefert oder geschickt habe." },
  arbeitsberichte: { source: "interventions", title: "Arbeitsberichte", chip: "Arbeitsberichte", one: "Arbeitsbericht",
    text: "Was ich bei dir gemacht habe, mit Datum und Zeit." },
  vertraege: { source: "contracts", title: "Verträge", chip: "Verträge", one: "Vertrag",
    text: "Laufende Leistungen wie Wartung oder Betreuung." },
};

/** Where a document's page is: invoices keep their address from the mails. */
export function documentPath(art, item) {
  return art === "rechnungen" ? `/kundenbereich/rechnungen/${item.id}` : `/kundenbereich/belege/${art}/${item.id}`;
}

const GOOD = new Set(["geliefert", "zugestellt", "erledigt", "abgerechnet", "aktiv", "läuft"]);

function DocumentState({ item }) {
  if (!item.state) return null;
  return <Badge tone={GOOD.has(item.state) ? "good" : "plain"}>{item.state}</Badge>;
}

function title(art, item) {
  return `${art === "rechnungen" && item.credit_note ? "Gutschrift" : KINDS[art].one} ${item.ref}`;
}

function Filter({ active, counts }) {
  const chip = (on) => `inline-flex min-h-[44px] items-center gap-2 rounded-full border-[1.5px] px-4 text-[15px] font-semibold no-underline hover:text-ink ${
    on ? "border-ink bg-ink text-page hover:text-page" : "border-line bg-surface text-ink"}`;
  const shown = Object.keys(KINDS).filter((art) => counts[art] > 0 || art === active);
  if (shown.length < 2 && !active) return null;
  const entry = (art, label, to, count) => (
    <li key={art}>
      <Link to={to} aria-current={(active || "") === art ? "page" : undefined} className={chip((active || "") === art)}>
        {label}
        <span className="text-[13px] font-bold opacity-80">{count}</span>
      </Link>
    </li>
  );
  return (
    <nav aria-label="Belegarten">
      <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
        {entry("", "Alle", "/kundenbereich/belege", Object.values(counts).reduce((sum, value) => sum + value, 0))}
        {shown.map((art) => entry(art, KINDS[art].chip, art === "rechnungen" ? "/kundenbereich/rechnungen" : `/kundenbereich/belege/${art}`, counts[art]))}
      </ul>
    </nav>
  );
}

/** Every document of the customer, newest first, filtered by kind (#100). */
export function Documents({ art: fixed }) {
  const { art: fromPath } = useParams();
  const art = fixed || fromPath || "";
  const { overview } = usePortal();
  if (art && !KINDS[art]) return <Navigate to="/kundenbereich/belege" replace />;
  if (overview.loading && !overview.data) return <Loading />;
  if (overview.error) return <Problem error={overview.error} onRetry={overview.reload} />;
  const data = overview.data;
  const all = Object.entries(KINDS).flatMap(([kind, spec]) => (data[spec.source] || []).map((item) => ({ art: kind, item })));
  const counts = Object.fromEntries(Object.entries(KINDS).map(([kind, spec]) => [kind, (data[spec.source] || []).length]));
  const shown = all.filter((entry) => !art || entry.art === art)
    .sort((a, b) => String(b.item.date || "").localeCompare(String(a.item.date || "")));
  return (
    <>
      <PageTitle text={art ? KINDS[art].text : "Alles, was ich dir geschickt habe – zum Ansehen und als PDF."}>
        {art ? KINDS[art].title : "Belege"}
      </PageTitle>
      <Filter active={art} counts={counts} />
      {shown.length === 0 ? <Empty>{art ? `Noch keine ${KINDS[art].title}.` : "Noch keine Belege."}</Empty> : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0">
          {shown.map(({ art: kind, item }) => (
            <li key={`${kind}-${item.id}`}>
              <Link to={documentPath(kind, item)} className="card flex flex-wrap items-center justify-between gap-3 p-5 no-underline hover:border-ink">
                <span className="flex min-w-0 flex-col">
                  <b className="break-words font-display text-lg">{title(kind, item)}</b>
                  <span className="text-sm text-muted">
                    {[day(item.date), kind === "rechnungen" && item.open && item.due && `fällig am ${day(item.due)}`].filter(Boolean).join(" · ")}
                  </span>
                </span>
                <span className="flex items-center gap-3">
                  {item.total != null && <b>{money(kind === "rechnungen" && item.open ? item.remain ?? item.total : item.total)}</b>}
                  {kind === "rechnungen" ? <InvoiceState invoice={item} /> : <DocumentState item={item} />}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

const HOURS = new Intl.NumberFormat("de-AT", { maximumFractionDigits: 2 });
const hours = (value) => `${HOURS.format(Number(value) || 0)} Std.`;

function Table({ label, head, rows, foot }) {
  return (
    <table className="w-full border-collapse text-[15px] md:text-base" aria-label={label}>
      <thead>
        <tr className="text-left text-sm text-muted">
          {head.map(([text, right]) => (
            <th key={text} scope="col" className={`border-b border-line py-2 font-semibold ${right ? "text-right" : ""}`}>{text}</th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((cells, index) => (
          <tr key={index}>
            {cells.map(([content, right], cell) => (
              <td key={cell} className={`border-b border-line-soft py-3 align-top ${right ? "whitespace-nowrap pl-3 text-right" : "pr-3"}`}>{content}</td>
            ))}
          </tr>
        ))}
        {foot}
      </tbody>
    </table>
  );
}

function Position({ line }) {
  return (
    <>
      {Number(line.qty) > 1 ? `${Number(line.qty)} × ` : ""}{line.text}
      {line.details && <span className="block text-sm text-muted">{line.details}</span>}
    </>
  );
}

function Content({ art, item }) {
  if (art === "auftraege") {
    return (
      <Table label="Positionen" head={[["Position"], ["Betrag", true]]}
        rows={item.lines.map((line) => [[<Position key="p" line={line} />], [money(line.total), true]])}
        foot={<>
          <tr><td className="pt-3 text-sm text-muted">davon Umsatzsteuer</td><td className="pt-3 text-right text-sm text-muted">{money(item.vat)}</td></tr>
          <tr><th scope="row" className="pt-2 text-left font-bold">Gesamt</th><td className="pt-2 text-right font-display text-xl font-bold">{money(item.total)}</td></tr>
        </>} />
    );
  }
  if (art === "lieferscheine") {
    return (
      <Table label="Gelieferte Positionen" head={[["Position"], ["Menge", true]]}
        rows={item.lines.map((line) => [[<Position key="p" line={{ ...line, qty: 1 }} />], [Number(line.qty) || line.qty, true]])} />
    );
  }
  if (art === "arbeitsberichte") {
    return (
      <>
        {item.description && <p className="m-0 whitespace-pre-line text-[17px] leading-normal text-body">{item.description}</p>}
        {item.lines.length > 0 && (
          <Table label="Arbeiten" head={[["Datum"], ["Arbeit"], ["Zeit", true]]}
            rows={item.lines.map((line) => [[day(line.date)], [line.text], [hours(line.hours), true]])}
            foot={<tr><th scope="row" colSpan={2} className="pt-3 text-left font-bold">Gesamt</th><td className="pt-3 text-right font-bold">{hours(item.hours)}</td></tr>} />
        )}
      </>
    );
  }
  return (
    <>
      <Terms item={item} />
      <Table label="Leistungen" head={[["Leistung"], ["Betrag", true]]}
        rows={item.lines.map((line) => [[<>
          <Position key="p" line={line} />
          <span key="z" className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
            {[line.start && `ab ${day(line.start)}`, line.end && `bis ${day(line.end)}`].filter(Boolean).join(" ")}
            {line.state && <Badge tone={GOOD.has(line.state) ? "good" : "plain"}>{line.state}</Badge>}
          </span>
        </>], [<>{money(line.total)}{line.interval && <span key="i" className="block text-sm text-muted">{line.interval}</span>}</>, true]])} />
    </>
  );
}

/** Minimum term and notice period, as the owner set them in Dolibarr (#101); nothing if empty. */
export function Terms({ item }) {
  if (!item.minimum_months && !item.notice) return null;
  return (
    <dl className="m-0 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[15px]">
      {item.minimum_months && <><dt className="text-muted">Mindestlaufzeit</dt><dd className="m-0">{item.minimum_months === 1 ? "1 Monat" : `${item.minimum_months} Monate`}</dd></>}
      {item.notice && <><dt className="text-muted">Kündigungsfrist</dt><dd className="m-0 break-words">{item.notice}</dd></>}
    </dl>
  );
}

function DocumentPage({ art, id }) {
  const spec = KINDS[art];
  const doc = usePortalData(`/documents/${spec.source}/${encodeURIComponent(id)}`);
  if (doc.loading && !doc.data) return <Loading />;
  if (doc.error) return <Problem error={doc.error} onRetry={doc.error.status >= 500 ? doc.reload : null} />;
  const item = doc.data;
  const meta = [day(item.date), item.delivery && `Lieferung am ${day(item.delivery)}`, item.tracking && `Sendungsnummer ${item.tracking}`];
  return (
    <>
      <Link to={`/kundenbereich/belege/${art}`} className="self-start text-[15px] font-semibold no-underline">← Alle {spec.title}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <PageTitle text={meta.filter(Boolean).join(" · ")}>{title(art, item)}</PageTitle>
        <DocumentState item={item} />
      </div>
      <section className="card flex flex-col gap-4 p-5 md:p-7" aria-label={spec.one}>
        <Content art={art} item={item} />
        {item.has_pdf ? (
          <a href={`/api/portal/documents/${spec.source}/${item.id}/pdf`} target="_blank" rel="noopener noreferrer"
            className="btn-outline self-start border-line">{spec.one} als PDF</a>
        ) : (
          <p className="m-0 text-[15px] text-muted">Für diesen Beleg gibt es noch kein PDF.</p>
        )}
      </section>
    </>
  );
}

/** One order confirmation, delivery note, work report or contract (#100). */
export function DocumentDetail() {
  const { art, id } = useParams();
  if (!KINDS[art]) return <Navigate to="/kundenbereich/belege" replace />;
  if (art === "rechnungen") return <Navigate to={`/kundenbereich/rechnungen/${encodeURIComponent(id)}`} replace />;
  return <DocumentPage key={`${art}-${id}`} art={art} id={id} />;
}

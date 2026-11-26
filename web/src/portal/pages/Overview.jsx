import { Link } from "react-router";
import { day, money } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { stepOf } from "../steps.js";
import { NEW_INQUIRY } from "./InquiryDetail.jsx";
import { Loading, Problem } from "./parts.jsx";
import { waitsForYou } from "./Unterlagen.jsx";

/** Hosting, maintenance and the like that run under a contract (#101). */
function Services({ services }) {
  if (!services?.length) return null;
  return (
    <section aria-labelledby="leistungen" className="flex flex-col gap-3">
      <h2 id="leistungen" className="m-0 text-[22px] font-bold md:text-2xl">Laufende Leistungen</h2>
      <ul className="m-0 flex list-none flex-col gap-3 p-0">
        {services.map((item, index) => (
          <li key={`${item.contract_id}-${index}`}>
            <Link to={`/kundenbereich/belege/vertraege/${item.contract_id}`}
              className="card flex flex-wrap items-start justify-between gap-x-4 gap-y-2 p-5 no-underline hover:border-ink">
              <span className="flex min-w-0 flex-col gap-0.5">
                <b className="break-words font-display text-lg">{item.text}</b>
                <span className="text-sm text-muted">
                  {[item.start && `seit ${day(item.start)}`, item.end && `geplant bis ${day(item.end)}`, `Vertrag ${item.contract_ref}`]
                    .filter(Boolean).join(" · ")}
                </span>
                {(item.minimum_months || item.notice) && (
                  <span className="text-sm text-muted">
                    {[item.minimum_months && `Mindestlaufzeit ${item.minimum_months === 1 ? "1 Monat" : `${item.minimum_months} Monate`}`,
                      item.notice && `Kündigungsfrist ${item.notice}`].filter(Boolean).join(" · ")}
                  </span>
                )}
              </span>
              <span className="flex flex-col items-end">
                <b>{money(item.total)}</b>
                {item.interval && <span className="text-sm text-muted">{item.interval}</span>}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

function Tile({ label, value, to, strong }) {
  return (
    <Link to={to} className="card flex flex-col gap-1 p-5 no-underline hover:border-ink">
      <span className="text-[15px] text-muted">{label}</span>
      <b className={`font-display text-[32px] ${strong && value > 0 ? "text-link-hover" : "text-ink"}`}>{value}</b>
    </Link>
  );
}

function Card({ to, kicker, title, text, action, accent }) {
  return (
    <Link to={to} className={`flex flex-col gap-1.5 rounded-2xl bg-surface p-5 no-underline hover:text-ink ${accent ? "border-2 border-accent" : "border border-line"}`}>
      <span className={`text-xs font-bold uppercase tracking-[0.06em] ${accent ? "text-link-hover" : "text-muted"}`}>{kicker}</span>
      <b className="font-display text-lg">{title}</b>
      <span className="text-[15px] text-body">{text}</span>
      {action && <span className={`mt-1 text-[15px] font-bold ${accent ? "text-link-hover" : "text-ink"}`}>{action} →</span>}
    </Link>
  );
}

/** What is open, at a glance (#64): offers to answer, invoices to pay, repairs under way. */
export default function Overview() {
  const { overview, documents } = usePortal();
  if (overview.loading && !overview.data) return <Loading />;
  if (overview.error) return <Problem error={overview.error} onRetry={overview.reload} />;
  const data = overview.data;
  const offers = data.offers.filter((item) => item.status === 1);
  const invoices = data.invoices.filter((item) => item.open);
  // An inquiry waiting for the customer's answer comes first and stands out (#122).
  const waiting = data.tickets.filter((item) => item.open && item.step === "wartet_auf_dich");
  const repairs = data.tickets.filter((item) => item.open && item.step !== "wartet_auf_dich");
  // Documents to confirm or sign (#187); the overview never waits for them.
  const papers = (documents?.data || []).filter(waitsForYou);
  return (
    <>
      <div className="flex flex-col gap-1">
        <h1 className="m-0 text-[28px] font-bold md:text-[36px]">Dein Kundenbereich</h1>
        <p className="m-0 text-[15px] text-muted">für {data.customers.join(", ")}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-3 md:gap-4">
        <Tile label="Angebote zum Annehmen" value={data.counts.offers_open} to="/kundenbereich/angebote" strong />
        <Tile label="Offene Rechnungen" value={data.counts.invoices_open} to="/kundenbereich/rechnungen" />
        <Tile label="Offene Anfragen" value={data.counts.repairs_running} to="/kundenbereich/anfragen" />
      </div>
      {waiting.length + offers.length + papers.length + invoices.length + repairs.length === 0 ? (
        <div className="card flex flex-col items-start gap-3 p-6">
          <p className="m-0 text-[17px] text-body">Gerade ist nichts offen.</p>
          <Link to={NEW_INQUIRY} className="btn-primary">Neue Anfrage starten</Link>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {waiting.map((item) => (
            <Card key={`w${item.id}`} to={`/kundenbereich/anfragen/${item.id}`} kicker="Wartet auf dich" accent
              title={item.title || "Anfrage"} action="Jetzt antworten" text={stepOf(item).text} />
          ))}
          {offers.map((item) => (
            <Card key={`o${item.id}`} to={`/kundenbereich/angebote/${item.id}`} kicker="Angebot wartet auf dich" accent
              title={`Angebot ${item.ref}`} action="Ansehen und annehmen"
              text={`${money(item.total)}${item.valid_until ? ` · gültig bis ${day(item.valid_until)}` : ""}`} />
          ))}
          {papers.map((item) => (
            <Card key={`u${item.customer_id}-${item.slug}`} to="/kundenbereich/unterlagen" kicker="Unterlage wartet auf dich" accent
              title={item.title} action={item.can_sign ? "Lesen und unterschreiben" : "Lesen und bestätigen"}
              text={item.stand ? `Stand ${day(`${item.stand}T12:00:00`)}` : "Bitte kurz ansehen"} />
          ))}
          {invoices.map((item) => (
            <Card key={`i${item.id}`} to={`/kundenbereich/rechnungen/${item.id}`} kicker="Rechnung offen"
              title={`Rechnung ${item.ref}`} action="Ansehen und überweisen"
              text={`${money(item.remain ?? item.total)}${item.due ? ` · fällig am ${day(item.due)}` : ""}`} />
          ))}
          {repairs.map((item) => (
            <Card key={`t${item.id}`} to={`/kundenbereich/anfragen/${item.id}`} kicker={stepOf(item).label} action="Verlauf ansehen"
              title={item.title || "Anfrage"} text={[item.inquiry_ref, item.created_at && `seit ${day(item.created_at)}`].filter(Boolean).join(" · ")} />
          ))}
        </div>
      )}
      <Services services={data.services} />
    </>
  );
}

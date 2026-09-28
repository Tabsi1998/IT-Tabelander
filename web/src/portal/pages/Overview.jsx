import { Link } from "react-router";
import { STEPS } from "../../forms/StatusView.jsx";
import { day, money } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { Loading, Problem } from "./parts.jsx";

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
  const { overview } = usePortal();
  if (overview.loading && !overview.data) return <Loading />;
  if (overview.error) return <Problem error={overview.error} onRetry={overview.reload} />;
  const data = overview.data;
  const offers = data.offers.filter((item) => item.status === 1);
  const invoices = data.invoices.filter((item) => item.open);
  const repairs = data.tickets.filter((item) => item.open);
  return (
    <>
      <div className="flex flex-col gap-1">
        <h1 className="m-0 text-[28px] font-bold md:text-[36px]">Dein Kundenbereich</h1>
        <p className="m-0 text-[15px] text-muted">für {data.customers.join(", ")}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-3 md:gap-4">
        <Tile label="Angebote zum Annehmen" value={data.counts.offers_open} to="/kundenbereich/angebote" strong />
        <Tile label="Offene Rechnungen" value={data.counts.invoices_open} to="/kundenbereich/rechnungen" />
        <Tile label="Laufende Reparaturen" value={data.counts.repairs_running} to="/kundenbereich/anfragen" />
      </div>
      {offers.length + invoices.length + repairs.length === 0 ? (
        <div className="card flex flex-col items-start gap-3 p-6">
          <p className="m-0 text-[17px] text-body">Gerade ist nichts offen.</p>
          <Link to="/?kontakt=anfrage#kontakt" className="btn-primary">Neue Anfrage starten</Link>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {offers.map((item) => (
            <Card key={`o${item.id}`} to={`/kundenbereich/angebote/${item.id}`} kicker="Angebot wartet auf dich" accent
              title={`Angebot ${item.ref}`} action="Ansehen und annehmen"
              text={`${money(item.total)}${item.valid_until ? ` · gültig bis ${day(item.valid_until)}` : ""}`} />
          ))}
          {invoices.map((item) => (
            <Card key={`i${item.id}`} to={`/kundenbereich/rechnungen/${item.id}`} kicker="Rechnung offen"
              title={`Rechnung ${item.ref}`} action="Ansehen und überweisen"
              text={`${money(item.remain ?? item.total)}${item.due ? ` · fällig am ${day(item.due)}` : ""}`} />
          ))}
          {repairs.map((item) => (
            <Card key={`t${item.id}`} to="/kundenbereich/anfragen" kicker={(STEPS[item.step] || STEPS.eingegangen).label}
              title={item.title || "Anfrage"} text={[item.inquiry_ref, item.created_at && `seit ${day(item.created_at)}`].filter(Boolean).join(" · ")} />
          ))}
        </div>
      )}
    </>
  );
}

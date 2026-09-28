import { useState } from "react";
import { Link, useParams } from "react-router";
import QrCode from "../../components/QrCode.jsx";
import { day, ibanText, money, usePortalData } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { Badge, Empty, Loading, PageTitle, Problem } from "./parts.jsx";

function State({ invoice }) {
  if (invoice.credit_note) return <Badge>Gutschrift</Badge>;
  if (invoice.open) return <Badge tone="warn">offen</Badge>;
  return <Badge tone={invoice.state === "bezahlt" ? "good" : "plain"}>{invoice.state}</Badge>;
}

export function Invoices() {
  const { overview } = usePortal();
  if (overview.loading && !overview.data) return <Loading />;
  if (overview.error) return <Problem error={overview.error} onRetry={overview.reload} />;
  const invoices = overview.data.invoices;
  return (
    <>
      <PageTitle text="Deine Rechnungen. Offene bezahlst du per Überweisung – am einfachsten mit dem QR-Code.">Rechnungen</PageTitle>
      {invoices.length === 0 ? <Empty>Noch keine Rechnungen.</Empty> : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0">
          {invoices.map((item) => (
            <li key={item.id}>
              <Link to={`/kundenbereich/rechnungen/${item.id}`} className="card flex flex-wrap items-center justify-between gap-3 p-5 no-underline hover:border-ink">
                <span className="flex flex-col">
                  <b className="font-display text-lg">Rechnung {item.ref}</b>
                  <span className="text-sm text-muted">{[day(item.date), item.open && item.due && `fällig am ${day(item.due)}`].filter(Boolean).join(" · ")}</span>
                </span>
                <span className="flex items-center gap-3"><b>{money(item.open ? item.remain ?? item.total : item.total)}</b><State invoice={item} /></span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

function Copy({ value, label }) {
  const [done, setDone] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setDone(true);
      window.setTimeout(() => setDone(false), 2000);
    } catch {
      setDone(false);
    }
  };
  return (
    <button type="button" onClick={copy} className="min-h-[36px] rounded-lg border border-navy-line px-2.5 text-sm font-semibold text-on-navy">
      {done ? "Kopiert" : `${label} kopieren`}
    </button>
  );
}

/** The transfer (#66): payee, IBAN, amount and reference to copy, and the
 *  code Austrian banking apps scan ("Zahlen mit Code"). */
function Payment({ payment }) {
  return (
    <section aria-labelledby="ueberweisen" className="flex flex-col gap-5 rounded-2xl bg-navy p-5 text-on-navy md:flex-row md:items-start md:p-7">
      <div className="flex flex-col items-center gap-3 md:w-[200px] md:shrink-0">
        <div className="rounded-xl bg-white p-2">
          <QrCode text={payment.epc} className="h-[170px] w-[170px]"
            title={`QR-Code für die Banking-App: ${money(payment.amount)} an ${payment.holder}, Verwendungszweck ${payment.reference}`} />
        </div>
        <span className="text-center text-sm text-on-navy-muted">In der Banking-App „Zahlen mit Code“ oder „QR-Code scannen“ wählen</span>
      </div>
      <div className="flex min-w-0 flex-1 flex-col gap-4">
        <h2 id="ueberweisen" className="m-0 text-xl font-bold text-on-navy">Überweisen</h2>
        <dl className="m-0 grid grid-cols-[100px_minmax(0,1fr)] gap-x-3 gap-y-2.5 text-[15px]">
          <dt className="text-on-navy-muted">Empfänger</dt><dd className="m-0">{payment.holder}</dd>
          <dt className="text-on-navy-muted">IBAN</dt><dd className="m-0 break-words font-semibold tracking-[0.02em]">{ibanText(payment.iban)}</dd>
          {payment.bic && <><dt className="text-on-navy-muted">BIC</dt><dd className="m-0">{payment.bic}</dd></>}
          <dt className="text-on-navy-muted">Betrag</dt><dd className="m-0 font-semibold">{money(payment.amount)}</dd>
          <dt className="text-on-navy-muted">Zweck</dt><dd className="m-0">{payment.reference}</dd>
        </dl>
        <div className="flex flex-wrap gap-2">
          <Copy value={payment.iban} label="IBAN" />
          <Copy value={payment.reference} label="Zweck" />
        </div>
      </div>
    </section>
  );
}

export function InvoiceDetail() {
  const { id } = useParams();
  const invoice = usePortalData(`/invoices/${encodeURIComponent(id)}`);
  if (invoice.loading && !invoice.data) return <Loading />;
  if (invoice.error) return <Problem error={invoice.error} onRetry={invoice.error.status >= 500 ? invoice.reload : null} />;
  const item = invoice.data;
  return (
    <>
      <Link to="/kundenbereich/rechnungen" className="self-start text-[15px] font-semibold no-underline">← Alle Rechnungen</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <PageTitle text={[day(item.date), item.open && item.due && `fällig am ${day(item.due)}`].filter(Boolean).join(" · ")}>
          {item.credit_note ? "Gutschrift" : "Rechnung"} {item.ref}
        </PageTitle>
        <State invoice={item} />
      </div>
      {item.payment && <Payment payment={item.payment} />}
      {item.open && !item.payment && (
        <p className="card m-0 p-5 text-body">Die Bankverbindung zum Überweisen steht auf der Rechnung.</p>
      )}
      <section className="card flex flex-col gap-4 p-5 md:p-7" aria-label="Positionen">
        <table className="w-full border-collapse text-[15px] md:text-base">
          <thead>
            <tr className="text-left text-sm text-muted">
              <th scope="col" className="border-b border-line py-2 font-semibold">Position</th>
              <th scope="col" className="border-b border-line py-2 text-right font-semibold">Betrag</th>
            </tr>
          </thead>
          <tbody>
            {item.lines.map((line, index) => (
              <tr key={`${item.id}-${index}`}>
                <td className="border-b border-line-soft py-3 pr-3 align-top">
                  {Number(line.qty) > 1 ? `${Number(line.qty)} × ` : ""}{line.text}
                  {line.details && <span className="block text-sm text-muted">{line.details}</span>}
                </td>
                <td className="whitespace-nowrap border-b border-line-soft py-3 text-right align-top">{money(line.total)}</td>
              </tr>
            ))}
            <tr>
              <td className="pt-3 text-sm text-muted">davon Umsatzsteuer</td>
              <td className="pt-3 text-right text-sm text-muted">{money(item.vat)}</td>
            </tr>
            <tr>
              <th scope="row" className="pt-2 text-left font-bold">Gesamt</th>
              <td className="pt-2 text-right font-display text-xl font-bold">{money(item.total)}</td>
            </tr>
            {item.open && Number(item.remain) !== Number(item.total) && (
              <tr>
                <th scope="row" className="pt-1 text-left font-semibold">Noch offen</th>
                <td className="pt-1 text-right font-semibold">{money(item.remain)}</td>
              </tr>
            )}
          </tbody>
        </table>
        {item.has_pdf && (
          <a href={`/api/portal/invoices/${item.id}/pdf`} target="_blank" rel="noopener noreferrer"
            className="btn-outline self-start border-line">Rechnung als PDF</a>
        )}
      </section>
    </>
  );
}

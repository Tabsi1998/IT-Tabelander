import { STEPS } from "../../forms/StatusView.jsx";
import { day } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { Badge, Empty, Loading, PageTitle, Problem } from "./parts.jsx";

/** Every inquiry and repair with its step, as Dolibarr's tickets say (#64). */
export default function Inquiries() {
  const { overview } = usePortal();
  if (overview.loading && !overview.data) return <Loading />;
  if (overview.error) return <Problem error={overview.error} onRetry={overview.reload} />;
  const tickets = overview.data.tickets;
  return (
    <>
      <PageTitle text="Alle deine Anfragen und Reparaturen mit ihrem aktuellen Stand.">Anfragen</PageTitle>
      {tickets.length === 0 ? <Empty>Noch keine Anfragen.</Empty> : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0">
          {tickets.map((item) => {
            const step = STEPS[item.step] || STEPS.eingegangen;
            return (
              <li key={item.id} className="card flex flex-col gap-2 p-5">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <b className="font-display text-lg">{item.title || "Anfrage"}</b>
                  <Badge tone={step.badge ? "warn" : item.step === "abgeschlossen" ? "good" : "plain"}>{step.label}</Badge>
                </div>
                <p className="m-0 text-[15px] text-body">{step.text}</p>
                <span className="text-sm text-muted">
                  {[item.inquiry_ref, item.ref && `Ticket ${item.ref}`, item.created_at && `seit ${day(item.created_at)}`].filter(Boolean).join(" · ")}
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}

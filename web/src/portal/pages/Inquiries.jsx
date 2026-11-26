import { useEffect, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router";
import { day, portalRequest } from "../api.js";
import { usePortal } from "../PortalApp.jsx";
import { stepOf, stepTone } from "../steps.js";
import { NEW_INQUIRY } from "./InquiryDetail.jsx";
import { Badge, Empty, Loading, PageTitle, Problem } from "./parts.jsx";

function Item({ item }) {
  const step = stepOf(item);
  return (
    <li>
      <Link to={`/kundenbereich/anfragen/${item.id}`}
        className={`flex flex-col gap-2 rounded-2xl bg-surface p-5 no-underline hover:border-ink hover:text-ink ${
          item.step === "wartet_auf_dich" ? "border-2 border-accent" : "border border-line"}`}>
        <span className="flex flex-wrap items-start justify-between gap-2">
          <b className="min-w-0 break-words font-display text-lg">{item.title || "Anfrage"}</b>
          <Badge tone={stepTone(item)}>{step.label}</Badge>
        </span>
        {item.open && <span className="text-[15px] text-body">{step.text}</span>}
        <span className="text-sm text-muted">
          {[item.inquiry_ref, item.ref && `Ticket ${item.ref}`, item.created_at && `seit ${day(item.created_at)}`].filter(Boolean).join(" · ")}
        </span>
      </Link>
    </li>
  );
}

/** The link from Dolibarr's mails, /status/view.php?track_id=… (#122): straight
 *  to that inquiry if it is this customer's, else the list says so. */
function useTrackLink() {
  const [search] = useSearchParams();
  const navigate = useNavigate();
  const trackId = search.get("track_id") || "";
  const [state, setState] = useState(trackId ? "looking" : "");
  useEffect(() => {
    if (!trackId) return undefined;
    const controller = new AbortController();
    portalRequest("GET", `/inquiries/track/${encodeURIComponent(trackId)}`, undefined, { signal: controller.signal })
      .then((found) => navigate(`/kundenbereich/anfragen/${found.id}`, { replace: true }))
      .catch((error) => { if (error?.name !== "AbortError") setState(error.status === 404 ? "unknown" : "failed"); });
    return () => controller.abort();
  }, [trackId, navigate]);
  return state;
}

/** Every inquiry and repair with its step, as Dolibarr's tickets say (#64, #122). */
export default function Inquiries() {
  const { overview } = usePortal();
  const track = useTrackLink();
  if (track === "looking" || (overview.loading && !overview.data)) return <Loading />;
  if (overview.error) return <Problem error={overview.error} onRetry={overview.reload} />;
  const tickets = overview.data.tickets;
  // What waits for the customer first, then the rest of the open ones, then the closed.
  const open = tickets.filter((item) => item.open)
    .sort((a, b) => Number(b.step === "wartet_auf_dich") - Number(a.step === "wartet_auf_dich"));
  const closed = tickets.filter((item) => !item.open);
  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <PageTitle text="Alle deine Anfragen und Reparaturen. Öffne eine, um den Verlauf zu sehen und mir zu antworten.">Anfragen</PageTitle>
        <Link to={NEW_INQUIRY} className="btn-primary min-h-[48px]">Neue Anfrage</Link>
      </div>
      {track === "unknown" && (
        <p role="status" className="card m-0 p-5 text-body">
          Die Anfrage aus diesem Link gehört nicht zu deiner Anmeldung. Hier sind alle Anfragen, die du sehen kannst.
        </p>
      )}
      {track === "failed" && (
        <p role="status" className="card m-0 p-5 text-body">Die Anfrage aus dem Link konnte ich gerade nicht öffnen. Hier sind alle deine Anfragen.</p>
      )}
      {tickets.length === 0 ? <Empty>Noch keine Anfragen.</Empty> : (
        <>
          {open.length > 0 && (
            <section aria-labelledby="offen" className="flex flex-col gap-3">
              <h2 id="offen" className="m-0 text-xl font-bold">Offen</h2>
              <ul className="m-0 flex list-none flex-col gap-3 p-0">{open.map((item) => <Item key={item.id} item={item} />)}</ul>
            </section>
          )}
          {closed.length > 0 && (
            <section aria-labelledby="erledigt" className="flex flex-col gap-3">
              <h2 id="erledigt" className="m-0 text-xl font-bold">Abgeschlossen</h2>
              <ul className="m-0 flex list-none flex-col gap-3 p-0">{closed.map((item) => <Item key={item.id} item={item} />)}</ul>
            </section>
          )}
        </>
      )}
    </>
  );
}

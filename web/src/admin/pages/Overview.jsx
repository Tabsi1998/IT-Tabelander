import { Link } from "react-router";
import { useAdminData } from "../api.js";
import { LoadState, Notice, PageHeader, Section } from "../ui.jsx";

function when(value) {
  const date = value ? new Date(value) : null;
  return date && !Number.isNaN(date.getTime())
    ? date.toLocaleString("de-AT", { dateStyle: "medium", timeStyle: "short" }) : "–";
}

function Tile({ label, value, note, to }) {
  return (
    <Link to={to} className="card flex flex-col gap-1 p-5 no-underline hover:border-ink">
      <span className="text-[15px] font-semibold text-muted">{label}</span>
      <span className="font-display text-3xl font-bold text-ink">{value}</span>
      {note && <span className="text-sm text-muted">{note}</span>}
    </Link>
  );
}

export default function Overview() {
  const state = useAdminData("/admin/dashboard");
  return (
    <>
      <PageHeader title="Übersicht" description="Was gerade wartet, was neu ist und was noch fehlt." />
      <LoadState state={state}>
        {(data) => (
          <>
            {!data.dolibarr_enabled && (
              <Notice tone="warning">Dolibarr ist nicht verbunden – Anfragen bleiben auf der Website liegen. <Link to="/admin/dolibarr">Zur Dolibarr-Seite</Link></Notice>
            )}
            {!data.mail_configured && (
              <Notice tone="warning">Der E-Mail-Versand ist nicht eingerichtet – Warnungen erreichen dich nicht. <Link to="/admin/technik">Zu Technik</Link></Notice>
            )}
            {data.inquiries.waiting > 0 && (
              <Notice tone="warning">
                {data.inquiries.waiting} {data.inquiries.waiting === 1 ? "Anfrage wartet" : "Anfragen warten"} auf Dolibarr. <Link to="/admin/dolibarr">Warteschlange ansehen</Link>
              </Notice>
            )}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <Tile label="Wartet auf Dolibarr" value={data.inquiries.waiting} to="/admin/dolibarr" note="Anfragen" />
              <Tile label="Bewertungen" value={data.reviews.visible} to="/admin/bewertungen"
                note={data.reviews.hidden ? `sichtbar, ${data.reviews.hidden} versteckt` : "sichtbar"} />
              <Tile label="Galerie" value={data.gallery.visible} to="/admin/galerie" note="Fotos auf der Website" />
              <Tile label="Leistungen" value={data.services.active} to="/admin/leistungen" note="auf der Website" />
            </div>
            <Section title="Neueste Anfragen" description="Die Daten selbst stehen in Dolibarr; hier nur Nummer, Art und Stand.">
              {data.inquiries.recent.length === 0 ? <p className="m-0 text-body">Noch keine Anfragen.</p> : (
                <ul className="m-0 flex list-none flex-col divide-y divide-line-soft p-0">
                  {data.inquiries.recent.map((item) => (
                    <li key={item.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                      <span>
                        <b className="font-display">{item.ref}</b>
                        <span className="text-muted"> · {item.request_type_label} · {when(item.created_at)}</span>
                      </span>
                      <span className={`self-start rounded-full px-2.5 py-1 text-[13px] font-semibold sm:self-auto ${
                        item.in_dolibarr ? "bg-line-soft text-ink" : "bg-badge text-badge-ink"}`}>
                        {item.in_dolibarr ? `in Dolibarr${item.ticket_ref ? ` · ${item.ticket_ref}` : ""}` : "wartet"}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Section>
          </>
        )}
      </LoadState>
    </>
  );
}

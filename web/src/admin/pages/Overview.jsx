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
            {data.reviews.pending > 0 && (
              <Notice tone="info">
                {data.reviews.pending} {data.reviews.pending === 1 ? "Bewertung wartet" : "Bewertungen warten"} auf deine Freigabe. <Link to="/admin/bewertungen">Zu den Bewertungen</Link>
              </Notice>
            )}
            {data.inquiries.waiting > 0 && (
              <Notice tone="warning">
                {data.inquiries.waiting} {data.inquiries.waiting === 1 ? "Anfrage wartet" : "Anfragen warten"} auf Dolibarr. <Link to="/admin/dolibarr">Warteschlange ansehen</Link>
              </Notice>
            )}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <Tile label="Wartet auf Dolibarr" value={data.inquiries.waiting} to="/admin/dolibarr" note="Anfragen" />
              <Tile label="Bewertungen" value={data.reviews.visible} to="/admin/bewertungen"
                note={[
                  "sichtbar",
                  data.reviews.pending ? `${data.reviews.pending} zur Freigabe` : "",
                  data.reviews.hidden ? `${data.reviews.hidden} versteckt` : "",
                ].filter(Boolean).join(", ")} />
              <Tile label="Galerie" value={data.gallery.visible} to="/admin/galerie" note="Fotos auf der Website" />
              <Tile label="Leistungen" value={data.services.active} to="/admin/leistungen" note="auf der Website" />
            </div>
            <Section title="Neueste Anfragen" description="Die Daten selbst stehen in Dolibarr; hier nur Nummer, Art und Stand. Für ein angenommenes Gerät druckst du hier das Etikett.">
              {data.inquiries.recent.length === 0 ? <p className="m-0 text-body">Noch keine Anfragen.</p> : (
                <ul className="m-0 flex list-none flex-col divide-y divide-line-soft p-0">
                  {data.inquiries.recent.map((item) => (
                    <li key={item.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                      <span>
                        <b className="font-display">{item.ref}</b>
                        <span className="text-muted"> · {item.request_type_label} · {when(item.created_at)}</span>
                      </span>
                      <span className="flex flex-wrap items-center gap-2">
                        <span className={`rounded-full px-2.5 py-1 text-[13px] font-semibold ${
                          item.in_dolibarr ? "bg-line-soft text-ink" : "bg-badge text-badge-ink"}`}>
                          {item.in_dolibarr ? `in Dolibarr${item.ticket_ref ? ` · ${item.ticket_ref}` : ""}` : "wartet"}
                        </span>
                        {item.request_type !== "contact" && (
                          <Link to={`/admin/etikett/${item.ref}`} className="btn-outline min-h-[40px] border-line px-3 text-sm"
                            aria-label={`Etikett für ${item.ref} drucken`}>Etikett</Link>
                        )}
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

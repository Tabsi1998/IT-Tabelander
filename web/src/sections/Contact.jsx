import Tabs from "../components/Tabs.jsx";
import InquiryForm from "../forms/InquiryForm.jsx";
import MessageForm from "../forms/MessageForm.jsx";
import StatusView from "../forms/StatusView.jsx";
import { telHref, useSite } from "../lib/site.jsx";

export const CONTACT_TABS = [
  { key: "nachricht", label: "Nachricht" },
  { key: "anfrage", label: "Reparatur anfragen" },
  { key: "status", label: "Status prüfen" },
];

/** Contact area with tabs (#49): message, inquiry (#47) and status (#48). */
export default function Contact({ tab, onTab, preset, statusFor, trackId, onShowStatus }) {
  const { company, openingHours } = useSite();

  return (
    <section id="kontakt" aria-labelledby="kontakt-title">
      <div className="mx-auto flex max-w-page flex-col gap-5 px-5 py-12 md:gap-7 md:px-6 md:py-24">
        <div className="flex flex-col gap-2 md:gap-3">
          <div className="kicker">Kontakt</div>
          <h2 id="kontakt-title" className="m-0 text-[30px] font-bold md:text-[44px]">Wie kann ich helfen?</h2>
        </div>
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-8">
          <div className="card overflow-hidden">
            <Tabs
              label="Kontakt"
              items={CONTACT_TABS}
              selected={tab}
              onSelect={onTab}
              variant="card"
              listClassName="grid grid-cols-3 border-b border-line"
              panelClassName="min-h-[380px] p-5 md:p-8"
            >
              {tab === "nachricht" && <MessageForm />}
              {tab === "anfrage" && <InquiryForm preset={preset} onShowStatus={onShowStatus} />}
              {tab === "status" && <StatusView initial={statusFor} trackId={trackId} />}
            </Tabs>
          </div>
          <aside aria-label="Direkter Kontakt" className="flex flex-col gap-[18px] rounded-2xl bg-navy p-6 text-on-navy md:p-7">
            {company?.phone && (
              <div>
                <a href={telHref(company.phone)} className="text-lg font-bold text-on-navy no-underline">{company.phone}</a>
                <div className="text-[15px] text-on-navy-muted">Anrufen</div>
              </div>
            )}
            {company?.email && (
              <div>
                <a href={`mailto:${company.email}`} className="break-all text-lg font-bold text-on-navy no-underline">{company.email}</a>
                <div className="text-[15px] text-on-navy-muted">Antwort meist am selben Tag</div>
              </div>
            )}
            <div>
              <b className="text-lg">{[company?.town, "Tirol"].filter(Boolean).join(" · ")}</b>
              <div className="text-[15px] text-on-navy-muted">Abgabe nach Vereinbarung, Versand aus ganz Österreich</div>
            </div>
            {openingHours.length > 0 && (
              <div className="border-t border-navy-line pt-4">
                <b className="text-base">Erreichbarkeit</b>
                <dl className="m-0 mt-1.5 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[15px] text-on-navy-muted">
                  {openingHours.map((item) => (
                    <div key={item.day} className="contents">
                      <dt>{item.day}</dt>
                      <dd className="m-0">{item.hours}</dd>
                    </div>
                  ))}
                </dl>
              </div>
            )}
          </aside>
        </div>
      </div>
    </section>
  );
}

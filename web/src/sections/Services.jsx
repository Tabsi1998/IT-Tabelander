import { useEffect, useMemo, useState } from "react";
import { servicesFrom } from "../content/services.js";
import { ContactLink } from "../lib/contact.jsx";
import { useApi } from "../lib/useApi.js";
import { Check } from "../components/Icons.jsx";
import Tabs from "../components/Tabs.jsx";

// One column per service from the tablet on. Whole class names, so Tailwind
// finds them; no inline style, which the page's security policy forbids.
const COLUMNS = ["", "md:grid-cols-1", "md:grid-cols-2", "md:grid-cols-3", "md:grid-cols-4", "md:grid-cols-5",
  "md:grid-cols-6", "md:grid-cols-7", "md:grid-cols-8"];

/** Services as tabs (#46). They come from the admin; until it answers, and
 *  on a fresh installation, the texts of Design A stand in. */
export default function Services({ initialSlug }) {
  const { data } = useApi("/services");
  const services = useMemo(() => servicesFrom(data), [data]);
  const [slug, setSlug] = useState(initialSlug || services[0].slug);

  useEffect(() => {
    // A service that is gone (or the default list replaced) falls back to the first.
    if (!services.some((item) => item.slug === slug)) setSlug(services[0].slug);
  }, [services, slug]);

  const current = services.find((item) => item.slug === slug) || services[0];
  const tabs = services.map((item) => ({ key: item.slug, label: item.tab }));

  return (
    <section id="leistungen" aria-labelledby="leistungen-title">
      <div className="mx-auto flex max-w-page flex-col gap-4 py-9 md:gap-8 md:px-6 md:py-24">
        <div className="flex flex-col gap-2 px-5 md:flex-row md:items-end md:justify-between md:gap-10 md:px-0">
          <div className="flex flex-col gap-2 md:gap-3">
            <div className="kicker">Leistungen</div>
            <h2 id="leistungen-title" className="m-0 text-[30px] font-bold md:text-[44px] md:tracking-[-0.01em]">Was ich für dich mache</h2>
          </div>
          <p className="m-0 hidden max-w-[30em] text-[17px] leading-normal text-body md:block">
            Keine Pauschalpreise: Jedes Gerät ist anders. Nach deiner Anfrage bekommst du ein Angebot für genau deinen Fall.
          </p>
        </div>
        <Tabs
          label="Leistungen"
          items={tabs}
          selected={current.slug}
          onSelect={setSlug}
          variant="services"
          listClassName={`mx-5 flex gap-2 overflow-x-auto pb-1 md:mx-0 md:grid md:gap-0 md:overflow-visible md:border-b-2 md:border-line md:pb-0 ${COLUMNS[Math.min(tabs.length, 8)]}`}
          panelClassName="card mx-5 flex flex-col overflow-hidden md:mx-0 lg:grid lg:grid-cols-[minmax(0,440px)_minmax(0,1fr)] lg:items-start lg:gap-12 lg:p-9"
        >
          <img
            src={current.image}
            alt=""
            width="1200"
            height="800"
            loading="lazy"
            className="block h-[190px] w-full object-cover md:h-[280px] lg:h-[340px] lg:rounded-xl"
          />
          <div className="flex flex-col gap-3 px-[18px] pb-[22px] pt-5 md:gap-[18px] md:px-8 md:pb-8 lg:p-0">
            <h3 className="m-0 text-[22px] font-bold leading-tight md:text-[30px] md:leading-[1.15]">{current.heading}</h3>
            {current.intro && <p className="m-0 text-base leading-normal text-body md:text-lg md:leading-[1.55]">{current.intro}</p>}
            {current.bullets.length > 0 && (
              <ul className="m-0 grid list-none gap-2 p-0 text-base md:grid-cols-2 md:gap-x-6 md:gap-y-2.5">
                {current.bullets.map((bullet) => (
                  <li key={bullet} className="flex items-start gap-2.5">
                    <Check size={20} className="mt-px shrink-0 text-brand" />{bullet}
                  </li>
                ))}
              </ul>
            )}
            {current.note && <p className="m-0 text-[15px] text-muted">{current.note}</p>}
            <ContactLink tab="anfrage" preset={{ requestType: current.requestType, service: current.tab }}
              className="btn-primary mt-1 min-h-[50px] px-[22px] text-base md:self-start">
              {current.tab} anfragen
            </ContactLink>
          </div>
        </Tabs>
        <p className="m-0 px-5 text-[15px] leading-snug text-muted md:hidden">
          Keine Pauschalpreise: Nach deiner Anfrage bekommst du ein Angebot für genau deinen Fall.
        </p>
      </div>
    </section>
  );
}

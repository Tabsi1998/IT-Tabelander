import { useMemo, useState } from "react";

const PAGE = 8;

/** "Aus der Werkstatt" (#50): own photos of finished work. Starts small and
 *  grows with the jobs; without photos the section is not shown at all. */
export default function Workshop({ items }) {
  const [category, setCategory] = useState("");
  const [shown, setShown] = useState(PAGE);
  const categories = useMemo(() => {
    const seen = new Map();
    for (const item of items) if (item.category && !seen.has(item.category)) seen.set(item.category, item.category_label || item.category);
    return [...seen.entries()].map(([key, label]) => ({ key, label }));
  }, [items]);
  if (!items.length) return null;

  const filtered = category ? items.filter((item) => item.category === category) : items;
  const visible = filtered.slice(0, shown);
  const chip = (active) => `min-h-[44px] rounded-full border-[1.5px] px-4 text-[15px] font-semibold ${
    active ? "border-ink bg-ink text-page" : "border-line bg-surface text-ink"}`;

  return (
    <section id="werkstatt" aria-labelledby="werkstatt-title">
      <div className="mx-auto flex max-w-page flex-col gap-5 px-5 pb-6 pt-10 md:gap-7 md:px-6 md:pb-12 md:pt-24">
        <div className="flex flex-col gap-2 md:gap-3">
          <div className="kicker">Aus der Werkstatt</div>
          <h2 id="werkstatt-title" className="m-0 text-[30px] font-bold md:text-[44px]">Zuletzt gemacht</h2>
        </div>
        {categories.length > 1 && (
          <div className="flex flex-wrap gap-2" role="group" aria-label="Nach Bereich filtern">
            <button type="button" aria-pressed={!category} className={chip(!category)}
              onClick={() => { setCategory(""); setShown(PAGE); }}>Alle</button>
            {categories.map((item) => (
              <button key={item.key} type="button" aria-pressed={category === item.key} className={chip(category === item.key)}
                onClick={() => { setCategory(item.key); setShown(PAGE); }}>{item.label}</button>
            ))}
          </div>
        )}
        <ul className="m-0 grid list-none grid-cols-2 gap-3 p-0 md:grid-cols-3 md:gap-5 lg:grid-cols-4">
          {visible.map((item) => (
            <li key={item.id}>
              <figure className="m-0 flex flex-col gap-2.5">
                <img src={item.thumb_url || item.image_url} alt={item.caption || item.category_label || "Foto aus der Werkstatt"}
                  loading="lazy" width="800" height="600"
                  className="aspect-[4/3] h-auto w-full rounded-xl bg-line-soft object-cover" />
                <figcaption className="flex flex-col gap-0.5">
                  {item.category_label && <span className="text-[13px] font-bold text-link-hover">{item.category_label}</span>}
                  {item.caption && <span className="text-[15px] font-semibold md:text-base">{item.caption}</span>}
                </figcaption>
              </figure>
            </li>
          ))}
        </ul>
        {filtered.length > shown && (
          <button type="button" onClick={() => setShown(shown + PAGE)} className="btn-outline self-center text-base">
            Mehr Fotos anzeigen
          </button>
        )}
      </div>
    </section>
  );
}

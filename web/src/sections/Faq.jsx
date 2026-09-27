/** Frequent questions from Dolibarr's knowledge base (#81); hidden without any. */
export default function Faq({ items }) {
  if (!items.length) return null;
  return (
    <section id="fragen" aria-labelledby="fragen-title">
      <div className="mx-auto flex max-w-[880px] flex-col gap-5 px-5 py-12 md:gap-7 md:px-6 md:py-24">
        <div className="flex flex-col gap-2 md:gap-3">
          <div className="kicker">Häufige Fragen</div>
          <h2 id="fragen-title" className="m-0 text-[30px] font-bold md:text-[44px]">Kurz beantwortet</h2>
        </div>
        <div className="flex flex-col gap-3">
          {items.map((item) => (
            <details key={item.id} className="card group p-0">
              <summary className="flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-4 px-5 py-3 font-display text-[17px] font-bold md:text-lg">
                {item.question}
                <span aria-hidden="true" className="shrink-0 text-2xl text-link-hover transition-transform group-open:rotate-45">+</span>
              </summary>
              {item.answer_html
                ? <div className="legal-text px-5 pb-5" dangerouslySetInnerHTML={{ __html: item.answer_html }} />
                : <p className="m-0 whitespace-pre-line px-5 pb-5 text-[17px] leading-relaxed text-body">{item.answer}</p>}
            </details>
          ))}
        </div>
      </div>
    </section>
  );
}

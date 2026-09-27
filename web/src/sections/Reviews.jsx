import { useState } from "react";

const FIRST = 3;

function dateText(value) {
  if (!value) return "";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "" : date.toLocaleDateString("de-AT", { month: "long", year: "numeric" });
}

function Stars({ rating }) {
  const value = Math.max(1, Math.min(5, Math.round(Number(rating) || 0)));
  return (
    <span role="img" aria-label={`${value} von 5 Sternen`} className="text-[19px] tracking-[2px] text-accent">
      {"★".repeat(value)}<span className="text-line">{"★".repeat(5 - value)}</span>
    </span>
  );
}

/** Customer reviews (#51), entered in the admin, also from Google with a link
 *  to the original. Never invented; without reviews the section is hidden. */
export default function Reviews({ data, googleUrl }) {
  const [all, setAll] = useState(false);
  const reviews = (data?.reviews || []).filter((review) => !review.is_demo);
  if (!reviews.length) return null;
  const visible = all ? reviews : reviews.slice(0, FIRST);

  return (
    <section id="bewertungen" aria-labelledby="bewertungen-title">
      <div className="mx-auto flex max-w-page flex-col gap-5 px-5 pb-12 pt-6 md:gap-7 md:px-6 md:pb-24 md:pt-12">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div className="flex flex-col gap-2 md:gap-3">
            <div className="kicker">Bewertungen</div>
            <h2 id="bewertungen-title" className="m-0 text-[30px] font-bold md:text-[44px]">Was Kunden sagen</h2>
            {data?.average && (
              <p className="m-0 text-base text-body">
                {String(data.average).replace(".", ",")} von 5 Sternen · {reviews.length} {reviews.length === 1 ? "Bewertung" : "Bewertungen"}
              </p>
            )}
          </div>
          {googleUrl && (
            <a href={googleUrl} target="_blank" rel="noopener noreferrer" className="btn-outline min-h-[44px] self-start px-4 text-[15px] md:self-auto">
              Auf Google bewerten
            </a>
          )}
        </div>
        <ul className="m-0 grid list-none gap-4 p-0 md:grid-cols-2 md:gap-6 lg:grid-cols-3">
          {visible.map((review) => (
            <li key={review.id}>
              <figure className="card m-0 flex h-full flex-col gap-3.5 p-6 md:p-7">
                <Stars rating={review.rating} />
                <blockquote className="m-0 text-[17px] leading-[1.55] text-body">„{review.text}“</blockquote>
                <figcaption className="mt-auto flex flex-wrap justify-between gap-2 text-[15px]">
                  <b>{review.author}</b>
                  <span className="text-muted">
                    {review.source_url
                      ? <a href={review.source_url} target="_blank" rel="noopener noreferrer">{review.source || "Google"}</a>
                      : (review.source === "Website" ? "nach Auftrag"
                        : review.source && review.source !== "manuell" ? review.source : "persönlich")}
                    {dateText(review.review_date || review.created_at) && ` · ${dateText(review.review_date || review.created_at)}`}
                  </span>
                </figcaption>
              </figure>
            </li>
          ))}
        </ul>
        {reviews.length > FIRST && !all && (
          <button type="button" onClick={() => setAll(true)} className="btn-outline self-center text-base">
            Alle {reviews.length} Bewertungen anzeigen
          </button>
        )}
        {/* How reviews are checked: a customer must be told (UWG, reviews). */}
        <p className="m-0 max-w-[52em] text-sm leading-normal text-muted">
          Nur echte Bewertungen: Über die Website bewerten kann nur, wer nach einem abgeschlossenen Auftrag einen persönlichen Link bekommen hat.
          Bewertungen von Google sind mit dem Original verlinkt; persönlich oder per E-Mail erhaltene stehen hier so, wie sie geschrieben wurden.
        </p>
      </div>
    </section>
  );
}

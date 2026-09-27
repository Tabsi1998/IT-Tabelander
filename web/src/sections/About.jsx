import { ABOUT_TEXT, QUALIFICATIONS } from "../content/about.js";
import { useSite } from "../lib/site.jsx";

/** "Über mich": the admin's text, photo and qualifications (#58), with the
 *  built-in text as long as none is written. */
export default function About() {
  const { settings } = useSite();
  const paragraphs = String(settings.about_text || ABOUT_TEXT).split(/\n\s*\n/).map((part) => part.trim()).filter(Boolean);
  const qualifications = settings.about_qualifications?.length ? settings.about_qualifications : QUALIFICATIONS;
  return (
    <section id="ueber" aria-labelledby="ueber-title" className="bg-surface">
      <div className="mx-auto grid max-w-page items-center gap-8 px-5 py-12 md:px-6 md:py-[88px] lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-16">
        <div className="flex flex-col gap-4 md:gap-[18px]">
          <div className="kicker">Über mich</div>
          <h2 id="ueber-title" className="m-0 text-[28px] font-bold md:text-[40px]">Jemand, der Hardware wirklich versteht</h2>
          {paragraphs.map((paragraph) => (
            <p key={paragraph} className="m-0 whitespace-pre-line text-[17px] leading-relaxed text-body md:text-lg">{paragraph}</p>
          ))}
          <ul className="m-0 flex list-none flex-wrap gap-2.5 p-0">
            {qualifications.map((item) => (
              <li key={item} className="flex min-h-[38px] items-center rounded-full border border-line bg-page px-3.5 text-[15px] font-semibold">{item}</li>
            ))}
          </ul>
        </div>
        <div className="flex flex-col items-start gap-4 lg:items-center">
          {settings.about_photo_url && (
            <img src={settings.about_photo_url} alt="Foto aus der Werkstatt" loading="lazy" width="720" height="540"
              className="aspect-[4/3] h-auto w-full max-w-[360px] rounded-2xl object-cover" />
          )}
          <img src="/assets/img/certs/comptia-aplus.webp" alt="CompTIA A+ zertifiziert" width="240" height="219" loading="lazy"
            className="h-auto w-[120px] md:w-[160px]" />
        </div>
      </div>
    </section>
  );
}

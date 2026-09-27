const QUALIFICATIONS = ["CompTIA A+", "WIFI Tirol", "Netzwerkadministrator", "Systemadministrator"];

export default function About() {
  return (
    <section id="ueber" aria-labelledby="ueber-title" className="bg-surface">
      <div className="mx-auto grid max-w-page items-center gap-8 px-5 py-12 md:px-6 md:py-[88px] lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-16">
        <div className="flex flex-col gap-4 md:gap-[18px]">
          <div className="kicker">Über mich</div>
          <h2 id="ueber-title" className="m-0 text-[28px] font-bold md:text-[40px]">Jemand, der Hardware wirklich versteht</h2>
          <p className="m-0 text-[17px] leading-relaxed text-body md:text-lg">
            Ich bin PC-Techniker mit CompTIA-A+-Zertifizierung sowie Ausbildung in Netzwerk- und Systemadministration
            mit internationalem Zeugnis am WIFI Tirol. Mein Fokus: Probleme sauber analysieren und sinnvolle Lösungen
            finden. Wenn sich eine Reparatur nicht lohnt, sage ich das offen.
          </p>
          <ul className="m-0 flex list-none flex-wrap gap-2.5 p-0">
            {QUALIFICATIONS.map((item) => (
              <li key={item} className="flex min-h-[38px] items-center rounded-full border border-line bg-page px-3.5 text-[15px] font-semibold">{item}</li>
            ))}
          </ul>
        </div>
        <img src="/assets/img/certs/comptia-aplus.webp" alt="CompTIA A+ zertifiziert" width="240" height="219" loading="lazy"
          className="h-auto w-[120px] md:w-[160px] lg:justify-self-center" />
      </div>
    </section>
  );
}

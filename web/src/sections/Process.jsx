const STEPS = [
  { title: "Anfrage senden", text: "Gerät, Fehler, ein paar Fotos. Dauert zwei Minuten." },
  { title: "Diagnose", text: "Ich prüfe dein Gerät und melde mich mit einer ehrlichen Einschätzung." },
  { title: "Angebot annehmen", text: "Du bekommst ein klares Angebot. Ohne deine Zusage passiert nichts." },
  { title: "Reparatur oder Bau", text: "Den Fortschritt siehst du jederzeit über den Status." },
  { title: "Abholen oder Versand", text: "Du holst dein Gerät ab oder bekommst es geschickt. Bezahlt wird per Überweisung." },
];

/** From inquiry to pickup (Design A, navy band). */
export default function Process() {
  return (
    <section id="ablauf" aria-labelledby="ablauf-title" className="bg-navy text-on-navy">
      <div className="mx-auto flex max-w-page flex-col gap-8 px-5 py-12 md:gap-12 md:px-6 md:py-24">
        <div className="flex flex-col gap-2 md:gap-3">
          <div className="kicker text-on-navy-muted">Ablauf</div>
          <h2 id="ablauf-title" className="m-0 text-[30px] font-bold md:text-[44px]">Von der Anfrage bis zur Abholung</h2>
        </div>
        <div className="relative">
          <div aria-hidden="true" className="absolute left-5 right-5 top-5 hidden h-1 rounded bg-navy-line md:block" />
          <div aria-hidden="true" className="absolute left-5 top-5 hidden h-1 w-[40%] rounded bg-brand md:block" />
          <ol className="relative m-0 grid list-none gap-6 p-0 md:grid-cols-5">
            {STEPS.map((step, index) => (
              <li key={step.title} className="flex gap-4 md:flex-col md:gap-3.5">
                <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-[5px] font-display text-[15px] font-bold ${
                  index === 2 ? "border-brand bg-brand text-on-accent" : index < 2 ? "border-brand bg-navy text-on-navy" : "border-[#4A6380] bg-navy text-on-navy"}`}>
                  {index + 1}
                </span>
                <div className="flex flex-col gap-1.5 md:gap-3.5">
                  <h3 className="m-0 text-lg font-bold md:text-xl">{step.title}</h3>
                  <p className="m-0 text-base leading-normal text-on-navy-muted">{step.text}</p>
                </div>
              </li>
            ))}
          </ol>
        </div>
      </div>
    </section>
  );
}

import { Link } from "react-router";
import Footer from "../components/Footer.jsx";
import SimpleHeader from "./SimpleHeader.jsx";

export default function NotFound() {
  return (
    <div className="flex min-h-screen flex-col bg-page">
      <SimpleHeader />
      <main id="inhalt" className="mx-auto flex w-full max-w-page flex-1 flex-col items-start gap-4 px-5 py-14 md:px-6 md:py-24">
        <div className="kicker">Fehler 404</div>
        <h1 className="m-0 text-[32px] font-bold md:text-5xl">Diese Seite gibt es nicht.</h1>
        <p className="m-0 max-w-[34em] text-lg leading-normal text-body">
          Vielleicht ein alter Link. Alles Wichtige findest du auf der Startseite.
        </p>
        <div className="flex flex-col gap-3 sm:flex-row">
          <Link to="/" className="btn-primary min-h-[52px] text-base">Zur Startseite</Link>
          <Link to="/?kontakt=anfrage#kontakt" className="btn-outline min-h-[52px] text-base">Anfrage starten</Link>
        </div>
      </main>
      <Footer onHome={false} />
    </div>
  );
}

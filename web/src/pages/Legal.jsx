import { NavLink, useParams } from "react-router";
import Footer from "../components/Footer.jsx";
import { useApi } from "../lib/useApi.js";
import NotFound from "./NotFound.jsx";
import SimpleHeader from "./SimpleHeader.jsx";

export const LEGAL_PAGES = [
  { kind: "impressum", label: "Impressum" },
  { kind: "datenschutz", label: "Datenschutz" },
  { kind: "nutzungsbedingungen", label: "Nutzungsbedingungen" },
];

/** Imprint, privacy and terms as three tabs (#52). The texts come from
 *  Dolibarr (#74); a draft article carries a visible note. */
export default function Legal() {
  const { kind } = useParams();
  const page = LEGAL_PAGES.find((item) => item.kind === kind);
  const text = useApi(page ? `/legal/${page.kind}` : null);
  if (!page) return <NotFound />;

  return (
    <div className="flex min-h-screen flex-col bg-page">
      <SimpleHeader />
      <main id="inhalt" className="mx-auto flex w-full max-w-[880px] flex-col gap-5 px-5 py-8 md:gap-6 md:px-6 md:py-14">
        <h1 className="m-0 text-[30px] font-bold md:text-[40px]">Rechtliches</h1>
        <nav aria-label="Rechtliche Texte" className="grid grid-cols-3 rounded-xl bg-line-soft p-1">
          {LEGAL_PAGES.map((item) => (
            <NavLink key={item.kind} to={`/rechtliches/${item.kind}`}
              className={({ isActive }) => `flex min-h-[48px] items-center justify-center rounded-[9px] px-1 text-center text-[13px] no-underline sm:text-base ${
                isActive ? "bg-surface font-bold shadow-sm" : "font-semibold"}`}>
              {item.label}
            </NavLink>
          ))}
        </nav>
        <article className="card flex flex-col gap-4 p-5 md:gap-5 md:px-12 md:py-10">
          {text.data?.draft && (
            <p className="m-0 rounded-[10px] border border-[#FFD2B3] bg-badge p-3.5 text-[15px] font-semibold text-badge-ink">
              Entwurf: Dieser Text ist in Dolibarr noch nicht freigegeben.
            </p>
          )}
          <h2 className="m-0 text-2xl font-bold md:text-[30px]">{text.data?.title || page.label}</h2>
          {text.loading && <p className="m-0 text-body">Wird geladen …</p>}
          {text.error && (
            <p className="m-0 text-body">
              {text.error.status === 404
                ? "Dieser Text ist noch nicht hinterlegt."
                : "Der Text ist gerade nicht abrufbar. Bitte später noch einmal versuchen."}
            </p>
          )}
          {/* The backend cleans this HTML (nh3) before it leaves the server. */}
          {text.data?.html && <div className="legal-text" dangerouslySetInnerHTML={{ __html: text.data.html }} />}
        </article>
      </main>
      <Footer onHome={false} />
    </div>
  );
}

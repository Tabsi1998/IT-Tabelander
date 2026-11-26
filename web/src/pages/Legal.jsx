import { NavLink, useParams } from "react-router";
import Footer from "../components/Footer.jsx";
import { DownloadIcon } from "../components/Icons.jsx";
import { useApi } from "../lib/useApi.js";
import NotFound from "./NotFound.jsx";
import SimpleHeader from "./SimpleHeader.jsx";

export const LEGAL_PAGES = [
  { kind: "impressum", label: "Impressum", core: true },
  { kind: "datenschutz", label: "Datenschutz", core: true },
  { kind: "nutzungsbedingungen", label: "Nutzungsbedingungen", core: true },
  { kind: "widerruf", label: "Widerruf" },
  { kind: "agb-unternehmer", label: "AGB Unternehmen" },
];

/** Imprint, privacy and terms as tabs (#52), withdrawal notice and the terms
 *  for businesses once they are picked (#128). The texts come from Dolibarr
 *  (#74); a draft article carries a visible note, a released one may have a PDF. */
export default function Legal() {
  const { kind } = useParams();
  const page = LEGAL_PAGES.find((item) => item.kind === kind);
  const text = useApi(page ? `/legal/${page.kind}` : null);
  if (!page) return <NotFound />;
  const available = new Set((text.data?.pages || []).map((item) => item.kind));
  const tabs = LEGAL_PAGES.filter((item) => item.core || item.kind === page.kind || available.has(item.kind));
  // Three tabs share one row; with four or five they wrap by their length on a phone.
  const wrap = tabs.length > 3;

  return (
    <div className="flex min-h-screen flex-col bg-page print:min-h-0 print:bg-white">
      <div className="print:hidden"><SimpleHeader /></div>
      <main id="inhalt" className="mx-auto flex w-full max-w-[880px] flex-col gap-5 px-5 py-8 md:gap-6 md:px-6 md:py-14 print:max-w-none print:p-0">
        <h1 className="m-0 text-[30px] font-bold md:text-[40px] print:hidden">Rechtliches</h1>
        <nav aria-label="Rechtliche Texte" className={`${wrap ? "flex flex-wrap gap-1" : "grid grid-cols-3"} rounded-xl bg-line-soft p-1 print:hidden`}>
          {tabs.map((item) => (
            <NavLink key={item.kind} to={`/rechtliches/${item.kind}`}
              className={({ isActive }) => `flex min-h-[48px] items-center justify-center rounded-[9px] text-center text-[13px] no-underline sm:text-base ${
                wrap ? "grow px-3" : "px-1"} ${
                isActive ? "bg-surface font-bold shadow-sm" : "font-semibold"}`}>
              {item.label}
            </NavLink>
          ))}
        </nav>
        <article className="card flex flex-col gap-4 p-5 md:gap-5 md:px-12 md:py-10 print:border-0 print:p-0">
          {text.data?.draft && (
            <p className="m-0 rounded-[10px] border border-[#FFD2B3] bg-badge p-3.5 text-[15px] font-semibold text-badge-ink">
              Entwurf: Dieser Text ist in Dolibarr noch nicht freigegeben.
            </p>
          )}
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <h2 className="m-0 text-2xl font-bold md:text-[30px]">{text.data?.title || page.label}</h2>
            {text.data?.pdf && !text.data.draft && (
              <a href={`/api/legal/${page.kind}/pdf`} download
                className="btn-outline min-h-[44px] shrink-0 self-start px-4 text-[15px] print:hidden">
                <DownloadIcon size={18} />
                Als PDF herunterladen
              </a>
            )}
          </div>
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
      <div className="print:hidden"><Footer onHome={false} /></div>
    </div>
  );
}

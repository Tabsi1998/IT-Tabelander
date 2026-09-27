import { Link } from "react-router";
import Logo from "../components/Logo.jsx";

/** Header of the pages outside the one-pager: logo and the way back. */
export default function SimpleHeader() {
  return (
    <header className="border-b border-line bg-surface">
      <div className="mx-auto flex h-16 max-w-page items-center gap-3 px-4 md:h-[84px] md:px-6">
        <Link to="/" aria-label="IT-Tabelander – zur Startseite" className="flex">
          <Logo className="h-6 w-auto md:h-[34px]" />
        </Link>
        <Link to="/" className="btn-outline ml-auto min-h-[44px] px-4 text-[15px]">← Zur Startseite</Link>
      </div>
    </header>
  );
}

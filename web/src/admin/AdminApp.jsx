import { Navigate, Route, Routes, useLocation } from "react-router";
import ErrorBoundary from "./ErrorBoundary.jsx";
import Layout from "./Layout.jsx";
import Dolibarr from "./pages/Dolibarr.jsx";
import Gallery from "./pages/Gallery.jsx";
import Label from "./pages/Label.jsx";
import Login from "./pages/Login.jsx";
import Overview from "./pages/Overview.jsx";
import Reviews from "./pages/Reviews.jsx";
import ServiceEditor from "./pages/ServiceEditor.jsx";
import Services from "./pages/Services.jsx";
import Technik from "./pages/Technik.jsx";
import Texts from "./pages/Texts.jsx";
import { SessionProvider, useSession } from "./session.jsx";

function Pages() {
  const session = useSession();
  const location = useLocation();
  if (session.status === "checking") {
    return <div aria-busy="true" aria-label="Wird geladen" className="min-h-screen bg-page" />;
  }
  // Signed out: the sign-in form right here, so after signing in the same
  // page opens again (#57).
  if (session.status !== "in") return <Login />;
  return (
    <Layout>
      <ErrorBoundary key={location.pathname}>
        <Routes>
          <Route index element={<Overview />} />
          <Route path="texte" element={<Texts />} />
          <Route path="leistungen" element={<Services />} />
          <Route path="leistungen/:id" element={<ServiceEditor />} />
          <Route path="galerie" element={<Gallery />} />
          <Route path="bewertungen" element={<Reviews />} />
          <Route path="dolibarr" element={<Dolibarr />} />
          <Route path="technik" element={<Technik />} />
          <Route path="etikett" element={<Label />} />
          <Route path="etikett/:ref" element={<Label />} />
          <Route path="*" element={<Navigate to="/admin" replace />} />
        </Routes>
      </ErrorBoundary>
    </Layout>
  );
}

/** The admin (#57): its own part of the site, loaded only under /admin. */
export default function AdminApp() {
  return (
    <SessionProvider>
      <Pages />
    </SessionProvider>
  );
}

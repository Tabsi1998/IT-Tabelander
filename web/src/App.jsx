import { lazy, Suspense } from "react";
import { Navigate, Outlet, Route, Routes, useSearchParams } from "react-router";
import { SiteProvider } from "./lib/site.jsx";
import Home from "./pages/Home.jsx";
import Legal from "./pages/Legal.jsx";
import NotFound from "./pages/NotFound.jsx";
import Review from "./pages/Review.jsx";

// The admin is its own part of the build: visitors never download it (#57).
const AdminApp = lazy(() => import("./admin/AdminApp.jsx"));
// So is the customer area (#63): loaded only by those who open it.
const PortalApp = lazy(() => import("./portal/PortalApp.jsx"));

/** The old status link from Dolibarr's mails and the device label (#48):
 *  the customer area opens that inquiry after signing in (#122). The server
 *  redirects the same way; this covers navigation inside the app. */
function StatusLink() {
  const [search] = useSearchParams();
  const trackId = search.get("track_id") || "";
  const target = /^[A-Za-z0-9]{8,64}$/.test(trackId) ? `/kundenbereich/anfragen?track_id=${trackId}` : "/kundenbereich/anfragen";
  return <Navigate to={target} replace />;
}

function Site() {
  return (
    <SiteProvider>
      <Outlet />
    </SiteProvider>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/admin/*" element={<Suspense fallback={<div aria-busy="true" className="min-h-screen bg-page" />}><AdminApp /></Suspense>} />
      <Route element={<Site />}>
        <Route path="/" element={<Home />} />
        <Route path="/leistungen/:slug" element={<Home />} />
        <Route path="/status/view.php" element={<StatusLink />} />
        <Route path="/bewertung" element={<Review />} />
        <Route path="/kundenbereich/*" element={<Suspense fallback={<div aria-busy="true" className="min-h-screen bg-page" />}><PortalApp /></Suspense>} />
        <Route path="/rechtliches" element={<Navigate to="/rechtliches/impressum" replace />} />
        <Route path="/rechtliches/:kind" element={<Legal />} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  );
}

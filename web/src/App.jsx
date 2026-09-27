import { lazy, Suspense } from "react";
import { Navigate, Outlet, Route, Routes } from "react-router";
import { SiteProvider } from "./lib/site.jsx";
import Home from "./pages/Home.jsx";
import Legal from "./pages/Legal.jsx";
import NotFound from "./pages/NotFound.jsx";
import Review from "./pages/Review.jsx";

// The admin is its own part of the build: visitors never download it (#57).
const AdminApp = lazy(() => import("./admin/AdminApp.jsx"));

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
        <Route path="/status/view.php" element={<Home />} />
        <Route path="/bewertung" element={<Review />} />
        <Route path="/rechtliches" element={<Navigate to="/rechtliches/impressum" replace />} />
        <Route path="/rechtliches/:kind" element={<Legal />} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  );
}

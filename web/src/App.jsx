import { Navigate, Route, Routes } from "react-router";
import { SiteProvider } from "./lib/site.jsx";
import Home from "./pages/Home.jsx";
import Legal from "./pages/Legal.jsx";
import NotFound from "./pages/NotFound.jsx";

export default function App() {
  return (
    <SiteProvider>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/leistungen/:slug" element={<Home />} />
        <Route path="/status/view.php" element={<Home />} />
        <Route path="/rechtliches" element={<Navigate to="/rechtliches/impressum" replace />} />
        <Route path="/rechtliches/:kind" element={<Legal />} />
        <Route path="*" element={<NotFound />} />
      </Routes>
    </SiteProvider>
  );
}

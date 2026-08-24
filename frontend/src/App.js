import React, { Suspense, lazy } from "react";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import { HelmetProvider } from "react-helmet-async";
import { Toaster } from "sonner";

import { ThemeProvider } from "./context/ThemeContext";
import { AuthProvider } from "./context/AuthContext";
import { SettingsProvider } from "./context/SettingsContext";
import { ConsentProvider } from "./context/ConsentContext";
import Layout from "./components/Layout";
import Skeleton from "./components/ui/skeleton";

const Home = lazy(() => import("./pages/Home"));
const Leistungen = lazy(() => import("./pages/Leistungen"));
const ServiceLanding = lazy(() => import("./pages/ServiceLanding"));
const GamingPCInfo = lazy(() => import("./pages/GamingPCInfo"));
const PCConfigurator = lazy(() => import("./pages/PCConfigurator"));
const PS5Configurator = lazy(() => import("./pages/PS5Configurator"));
const UeberMich = lazy(() => import("./pages/UeberMich"));
const Bewertungen = lazy(() => import("./pages/Bewertungen"));
const Kontakt = lazy(() => import("./pages/Kontakt"));
const Reparatur = lazy(() => import("./pages/Reparatur"));
const Impressum = lazy(() => import("./pages/Impressum"));
const Datenschutz = lazy(() => import("./pages/Datenschutz"));
const NotFound = lazy(() => import("./pages/NotFound"));
const AdminApp = lazy(() => import("./pages/admin/AdminApp"));

const PageLoader = () => (
  <div className="mx-auto max-w-7xl px-4 pt-28">
    <Skeleton className="h-72 w-full" />
    <div className="mt-6 grid gap-4 md:grid-cols-3">
      <Skeleton className="h-40" />
      <Skeleton className="h-40" />
      <Skeleton className="h-40" />
    </div>
  </div>
);

const Site = ({ children }) => (
  <Layout>
    <Suspense fallback={<PageLoader />}>{children}</Suspense>
  </Layout>
);

function App() {
  return (
    <HelmetProvider>
      <ThemeProvider>
        <SettingsProvider>
          <ConsentProvider>
            <AuthProvider>
              <BrowserRouter>
                <Toaster position="bottom-right" richColors theme="dark" />
                <Routes>
                  <Route path="/" element={<Site><Home /></Site>} />
                  <Route path="/leistungen" element={<Site><Leistungen /></Site>} />
                  <Route path="/pc-reparatur" element={<Site><ServiceLanding slug="pc-reparatur" /></Site>} />
                  <Route path="/notebook-reparatur" element={<Site><ServiceLanding slug="notebook-reparatur" /></Site>} />
                  <Route path="/pc-aufruestung" element={<Site><ServiceLanding slug="pc-aufruestung" /></Site>} />
                  <Route path="/konsolen-reparatur" element={<Site><ServiceLanding slug="konsolen-reparatur" /></Site>} />
                  <Route path="/controller-reparatur" element={<Site><ServiceLanding slug="controller-reparatur" /></Site>} />
                  <Route path="/gaming-pc" element={<Site><GamingPCInfo /></Site>} />
                  <Route path="/gaming-pc-konfigurator" element={<Site><PCConfigurator /></Site>} />
                  <Route path="/ps5-controller-konfigurator" element={<Site><PS5Configurator /></Site>} />
                  <Route path="/ueber-mich" element={<Site><UeberMich /></Site>} />
                  <Route path="/bewertungen" element={<Site><Bewertungen /></Site>} />
                  <Route path="/kontakt" element={<Site><Kontakt /></Site>} />
                  <Route path="/reparatur" element={<Site><Reparatur /></Site>} />
                  <Route path="/impressum" element={<Site><Impressum /></Site>} />
                  <Route path="/datenschutz" element={<Site><Datenschutz /></Site>} />
                  <Route
                    path="/admin/*"
                    element={
                      <Suspense fallback={<PageLoader />}>
                        <AdminApp />
                      </Suspense>
                    }
                  />
                  <Route path="*" element={<Site><NotFound /></Site>} />
                </Routes>
              </BrowserRouter>
            </AuthProvider>
          </ConsentProvider>
        </SettingsProvider>
      </ThemeProvider>
    </HelmetProvider>
  );
}

export default App;

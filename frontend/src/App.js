import React, { Suspense, lazy } from "react";
import { BrowserRouter, Navigate, Routes, Route } from "react-router-dom";
import { HelmetProvider } from "react-helmet-async";
import { Toaster } from "sonner";

import { ThemeProvider } from "./context/ThemeContext";
import { useTheme } from "./context/ThemeContext";
import { AuthProvider } from "./context/AuthContext";
import { SettingsProvider } from "./context/SettingsContext";
import Skeleton from "./components/ui/skeleton";

// Only the admin lives here until the new admin of milestone 4 (#57); the
// public website is the new one in web/ (#54).
const AdminApp = lazy(() => import("./pages/admin/AdminApp"));

const PageLoader = () => (
  <div className="mx-auto max-w-7xl px-4 pt-28">
    <Skeleton className="h-72 w-full" />
  </div>
);

const ThemedToaster = () => {
  const { theme } = useTheme();
  return <Toaster position="bottom-right" richColors theme={theme} />;
};

function App() {
  return (
    <HelmetProvider>
      <ThemeProvider>
        <SettingsProvider>
          <AuthProvider>
            <BrowserRouter>
              <ThemedToaster />
              <Routes>
                <Route
                  path="/admin/*"
                  element={
                    <Suspense fallback={<PageLoader />}>
                      <AdminApp />
                    </Suspense>
                  }
                />
                <Route path="*" element={<Navigate to="/admin" replace />} />
              </Routes>
            </BrowserRouter>
          </AuthProvider>
        </SettingsProvider>
      </ThemeProvider>
    </HelmetProvider>
  );
}

export default App;

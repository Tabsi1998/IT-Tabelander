import React from "react";
import { Routes, Route } from "react-router-dom";
import { useAuth } from "../../context/AuthContext";
import Skeleton from "../../components/ui/skeleton";
import AdminLogin from "./AdminLogin";
import AdminLayout from "./AdminLayout";
import Dashboard from "./Dashboard";
import AdminRepairs from "./AdminRepairs";
import AdminContact from "./AdminContact";
import AdminServices from "./AdminServices";
import AdminFaqs from "./AdminFaqs";
import AdminReviews from "./AdminReviews";
import AdminConfigurator from "./AdminConfigurator";
import AdminMedia from "./AdminMedia";
import AdminDolibarr from "./AdminDolibarr";
import AdminSettings from "./AdminSettings";

export default function AdminApp() {
  const { user, ready } = useAuth();

  if (!ready) {
    return (
      <div className="dark min-h-screen bg-[#070d18] p-10">
        <Skeleton className="mx-auto h-96 max-w-4xl" />
      </div>
    );
  }

  if (!user) return <AdminLogin />;

  return (
    <AdminLayout>
      <Routes>
        <Route index element={<Dashboard />} />
        <Route path="reparaturen" element={<AdminRepairs />} />
        <Route path="kontakt" element={<AdminContact />} />
        <Route path="leistungen" element={<AdminServices />} />
        <Route path="faqs" element={<AdminFaqs />} />
        <Route path="bewertungen" element={<AdminReviews />} />
        <Route path="konfigurator" element={<AdminConfigurator />} />
        <Route path="medien" element={<AdminMedia />} />
        <Route path="dolibarr" element={<AdminDolibarr />} />
        <Route path="einstellungen" element={<AdminSettings />} />
      </Routes>
    </AdminLayout>
  );
}

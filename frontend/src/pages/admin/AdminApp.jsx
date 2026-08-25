import React from "react";
import { Navigate, Routes, Route } from "react-router-dom";
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
import AdminMedia from "./AdminMedia";
import AdminDolibarr from "./AdminDolibarr";
import AdminSettings from "./AdminSettings";

export default function AdminApp() {
  const { user, ready } = useAuth();

  if (!ready) {
    return (
      <div className="min-h-screen bg-canvas p-10">
        <Skeleton className="mx-auto h-96 max-w-4xl" />
      </div>
    );
  }

  if (!user) return <AdminLogin />;

  return (
    <AdminLayout>
      <Routes>
        <Route index element={<Dashboard />} />
        <Route path="anfragen" element={<AdminRepairs />} />
        <Route path="reparaturen" element={<Navigate to="/admin/anfragen" replace />} />
        <Route path="konfigurator" element={<Navigate to="/admin/anfragen" replace />} />
        <Route path="controller-builder" element={<Navigate to="/admin/anfragen" replace />} />
        <Route path="kontakt" element={<AdminContact />} />
        <Route path="leistungen" element={<AdminServices />} />
        <Route path="faqs" element={<AdminFaqs />} />
        <Route path="bewertungen" element={<AdminReviews />} />
        <Route path="medien" element={<AdminMedia />} />
        <Route path="dolibarr" element={<AdminDolibarr />} />
        <Route path="einstellungen" element={<AdminSettings />} />
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
    </AdminLayout>
  );
}

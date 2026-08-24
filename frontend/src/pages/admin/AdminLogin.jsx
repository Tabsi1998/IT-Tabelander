import React, { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Lock, Loader2, LogIn } from "lucide-react";
import { toast } from "sonner";
import { useAuth } from "../../context/AuthContext";
import { formatApiError } from "../../lib/api";
import { Button } from "../../components/ui/button";
import { Input, Label } from "../../components/ui/input";
import Seo from "../../components/Seo";

export default function AdminLogin() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await login(email, password);
      toast.success("Willkommen zurück!");
      navigate("/admin");
    } catch (err) {
      toast.error(formatApiError(err.response?.data?.detail));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="dark flex min-h-screen items-center justify-center bg-[#070d18] p-4">
      <Seo title="Admin Login" path="/admin" />
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <img src="/assets/img/logo/banner-light.png" alt="IT-Tabelander" className="mx-auto h-10" />
          <p className="mt-3 font-mono text-xs uppercase tracking-[0.3em] text-slate-500">Admin-Bereich</p>
        </div>
        <form onSubmit={submit} className="glass rounded-2xl border border-subtle p-8 shadow-card" data-testid="admin-login-form">
          <div className="mb-5 flex items-center gap-2 text-brand">
            <Lock size={18} /> <span className="font-heading font-semibold">Anmeldung</span>
          </div>
          <div className="space-y-4">
            <div>
              <Label>E-Mail</Label>
              <Input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} data-testid="admin-email" placeholder="admin@it-tabelander.at" />
            </div>
            <div>
              <Label>Passwort</Label>
              <Input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} data-testid="admin-password" placeholder="••••••••" />
            </div>
          </div>
          <Button type="submit" className="mt-6 w-full" disabled={loading} data-testid="admin-login-submit">
            {loading ? <Loader2 className="animate-spin" size={16} /> : <LogIn size={16} />} Anmelden
          </Button>
        </form>
      </div>
    </div>
  );
}

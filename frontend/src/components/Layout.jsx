import React, { useEffect } from "react";
import { useLocation } from "react-router-dom";
import Header from "./Header";
import Footer from "./Footer";
import ConsentBanner from "./ConsentBanner";
import { useConsent } from "../context/ConsentContext";

export function Layout({ children }) {
  const { pathname } = useLocation();
  const { hasChoice } = useConsent();
  useEffect(() => {
    window.scrollTo(0, 0);
  }, [pathname]);

  return (
    <div className="relative min-h-screen bg-canvas">
      <Header />
      <main className={hasChoice ? "" : "pb-40 md:pb-32"}>{children}</main>
      <Footer />
      <ConsentBanner />
    </div>
  );
}

export default Layout;

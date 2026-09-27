import { useCallback, useEffect, useMemo, useState } from "react";
import { useLocation, useParams, useSearchParams } from "react-router";
import Footer from "../components/Footer.jsx";
import Header from "../components/Header.jsx";
import { ContactContext } from "../lib/contact.jsx";
import { useSite } from "../lib/site.jsx";
import { useApi } from "../lib/useApi.js";
import About from "../sections/About.jsx";
import Contact, { CONTACT_TABS } from "../sections/Contact.jsx";
import Hero from "../sections/Hero.jsx";
import Process from "../sections/Process.jsx";
import Reviews from "../sections/Reviews.jsx";
import Services from "../sections/Services.jsx";
import Workshop from "../sections/Workshop.jsx";

function scrollTo(id) {
  document.getElementById(id)?.scrollIntoView({ block: "start" });
}

/** The one-pager (#46): every section on one page, services and contact as tabs. */
export default function Home() {
  const { slug } = useParams();
  const location = useLocation();
  const [search] = useSearchParams();
  const { settings } = useSite();
  const gallery = useApi("/gallery");
  const reviews = useApi("/reviews");

  // /status/view.php?track_id=… is the link in Dolibarr's confirmation mail (#48).
  const trackId = location.pathname === "/status/view.php" ? search.get("track_id") || "" : "";
  const askedTab = search.get("kontakt");
  const [tab, setTab] = useState(trackId ? "status" : CONTACT_TABS.some((item) => item.key === askedTab) ? askedTab : "nachricht");
  const [preset, setPreset] = useState(null);
  const [statusFor, setStatusFor] = useState(null);

  const openContact = useCallback((next, nextPreset) => {
    setTab(next);
    if (nextPreset) setPreset(nextPreset);
    scrollTo("kontakt");
  }, []);
  const contextValue = useMemo(() => ({ openContact }), [openContact]);

  useEffect(() => {
    if (slug) scrollTo("leistungen");
    else if (trackId || askedTab || location.hash === "#kontakt") scrollTo("kontakt");
    else if (location.hash) scrollTo(location.hash.slice(1));
  }, [slug, trackId, askedTab, location.hash]);

  const galleryItems = gallery.data?.items || [];
  const reviewCount = (reviews.data?.reviews || []).filter((review) => !review.is_demo).length;
  const sections = [
    { key: "leistungen", label: "Leistungen" },
    { key: "ablauf", label: "Ablauf" },
    ...(galleryItems.length ? [{ key: "werkstatt", label: "Werkstatt" }] : []),
    ...(reviewCount ? [{ key: "bewertungen", label: "Bewertungen" }] : []),
    { key: "ueber", label: "Über mich" },
    { key: "kontakt", label: "Kontakt" },
  ];

  return (
    <ContactContext.Provider value={contextValue}>
      <a href="#inhalt" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-[60] focus:rounded focus:bg-surface focus:p-3">
        Zum Inhalt springen
      </a>
      <Header sections={sections} />
      <main id="inhalt">
        <Hero />
        <Services initialSlug={slug} />
        <Process />
        <Workshop items={galleryItems} />
        <Reviews data={reviews.data} googleUrl={settings.google_review_url} />
        <About />
        <Contact
          tab={tab}
          onTab={setTab}
          preset={preset}
          statusFor={statusFor}
          trackId={trackId}
          onShowStatus={(ref, email) => { setStatusFor({ ref, email }); setTab("status"); }}
        />
      </main>
      <Footer />
    </ContactContext.Provider>
  );
}

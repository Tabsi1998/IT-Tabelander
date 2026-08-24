import React, { createContext, useContext, useEffect, useState } from "react";
import { useSettings } from "./SettingsContext";

const ConsentContext = createContext();

const KEY = "it_consent_v1";

export function ConsentProvider({ children }) {
  const { settings } = useSettings();
  const [consent, setConsent] = useState(() => {
    try {
      return JSON.parse(localStorage.getItem(KEY)) || null;
    } catch {
      return null;
    }
  });

  const save = (value) => {
    const payload = { ...value, ts: Date.now() };
    localStorage.setItem(KEY, JSON.stringify(payload));
    setConsent(payload);
  };

  const acceptAll = () => save({ necessary: true, statistics: true, external: true });
  const rejectAll = () => save({ necessary: true, statistics: false, external: false });

  // Load GA4 only when statistics consent + measurement id present
  useEffect(() => {
    const gaId = settings?.ga_measurement_id;
    if (!gaId || !consent?.statistics) return;
    if (window.__gaLoaded) return;
    window.__gaLoaded = true;
    const s = document.createElement("script");
    s.async = true;
    s.src = `https://www.googletagmanager.com/gtag/js?id=${gaId}`;
    document.head.appendChild(s);
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () {
      window.dataLayer.push(arguments);
    };
    window.gtag("js", new Date());
    window.gtag("config", gaId, { anonymize_ip: true });
  }, [settings, consent]);

  return (
    <ConsentContext.Provider value={{ consent, acceptAll, rejectAll, save, hasChoice: !!consent }}>
      {children}
    </ConsentContext.Provider>
  );
}

export const useConsent = () => useContext(ConsentContext);

export function trackEvent(name, params = {}) {
  try {
    if (window.gtag) window.gtag("event", name, params);
  } catch {}
}

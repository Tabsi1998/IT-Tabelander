import { createContext, useContext, useMemo } from "react";
import { useApi } from "./useApi.js";

const SiteContext = createContext({ company: null, openingHours: [], settings: {} });

/** Company data and opening hours come from Dolibarr through the backend
 *  (#74); the website's own settings add what only the website knows. */
export function SiteProvider({ children }) {
  const info = useApi("/site-info");
  const settings = useApi("/settings");
  const value = useMemo(() => ({
    company: info.data?.company || null,
    openingHours: info.data?.opening_hours || [],
    settings: settings.data || {},
  }), [info.data, settings.data]);
  return <SiteContext.Provider value={value}>{children}</SiteContext.Provider>;
}

export function useSite() {
  return useContext(SiteContext);
}

export function telHref(phone) {
  return `tel:${String(phone || "").replace(/[^+\d]/g, "")}`;
}

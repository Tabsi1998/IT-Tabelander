import React, { createContext, useContext, useEffect, useState } from "react";
import api from "../lib/api";

const SettingsContext = createContext();

export function SettingsProvider({ children }) {
  const [settings, setSettings] = useState(null);

  useEffect(() => {
    api
      .get("/settings")
      .then(({ data }) => setSettings(data))
      .catch(() => setSettings({}));
  }, []);

  return (
    <SettingsContext.Provider value={{ settings: settings || {}, loaded: settings !== null }}>
      {children}
    </SettingsContext.Provider>
  );
}

export const useSettings = () => useContext(SettingsContext);

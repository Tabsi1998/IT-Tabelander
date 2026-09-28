import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { adminRequest } from "./api.js";

const SessionContext = createContext(null);

/** Who is signed in. A session that runs out mid-work ends with a message
 *  and the way back to this very page after signing in again (#57). */
export function SessionProvider({ children }) {
  const [state, setState] = useState({ status: "checking", user: null, notice: "" });

  useEffect(() => {
    adminRequest("GET", "/auth/me", undefined, { quiet: true })
      .then(({ user }) => setState({ status: "in", user, notice: "" }))
      .catch(() => setState({ status: "out", user: null, notice: "" }));
    const expired = () => setState({ status: "out", user: null,
      notice: "Deine Anmeldung ist abgelaufen. Bitte melde dich neu an – danach geht es hier weiter." });
    window.addEventListener("admin-session-expired", expired);
    return () => window.removeEventListener("admin-session-expired", expired);
  }, []);

  const login = useCallback(async (email, password) => {
    const { user } = await adminRequest("POST", "/auth/login", { email, password });
    setState({ status: "in", user, notice: "" });
  }, []);

  const logout = useCallback(async () => {
    try {
      await adminRequest("POST", "/auth/logout", undefined, { quiet: true });
    } finally {
      setState({ status: "out", user: null, notice: "Du bist abgemeldet." });
    }
  }, []);

  const setUser = useCallback((user) => setState((current) => ({ ...current, user })), []);
  const value = useMemo(() => ({ ...state, login, logout, setUser }), [state, login, logout, setUser]);
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  return useContext(SessionContext);
}

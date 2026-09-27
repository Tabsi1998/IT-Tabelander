import { useCallback, useEffect, useState } from "react";
import { ApiError } from "../lib/api.js";

// Field names of the backend in the owner's words, for readable errors (#57).
const FIELDS = {
  email: "E-Mail", password: "Passwort", current_password: "Aktuelles Passwort", new_password: "Neues Passwort",
  title: "Name", heading: "Überschrift", long_description: "Text", bullets: "Stichpunkte",
  author: "Name", rating: "Sterne", text: "Text", source_url: "Link zur Bewertung", review_date: "Datum",
  caption: "Bildtext", category: "Bereich", smtp_host: "Mailserver", smtp_port: "Port", smtp_from: "Absender-Adresse",
  warning_email: "Warnungen an", google_review_url: "Google-Profil", canonical_base_url: "Öffentliche Adresse",
  dolibarr_base_url: "Dolibarr-Adresse", dolibarr_timeout_seconds: "Wartezeit", about_text: "Text",
  about_qualifications: "Qualifikationen", about_photo_url: "Foto",
};

async function detailText(response) {
  try {
    const body = await response.json();
    if (typeof body?.detail === "string") return body.detail;
    if (Array.isArray(body?.detail)) {
      return body.detail.map((item) => {
        const field = FIELDS[item.loc?.[item.loc.length - 1]];
        const message = String(item.msg || "").replace(/^Value error, /, "");
        return field ? `${field}: ${message}` : message;
      }).join(" · ");
    }
  } catch {
    // no JSON
  }
  if (response.status === 413) return "Die Datei ist zu groß.";
  if (response.status >= 500) return "Der Server hat einen Fehler gemeldet. Bitte gleich noch einmal versuchen.";
  return `Fehler ${response.status}`;
}

let refreshing = null;

function send(method, path, body, signal) {
  const init = { method, headers: { Accept: "application/json" }, credentials: "same-origin", signal };
  if (body instanceof FormData) init.body = body;
  else if (body !== undefined) {
    init.headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body);
  }
  return fetch(`/api${path}`, init);
}

/** An admin request. After an hour the access cookie runs out; one refresh
 *  renews it silently and the request goes out again (#57). Only when that
 *  fails too, the session ends - with a message, never a silent empty page. */
export async function adminRequest(method, path, body, { signal, quiet = false } = {}) {
  let response = await send(method, path, body, signal);
  if (response.status === 401 && !path.startsWith("/auth/login")) {
    refreshing ||= fetch("/api/auth/refresh", { method: "POST", credentials: "same-origin" })
      .finally(() => { refreshing = null; });
    const refreshed = await refreshing;
    if (refreshed.ok) response = await send(method, path, body, signal);
    if (response.status === 401) {
      if (!quiet) window.dispatchEvent(new Event("admin-session-expired"));
      throw new ApiError(401, "Deine Anmeldung ist abgelaufen. Bitte melde dich neu an.");
    }
  }
  if (!response.ok) throw new ApiError(response.status, await detailText(response));
  const type = response.headers.get("content-type") || "";
  return type.includes("application/json") ? response.json() : null;
}

/** Any error as a sentence; never an object on the screen (#57). */
export function errorText(error) {
  if (!error) return "";
  if (error.name === "TypeError") return "Keine Verbindung zum Server. Bitte die Internetverbindung prüfen.";
  return error.message || "Das hat nicht geklappt.";
}

/** Load admin data; `reload` fetches again, `setData` keeps local edits. */
export function useAdminData(path) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const load = useCallback(async (signal) => {
    setState((current) => ({ ...current, loading: true, error: null }));
    try {
      const data = await adminRequest("GET", path, undefined, { signal });
      setState({ data, error: null, loading: false });
    } catch (error) {
      if (error.name !== "AbortError") setState({ data: null, error, loading: false });
    }
  }, [path]);
  useEffect(() => {
    const controller = new AbortController();
    load(controller.signal);
    return () => controller.abort();
  }, [load]);
  const setData = useCallback((update) => setState((current) => ({
    ...current, data: typeof update === "function" ? update(current.data) : update,
  })), []);
  return { ...state, reload: () => load(), setData };
}

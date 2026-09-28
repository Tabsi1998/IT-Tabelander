import { useCallback, useEffect, useState } from "react";
import { ApiError } from "../lib/api.js";

async function detailText(response) {
  try {
    const body = await response.json();
    if (typeof body?.detail === "string") return body.detail;
    if (Array.isArray(body?.detail)) return body.detail.map((item) => String(item.msg || "").replace(/^Value error, /, "")).join(" ");
  } catch {
    // no JSON
  }
  if (response.status === 429) return "Zu viele Versuche in kurzer Zeit. Bitte in einer Stunde noch einmal.";
  if (response.status >= 500) return "Der Kundenbereich ist gerade nicht erreichbar. Bitte versuch es später noch einmal.";
  return `Fehler ${response.status}`;
}

/** A request of the customer area; the session is a cookie only it gets (#63). */
export async function portalRequest(method, path, body, { signal } = {}) {
  const init = { method, headers: { Accept: "application/json" }, credentials: "same-origin", signal };
  if (body !== undefined) {
    init.headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body);
  }
  const response = await fetch(`/api/portal${path}`, init);
  if (!response.ok) {
    if (response.status === 401 && path !== "/me") window.dispatchEvent(new Event("portal-signed-out"));
    throw new ApiError(response.status, await detailText(response));
  }
  return response.json();
}

export function usePortalData(path) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const load = useCallback((signal) => {
    setState((current) => ({ ...current, loading: true, error: null }));
    return portalRequest("GET", path, undefined, { signal })
      .then((data) => setState({ data, error: null, loading: false }))
      .catch((error) => {
        if (error?.name !== "AbortError") setState({ data: null, error, loading: false });
      });
  }, [path]);
  useEffect(() => {
    const controller = new AbortController();
    load(controller.signal);
    return () => controller.abort();
  }, [load]);
  return { ...state, reload: () => load() };
}

const EURO = new Intl.NumberFormat("de-AT", { style: "currency", currency: "EUR" });

export function money(value) {
  const number = Number(value);
  return Number.isFinite(number) ? EURO.format(number) : "–";
}

export function day(value) {
  const date = value ? new Date(value) : null;
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString("de-AT") : "";
}

/** "AT611904300234573201" in blocks of four, as on a bank card. */
export function ibanText(iban) {
  return String(iban || "").replace(/(.{4})/g, "$1 ").trim();
}

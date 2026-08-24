import axios from "axios";

const BASE = (process.env.REACT_APP_BACKEND_URL || "").replace(/\/$/, "");

const api = axios.create({
  baseURL: `${BASE}/api`,
  withCredentials: true,
});

// attach bearer token if present (fallback to cookie auth)
api.interceptors.request.use((config) => {
  const token = localStorage.getItem("it_token");
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

export function setToken(token) {
  if (token) localStorage.setItem("it_token", token);
  else localStorage.removeItem("it_token");
}

export function mediaUrl(path) {
  if (!path) return "";
  if (path.startsWith("http")) return path;
  if (path.startsWith("/api/")) return `${BASE}${path}`;
  return path; // static assets served from public/
}

export function formatApiError(detail) {
  if (detail == null) return "Etwas ist schiefgelaufen. Bitte erneut versuchen.";
  if (typeof detail === "string") return detail;
  if (Array.isArray(detail))
    return detail.map((e) => (e && typeof e.msg === "string" ? e.msg : JSON.stringify(e))).join(" ");
  if (detail && typeof detail.msg === "string") return detail.msg;
  return String(detail);
}

export default api;

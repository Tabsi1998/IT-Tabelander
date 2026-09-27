// The website talks to its own backend on the same address; no other server.

export class ApiError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

async function detailOf(response) {
  try {
    const body = await response.json();
    if (typeof body?.detail === "string") return body.detail;
    if (Array.isArray(body?.detail)) return body.detail.map((item) => item.msg).filter(Boolean).join(" ");
  } catch {
    // no JSON body
  }
  return "";
}

export async function getJson(path, { signal } = {}) {
  const response = await fetch(`/api${path}`, { headers: { Accept: "application/json" }, signal });
  if (!response.ok) {
    throw new ApiError(response.status, (await detailOf(response)) || `Fehler ${response.status}`);
  }
  return response.json();
}

export async function postJson(path, body, { signal } = {}) {
  const response = await fetch(`/api${path}`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(body),
    signal,
  });
  if (!response.ok) {
    throw new ApiError(response.status, (await detailOf(response)) || `Fehler ${response.status}`);
  }
  return response.json();
}

/** A random id per form, so a double click or a lost answer never sends twice. */
export function requestId(prefix = "web") {
  const random = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return `${prefix}-${random}`;
}

/** A photo for an inquiry draft; only this draft (and the admin) can see it. */
export async function uploadPhoto(file, draftId) {
  const body = new FormData();
  body.append("file", file);
  body.append("request_id", draftId);
  const response = await fetch("/api/uploads/repair-attachment", { method: "POST", body });
  if (!response.ok) {
    throw new ApiError(response.status, (await detailOf(response)) || `Fehler ${response.status}`);
  }
  return response.json();
}

export async function deletePhoto(photoId, draftId) {
  const query = new URLSearchParams({ request_id: draftId });
  await fetch(`/api/uploads/repair-attachment/${encodeURIComponent(photoId)}?${query}`, { method: "DELETE" });
}

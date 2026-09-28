import { test as base, expect } from "@playwright/test";

export const COMPANY = {
  name: "IT-Tabelander", managers: "Fabian Tabelander", address: "Teststraße 1", zip: "6410", town: "Telfs",
  country_code: "AT", phone: "+43 676 0000000", email: "office@example.at",
};

export const SERVICES = [
  { slug: "pc-reparatur", title: "PC-Reparatur", heading: "PC-Reparatur", short_description: "Diagnose statt Rätselraten",
    long_description: "Ich finde die Ursache.", bullets: ["Diagnose", "Kühlung"], active: true, image_url: "/assets/img/services/pc-laptop-reparatur-1200.webp" },
  { slug: "pc-bau", title: "PC nach Wunsch", heading: "PC nach Wunsch", short_description: "Dein PC, passend gebaut",
    long_description: "Gaming, Office, Workstation.", bullets: ["Gaming-PC", "Office-PC"], active: true, image_url: "/assets/img/services/service-overview-1200.webp" },
  { slug: "controller-reparatur", title: "Controller", heading: "Controller", short_description: "Stick Drift adé",
    long_description: "Reparatur und Umbau.", bullets: ["Hall-Effect"], active: true },
];

export function photos(count) {
  const areas = [["pc_build", "PC-Bau"], ["repair", "Reparatur"], ["controller", "Controller"]];
  return Array.from({ length: count }, (_, index) => ({
    id: `g${index}`, image_url: "/assets/img/services/service-overview-640.webp",
    caption: `Projekt ${index + 1}`, category: areas[index % 3][0], category_label: areas[index % 3][1],
  }));
}

export function reviews(count) {
  return Array.from({ length: count }, (_, index) => ({
    id: `r${index}`, author: `Kunde ${index + 1}`, text: `Sehr zufrieden, Nummer ${index + 1}.`, rating: 5,
    source: index === 0 ? "Google" : "manuell", source_url: index === 0 ? "https://g.page/r/beispiel" : "",
  }));
}

// The personal link from the review request mail (#71): /bewertung#<token>.
export const REVIEW_LINK = "Beispiel-Link-nur-fuer-Tests-000000000000";

/** Answers every /api request of the page; `sent` records what the page posted. */
export async function mockApi(page, { gallery = 3, reviewCount = 3, legal = {}, status, portal = false } = {}) {
  const sent = [];
  const json = (route, body, statusCode = 200) => route.fulfill({ status: statusCode, contentType: "application/json", body: JSON.stringify(body) });
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace(/^\/api/, "");
    const method = request.method();
    if (method !== "GET") {
      let body = null;
      try { body = request.postDataJSON(); } catch { /* a photo upload is multipart, not JSON */ }
      sent.push({ method, path, body, raw: request.postData() });
    }
    if (path === "/site-info") return json(route, { company: COMPANY, opening_hours: [{ day: "Montag", hours: "09:00–17:00" }], faq: [] });
    if (path === "/settings") {
      return json(route, { canonical_base_url: "https://it.tabelander.co.at", google_review_url: "https://g.page/r/bewerten", portal_enabled: portal });
    }
    if (path === "/services") return json(route, SERVICES);
    if (path === "/gallery") return json(route, { items: photos(gallery) });
    if (path === "/reviews") return json(route, { reviews: reviews(reviewCount), average: reviewCount ? 5 : null, count: reviewCount });
    if (path.startsWith("/legal/")) {
      const kind = path.split("/").pop();
      if (legal[kind] === undefined) return json(route, { detail: "Noch nicht hinterlegt." }, 404);
      return json(route, { kind, title: legal[kind].title, html: legal[kind].html, draft: Boolean(legal[kind].draft) });
    }
    if (path === "/contact" && method === "POST") return json(route, { ok: true, ref: "ANF-KONTAKT1", dolibarr_synced: true });
    if (path === "/uploads/repair-attachment" && method === "POST") return json(route, { id: "6523f0000000000000000001", url: "/api/media/x.webp" });
    if (path.startsWith("/uploads/repair-attachment/") && method === "DELETE") return json(route, { ok: true });
    if (path === "/inquiries" && method === "POST") return json(route, { ok: true, ref: "ANF-NEU12345", dolibarr_synced: true });
    if (path === "/inquiries/status" && method === "POST") {
      const body = request.postDataJSON();
      if (body.email !== "kunde@example.at") return json(route, { detail: "Keine Anfrage mit dieser Nummer und E-Mail gefunden." }, 404);
      return json(route, { ref: body.ref, request_type: "repair", request_type_label: "Reparatur", step: status || "eingegangen",
        created_at: "2026-09-27T10:00:00+00:00", updated_at: "2026-09-27T12:00:00+00:00" });
    }
    if (path === "/review-invites/check" && method === "POST") {
      if (request.postDataJSON().token !== REVIEW_LINK) {
        return json(route, { detail: "Dieser Link ist abgelaufen oder wurde schon verwendet." }, 404);
      }
      return json(route, { ref: "ANF-BEWERT01", request_type_label: "Reparatur" });
    }
    if (path === "/review-invites/submit" && method === "POST") return json(route, { ok: true });
    if (path.startsWith("/inquiries/status/track/")) {
      return json(route, { ref: "ANF-LINK0001", request_type: "repair", request_type_label: "Reparatur", step: "in_arbeit" });
    }
    return json(route, { detail: "Nicht im Test vorgesehen" }, 404);
  });
  return sent;
}

export const test = base.extend({
  /** Requests to any server but this site's own (#45). */
  foreign: async ({ page, baseURL }, use) => {
    const foreign = [];
    page.on("request", (request) => {
      const url = request.url();
      if (!url.startsWith(baseURL) && !url.startsWith("data:") && !url.startsWith("blob:")) foreign.push(url);
    });
    await use(foreign);
  },
  /** Every test fails on a breach of the security policy (#54). */
  noPolicyBreach: [async ({ page }, use) => {
    const breaches = [];
    page.on("console", (message) => {
      if (/Content Security Policy/i.test(message.text())) breaches.push(message.text());
    });
    await use(breaches);
    expect(breaches, "the page breaks its Content-Security-Policy").toEqual([]);
  }, { auto: true }],
});

export async function expectNoSidewaysScroll(page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, "the page must not scroll sideways").toBeLessThanOrEqual(0);
  // Nothing cut off (#55): headings, buttons, links and fields stay inside the
  // screen, apart from rows that scroll on purpose (service pills on the phone).
  const outside = await page.evaluate(() => {
    const width = window.innerWidth;
    return [...document.querySelectorAll("h1, h2, h3, p, button, a, input, textarea, select, img")]
      .filter((node) => node.offsetParent !== null && !node.closest("[role=tablist], [role=dialog], [aria-hidden=true]"))
      .map((node) => ({ node, rect: node.getBoundingClientRect() }))
      .filter(({ rect }) => rect.width > 0 && (rect.left < -1 || rect.right > width + 1))
      .map(({ node }) => `${node.tagName.toLowerCase()} "${(node.textContent || node.getAttribute("alt") || "").trim().slice(0, 40)}"`);
  });
  expect(outside, "elements reach beyond the screen edge").toEqual([]);
}

export { expect };

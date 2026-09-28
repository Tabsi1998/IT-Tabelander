import { mockApi } from "./fixtures.js";

// The link from the sign-in mail (#63): /kundenbereich/anmelden#<code>.
export const SIGN_IN_LINK = "Beispiel-Anmeldelink-nur-fuer-Tests-000000";

const OFFER = {
  id: 31, ref: "PR2609-0007", status: 1, state: "offen", date: "2026-09-26T08:00:00+00:00", valid_until: "2026-10-26T00:00:00+00:00",
  total: "189.00000000", net: "157.50000000", vat: "31.50000000", has_pdf: true, expired: false, can_answer: true,
  lines: [
    { text: "Akku tauschen", details: "inkl. Test und Kalibrierung", qty: "1", total: "69.00000000" },
    { text: "Ersatzakku Lenovo ThinkPad T14", details: "", qty: "1", total: "120.00000000" },
  ],
};

const INVOICE = {
  id: 41, ref: "FA2609-0012", status: 1, state: "offen", open: true, credit_note: false,
  date: "2026-09-20T08:00:00+00:00", due: "2026-10-04T00:00:00+00:00", total: "89.00000000", remain: "89.00000000",
  net: "74.17000000", vat: "14.83000000", has_pdf: true,
  lines: [{ text: "Reinigung und Wärmeleitpaste", details: "", qty: "1", total: "89.00000000" }],
  payment: {
    holder: "Jürgen Müller", iban: "AT611904300234573201", bic: "BKAUATWW", amount: "89.00000000", reference: "FA2609-0012",
    epc: ["BCD", "002", "1", "SCT", "BKAUATWW", "Jürgen Müller", "AT611904300234573201", "EUR89.00", "", "", "FA2609-0012"].join("\n"),
  },
};

export function portalState(overrides = {}) {
  return {
    enabled: true,
    signedIn: false,
    me: { email: "kunde@example.at", customers: ["Max Muster"] },
    overview: {
      email: "kunde@example.at", customers: ["Max Muster"],
      counts: { offers_open: 1, invoices_open: 1, repairs_running: 1 },
      tickets: [
        { id: 5, ref: "TS2609-0005", title: "Reparatur: Notebook Lenovo ThinkPad T14", inquiry_ref: "ANF-7K3M9Q2X", step: "angebot_bereit",
          open: true, created_at: "2026-09-24T08:00:00+00:00", updated_at: "2026-09-26T08:00:00+00:00" },
        { id: 3, ref: "TS2608-0003", title: "Controller-Umbau: Controller", inquiry_ref: "ANF-2B8C4D6E", step: "abgeschlossen",
          open: false, created_at: "2026-08-12T08:00:00+00:00", updated_at: "2026-08-30T08:00:00+00:00" },
      ],
      offers: [{ ...OFFER }, { id: 22, ref: "PR2608-0003", status: 2, state: "angenommen", date: "2026-08-14T08:00:00+00:00",
        valid_until: null, total: "140.00000000", has_pdf: true }],
      invoices: [{ ...INVOICE }, { id: 33, ref: "FA2608-0009", status: 2, state: "bezahlt", open: false, credit_note: false,
        date: "2026-08-30T08:00:00+00:00", due: null, total: "140.00000000", remain: "0", has_pdf: true }],
    },
    offers: { 31: { ...OFFER } },
    invoices: { 41: { ...INVOICE } },
    sent: [],
    ...overrides,
  };
}

/** The public API of the site plus the customer area's, which remembers what the page sends. */
export async function mockPortal(page, state = portalState()) {
  await mockApi(page, { portal: state.enabled });
  const json = (route, body, status = 200) => route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
  await page.route("**/api/portal/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace(/^\/api\/portal/, "");
    const method = request.method();
    let body = null;
    try { body = request.postDataJSON(); } catch { /* no body */ }
    if (method !== "GET") state.sent.push({ method, path, body });
    if (!state.enabled) return json(route, { detail: "Der Kundenbereich ist noch nicht eingeschaltet." }, 404);

    if (path === "/login") return json(route, { ok: true });
    if (path === "/session") {
      if (body?.token !== SIGN_IN_LINK) {
        return json(route, { detail: "Dieser Link ist abgelaufen oder wurde schon verwendet. Fordere einfach einen neuen an." }, 400);
      }
      state.signedIn = true;
      return json(route, state.me);
    }
    if (path === "/logout") { state.signedIn = false; return json(route, { ok: true }); }
    if (!state.signedIn) return json(route, { detail: "Bitte melde dich an." }, 401);
    if (path === "/me") return json(route, state.me);
    if (path === "/overview") return json(route, state.overview);

    const pdf = path.match(/^\/(offers|invoices)\/(\d+)\/pdf$/);
    if (pdf) return route.fulfill({ status: 200, contentType: "application/pdf", body: "%PDF-1.4\n%%EOF\n" });
    const offer = path.match(/^\/offers\/(\d+)(\/answer)?$/);
    if (offer) {
      const item = state.offers[offer[1]];
      if (!item) return json(route, { detail: "Das gibt es hier nicht." }, 404);
      if (offer[2]) {
        const status = body.accept ? 2 : 3;
        state.offers[offer[1]] = { ...item, status, state: body.accept ? "angenommen" : "abgelehnt", can_answer: false };
        return json(route, state.offers[offer[1]]);
      }
      return json(route, item);
    }
    const invoice = path.match(/^\/invoices\/(\d+)$/);
    if (invoice) {
      return state.invoices[invoice[1]] ? json(route, state.invoices[invoice[1]]) : json(route, { detail: "Das gibt es hier nicht." }, 404);
    }
    return json(route, { detail: `Nicht im Test vorgesehen: ${method} ${path}` }, 404);
  });
  return state;
}

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

// The other documents (#100): one of each kind, plus a credit note with the invoices.
const ORDER = {
  kind: "orders", id: 51, ref: "CO2609-0004", status: 2, state: "in Lieferung", date: "2026-09-18T08:00:00+00:00",
  total: "249.00000000", has_pdf: true, net: "207.50000000", vat: "41.50000000", delivery: "2026-09-25T00:00:00+00:00",
  lines: [{ text: "SSD Samsung 990 Pro 2 TB", details: "inkl. Einbau und Datenübernahme", qty: "1", total: "249.00000000" }],
};
const SHIPMENT = {
  kind: "shipments", id: 61, ref: "SH2609-0002", status: 2, state: "zugestellt", date: "2026-09-24T08:00:00+00:00",
  total: null, has_pdf: true, tracking: "AT123456789", delivery: null,
  lines: [{ text: "SSD Samsung 990 Pro 2 TB", details: "", qty: "1" }],
};
const INTERVENTION = {
  kind: "interventions", id: 71, ref: "FI2609-0003", status: 3, state: "erledigt", date: "2026-09-22T08:00:00+00:00",
  total: null, has_pdf: true, description: "Notebook gereinigt und Lüfter getauscht.", hours: 1.5,
  lines: [{ text: "Gehäuse geöffnet, Lüfter getauscht", date: "2026-09-22T08:00:00+00:00", hours: 1 },
    { text: "Belastungstest", date: "2026-09-22T09:00:00+00:00", hours: 0.5 }],
};
const CONTRACT = {
  kind: "contracts", id: 81, ref: "CT2609-0001", status: 1, state: "aktiv", date: "2026-09-01T08:00:00+00:00",
  total: null, has_pdf: false, minimum_months: 12, notice: "3 Monate zum Quartalsende",
  lines: [{ text: "Wartung Büro-PCs", details: "monatlich, 3 Geräte", qty: "1", total: "45.00000000", state: "läuft",
    start: "2026-09-01T00:00:00+00:00", end: "2027-08-31T00:00:00+00:00", interval: "monatlich" }],
};
const SERVICE = {
  contract_id: 81, contract_ref: "CT2609-0001", text: "Wartung Büro-PCs", details: "monatlich, 3 Geräte", qty: "1",
  total: "45.00000000", interval: "monatlich", start: "2026-09-01T00:00:00+00:00", end: "2027-08-31T00:00:00+00:00",
  minimum_months: 12, notice: "3 Monate zum Quartalsende", has_pdf: false,
};
const CREDIT_NOTE = { id: 44, ref: "AV2609-0001", status: 1, state: "bezahlt", open: false, credit_note: true,
  date: "2026-09-21T08:00:00+00:00", due: null, total: "-20.00000000", remain: "0", has_pdf: true };

// Inquiries with their history (#122): one waits for the customer, one has an offer, one is closed.
const WAITING = {
  id: 8, ref: "TS2609-0008", title: "Reparatur: PlayStation 5", inquiry_ref: "ANF-9Z8Y7X6W", step: "wartet_auf_dich",
  open: true, created_at: "2026-09-25T08:00:00+00:00", updated_at: "2026-09-27T09:30:00+00:00",
};
export const TRACK_ID = "ITPORTALTEST0008";

function detail(item, messages, files = []) {
  return { ...item, closed_at: item.open ? null : "2026-08-30T08:00:00+00:00", can_reply: item.open, messages, files };
}

// A tiny PNG, as the ticket's photo (#123).
export const PHOTO = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=", "base64");

function inquiries(tickets) {
  const [offer, closed] = tickets;
  return {
    8: detail(WAITING, [
      { id: "start", at: "2026-09-25T08:00:00+00:00", from: "customer", author: null, via: null,
        text: "Anfrageart: Reparatur\nGerät / Bereich: PlayStation\n\nBeschreibung:\nDas Laufwerk zieht keine Discs mehr ein." },
      { id: "41", at: "2026-09-26T10:15:00+00:00", from: "team", author: "IT-Tabelander", via: "email",
        text: "Danke für die Fotos! Ist das die Digital Edition oder die mit Laufwerk?" },
    ], [
      { name: "kunde-20260925-080000-a1b2c3-laufwerk.webp", size: 182000, kind: "image", at: "2026-09-25T08:00:00+00:00", from: "customer" },
      { name: "kostenvoranschlag.pdf", size: 52000, kind: "pdf", at: "2026-09-26T10:15:00+00:00", from: "team" },
    ]),
    5: detail(offer, [
      { id: "start", at: "2026-09-24T08:00:00+00:00", from: "customer", author: null, via: null, text: "Der Akku hält nur noch 20 Minuten." },
    ]),
    3: detail(closed, [
      { id: "start", at: "2026-08-12T08:00:00+00:00", from: "customer", author: null, via: null, text: "Bitte Hall-Effect-Sticks einbauen." },
      { id: "12", at: "2026-08-30T08:00:00+00:00", from: "customer", author: "Max Muster", via: "portal", text: "Passt perfekt, danke!" },
    ]),
  };
}

export function portalState(overrides = {}) {
  const state = {
    enabled: true,
    signedIn: false,
    me: { email: "kunde@example.at", customers: ["Max Muster"] },
    overview: {
      email: "kunde@example.at", customers: ["Max Muster"], accounts: [{ id: 7, name: "Max Muster" }],
      counts: { offers_open: 1, invoices_open: 1, repairs_running: 2, inquiries_waiting: 1 },
      tickets: [
        { ...WAITING },
        { id: 5, ref: "TS2609-0005", title: "Reparatur: Notebook Lenovo ThinkPad T14", inquiry_ref: "ANF-7K3M9Q2X", step: "angebot_bereit",
          open: true, created_at: "2026-09-24T08:00:00+00:00", updated_at: "2026-09-26T08:00:00+00:00" },
        { id: 3, ref: "TS2608-0003", title: "Controller-Umbau: Controller", inquiry_ref: "ANF-2B8C4D6E", step: "abgeschlossen",
          open: false, created_at: "2026-08-12T08:00:00+00:00", updated_at: "2026-08-30T08:00:00+00:00" },
      ],
      offers: [{ ...OFFER }, { id: 22, ref: "PR2608-0003", status: 2, state: "angenommen", date: "2026-08-14T08:00:00+00:00",
        valid_until: null, total: "140.00000000", has_pdf: true }],
      invoices: [{ ...INVOICE }, { id: 33, ref: "FA2608-0009", status: 2, state: "bezahlt", open: false, credit_note: false,
        date: "2026-08-30T08:00:00+00:00", due: null, total: "140.00000000", remain: "0", has_pdf: true }, { ...CREDIT_NOTE }],
      services: [{ ...SERVICE }],
      orders: [{ ...ORDER }], shipments: [{ ...SHIPMENT }], interventions: [{ ...INTERVENTION }], contracts: [{ ...CONTRACT }],
    },
    documents: { "orders/51": ORDER, "shipments/61": SHIPMENT, "interventions/71": INTERVENTION, "contracts/81": CONTRACT },
    offers: { 31: { ...OFFER } },
    invoices: { 41: { ...INVOICE } },
    sent: [],
    ...overrides,
  };
  state.inquiries = overrides.inquiries || inquiries(state.overview.tickets.slice(1));
  return state;
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

    const pdf = path.match(/^\/(offers|invoices|documents\/[a-z]+)\/(\d+)\/pdf$/);
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
    const found = path.match(/^\/documents\/([a-z]+\/\d+)$/);
    if (found) {
      const item = state.documents[found[1]];
      return item ? json(route, item) : json(route, { detail: "Das gibt es hier nicht." }, 404);
    }
    const track = path.match(/^\/inquiries\/track\/([A-Za-z0-9]+)$/);
    if (track) return track[1] === TRACK_ID ? json(route, { id: 8 }) : json(route, { detail: "Diese Anfrage gibt es hier nicht." }, 404);
    if (path === "/inquiries" && method === "POST") {
      const id = 9;
      state.inquiries[id] = detail({ id, ref: "TS2609-0009", title: "Reparatur: Notebook Lenovo", inquiry_ref: "ANF-NEUPORT1",
        step: "eingegangen", open: true, created_at: "2026-09-27T12:00:00+00:00", updated_at: "2026-09-27T12:00:00+00:00" },
      [{ id: "start", at: "2026-09-27T12:00:00+00:00", from: "customer", author: null, via: null, text: body.description }]);
      return json(route, { id, ref: "ANF-NEUPORT1" });
    }
    const file = path.match(/^\/inquiries\/(\d+)\/files(?:\/(.+))?$/);
    if (file) {
      const item = state.inquiries[file[1]];
      if (!item) return json(route, { detail: "Diese Anfrage gibt es hier nicht." }, 404);
      if (method === "POST") {
        const original = /filename="([^"]+)"/.exec(request.postData() || "")?.[1] || "datei";
        const pdf = /\.pdf$/i.test(original);
        const name = `kunde-20260927-120000-${String(state.sent.length).padStart(6, "0")}.${pdf ? "pdf" : "webp"}`;
        state.inquiries[file[1]] = { ...item, files: [...item.files, { name, size: 1000, kind: pdf ? "pdf" : "image", at: null, from: "customer" }] };
        return json(route, { name, size: 1000, kind: pdf ? "pdf" : "image" });
      }
      return route.fulfill({ status: 200, contentType: /\.pdf$/.test(file[2]) ? "application/pdf" : "image/png",
        body: /\.pdf$/.test(file[2]) ? "%PDF-1.4\n%%EOF\n" : PHOTO });
    }
    const inquiry = path.match(/^\/inquiries\/(\d+)(\/messages|\/close)?$/);
    if (inquiry) {
      const item = state.inquiries[inquiry[1]];
      if (!item) return json(route, { detail: "Diese Anfrage gibt es hier nicht." }, 404);
      if (inquiry[2] === "/messages") {
        const step = item.step === "wartet_auf_dich" ? "eingegangen" : item.step;
        const text = [body.message, body.files?.length ? `Angehängt: ${body.files.join(", ")}` : ""].filter(Boolean).join("\n\n");
        state.inquiries[inquiry[1]] = { ...item, step, messages: [...item.messages,
          { id: `m${item.messages.length}`, at: "2026-09-27T12:00:00+00:00", from: "customer", author: "Max Muster", via: "portal", text }] };
      }
      if (inquiry[2] === "/close") {
        state.inquiries[inquiry[1]] = { ...item, step: "abgeschlossen", open: false, can_reply: false, closed_at: "2026-09-27T12:00:00+00:00" };
      }
      return json(route, state.inquiries[inquiry[1]]);
    }
    const invoice = path.match(/^\/invoices\/(\d+)$/);
    if (invoice) {
      return state.invoices[invoice[1]] ? json(route, state.invoices[invoice[1]]) : json(route, { detail: "Das gibt es hier nicht." }, 404);
    }
    return json(route, { detail: `Nicht im Test vorgesehen: ${method} ${path}` }, 404);
  });
  return state;
}

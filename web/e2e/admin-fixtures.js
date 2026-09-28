// An admin API for the browser tests that remembers what the page saves.
export function adminState(overrides = {}) {
  return {
    loggedIn: true,
    user: { id: "u1", email: "chef@example.at", role: "super_admin" },
    refreshOk: true,
    expireNext: 0, // how many next requests answer 401 once (expired access cookie)
    failing: {}, // path -> status for GET
    settings: {
      canonical_base_url: "https://it.tabelander.co.at", service_area: "Tirol", seo_default_title: "", seo_default_description: "",
      google_review_url: "", about_text: "", about_qualifications: [], about_photo_url: "",
      dolibarr_enabled: true, dolibarr_base_url: "https://erp.example.at", dolibarr_api_key_configured: true,
      dolibarr_timeout_seconds: 8, dolibarr_country_code: "AT", dolibarr_ticket_categories: {},
      dolibarr_content_category_id: 5, dolibarr_imprint_article_id: 7, dolibarr_privacy_article_id: 0, dolibarr_terms_article_id: 9,
      smtp_host: "smtp.example.at", smtp_port: 587, smtp_security: "starttls", smtp_password_configured: true,
      smtp_from: "office@example.at", smtp_from_name: "IT-Tabelander", warning_email: "",
    },
    services: [
      { id: "s1", slug: "pc-reparatur", title: "PC-Reparatur", heading: "Diagnose statt Rätselraten", long_description: "Text", bullets: ["A"], active: true, sort: 0 },
      { id: "s2", slug: "pc-bau", title: "PC nach Wunsch", heading: "", long_description: "", bullets: [], active: true, sort: 1 },
    ],
    gallery: [{ id: "g1", image_url: "/assets/img/services/service-overview-640.webp", thumb_url: "/assets/img/services/service-overview-640.webp",
      caption: "Gaming-PC", category: "pc_build", category_label: "PC-Bau", visible: true }],
    reviews: [
      { id: "r2", author: "Max M.", rating: 4, text: "Schnell repariert, fair erklärt.", source: "Website", source_url: "",
        review_date: "2026-09-27", visible: false, pending: true, inquiry_ref: "ANF-BEWERT01" },
      { id: "r1", author: "Eva", rating: 5, text: "Top Arbeit", source: "Google", source_url: "", review_date: "2026-09-01", visible: true },
    ],
    invites: { waiting: 2, sent: 5, answered: 3, last_error: null },
    labels: {
      "ANF-NEU00001": { ref: "ANF-NEU00001", ticket_ref: "TS2609-0001", request_type_label: "Reparatur",
        title: "Reparatur: Notebook Lenovo ThinkPad T14", created_at: "2026-09-27T09:00:00+00:00",
        status_url: "https://it.tabelander.co.at/status/view.php?track_id=IT7K3M9Q2XABCD12" },
    },
    queue: { waiting: 1, gave_up: 0, items: [{ id: "q1", ref: "ANF-WARTE001", request_type: "repair", created_at: "2026-09-27T10:00:00+00:00",
      reason: "Dolibarr ist beim Anlegen des Tickets nicht erreichbar (ConnectError).", attempts: 2, next_attempt_at: "2026-09-27T10:20:00+00:00",
      warned_at: null, gave_up: false }], warning_mail: {} },
    legal: {
      imprint: [
        { label: "Firmenname", ok: true, required: true, where: "Dolibarr: Einstellungen → Unternehmen/Institution → „Firmenname“", note: "" },
        { label: "Unternehmensgegenstand", ok: false, required: true,
          where: "Dolibarr: Einstellungen → Unternehmen/Institution → „Gegenstand des Unternehmens“", note: "" },
        { label: "UID-Nummer", ok: false, required: false, where: "Dolibarr: Einstellungen → Unternehmen/Institution → „Umsatzsteuer-ID“",
          note: "nur wenn du eine hast" },
      ],
      texts: [
        { kind: "impressum", label: "Ergänzung zum Impressum", setting: "dolibarr_imprint_article_id",
          article: { id: 7, question: "Impressum – Ergänzung", status: 1 }, gone: false, state: "released", open_points: [] },
        { kind: "datenschutz", label: "Datenschutzerklärung", setting: "dolibarr_privacy_article_id",
          article: null, gone: false, state: "missing", open_points: [] },
        { kind: "nutzungsbedingungen", label: "Nutzungsbedingungen", setting: "dolibarr_terms_article_id",
          article: { id: 9, question: "Nutzungsbedingungen", status: 0 }, gone: false, state: "draft",
          open_points: ["[BITTE ERGÄNZEN: z. B. 4 Wochen]", "[BITTE MIT DER WKO KLÄREN: nicht abgeholte Geräte]"] },
      ],
      choices: [{ id: 7, question: "Impressum – Ergänzung", status: 1 }, { id: 9, question: "Nutzungsbedingungen", status: 0 }],
      todo: ["Unternehmensgegenstand fehlt im Impressum", "Datenschutzerklärung fehlt", "Nutzungsbedingungen ist noch ein Entwurf",
        "Nutzungsbedingungen: 2 Stellen noch zu ergänzen"],
      error: null,
    },
    sent: [],
    refreshes: 0,
    ...overrides,
  };
}

export async function mockAdmin(page, state = adminState()) {
  const json = (route, body, status = 200) => route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
  const nextId = (prefix) => `${prefix}${Math.random().toString(16).slice(2, 8)}`;
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace(/^\/api/, "");
    const method = request.method();
    let body = null;
    try { body = request.postDataJSON(); } catch { /* multipart */ }
    if (method !== "GET") state.sent.push({ method, path, body, raw: request.postData() });

    if (path === "/auth/refresh") {
      state.refreshes += 1;
      return state.refreshOk && state.loggedIn ? json(route, { ok: true }) : json(route, { detail: "Ungültiger Token" }, 401);
    }
    if (path === "/auth/login") {
      if (body.password !== "richtiges-passwort") return json(route, { detail: "E-Mail oder Passwort ist falsch" }, 401);
      state.loggedIn = true;
      state.refreshOk = true;
      return json(route, { user: state.user });
    }
    if (!state.loggedIn) return json(route, { detail: "Nicht angemeldet" }, 401);
    if (state.expireNext > 0 && path !== "/auth/logout") {
      state.expireNext -= 1;
      return json(route, { detail: "Token abgelaufen" }, 401);
    }
    if (method === "GET" && state.failing[path]) return json(route, { detail: "Datenbank nicht erreichbar" }, state.failing[path]);

    if (path === "/auth/me") return json(route, { user: state.user });
    if (path === "/auth/logout") { state.loggedIn = false; return json(route, { ok: true }); }
    if (path === "/auth/account") return json(route, { user: { ...state.user, email: body.email || state.user.email } });
    if (path === "/admin/dashboard") {
      if (state.brokenDashboard) return json(route, { inquiries: null });
      return json(route, {
        inquiries: { waiting: state.queue.waiting, recent: [
          { id: "i1", ref: "ANF-NEU00001", request_type: "repair", request_type_label: "Reparatur",
            created_at: "2026-09-27T09:00:00+00:00", in_dolibarr: true, ticket_ref: "TS2609-0001" },
          { id: "i2", ref: "ANF-KONTAKT1", request_type: "contact", request_type_label: "Kontaktnachricht",
            created_at: "2026-09-27T08:00:00+00:00", in_dolibarr: true, ticket_ref: "TS2609-0002" },
        ] },
        reviews: { visible: state.reviews.filter((item) => item.visible).length, hidden: 0,
          pending: state.reviews.filter((item) => item.pending).length },
        gallery: { visible: state.gallery.length, total: state.gallery.length }, services: { active: state.services.length },
        dolibarr_enabled: true, mail_configured: true, legal_todo: state.legal.todo,
      });
    }
    if (path === "/admin/settings" && method === "GET") return json(route, state.settings);
    if (path === "/admin/settings" && method === "PUT") {
      if (body.google_review_url && !body.google_review_url.startsWith("https://")) {
        return json(route, { detail: [{ loc: ["body", "google_review_url"], msg: "Value error, Der Link zum Google-Profil muss eine vollständige https-Adresse sein" }] }, 422);
      }
      state.settings = { ...state.settings, ...body };
      return json(route, state.settings);
    }
    if (path === "/admin/settings/test-mail") return json(route, { ok: true, message: "Test-Mail an chef@example.at gesendet." });
    if (path === "/admin/media") return json(route, { id: nextId("m"), url: "/assets/img/services/service-overview-640.webp" });

    if (path === "/admin/services" && method === "GET") return json(route, state.services);
    if (path === "/admin/services" && method === "POST") {
      const created = { id: nextId("s"), slug: body.title.toLowerCase().replace(/[^a-z0-9]+/g, "-"), sort: state.services.length, ...body };
      state.services.push(created);
      return json(route, created);
    }
    if (path === "/admin/services/order") {
      state.services = body.ids.map((id) => state.services.find((item) => item.id === id));
      return json(route, { ok: true });
    }
    const service = path.match(/^\/admin\/services\/(\w+)$/);
    if (service && method === "PUT") {
      state.services = state.services.map((item) => (item.id === service[1] ? { ...item, ...body } : item));
      return json(route, state.services.find((item) => item.id === service[1]));
    }
    if (service && method === "DELETE") { state.services = state.services.filter((item) => item.id !== service[1]); return json(route, { ok: true }); }

    if (path === "/admin/gallery" && method === "GET") return json(route, { items: state.gallery, categories: {} });
    if (path === "/admin/gallery" && method === "POST") {
      const raw = request.postData() || "";
      const field = (name) => (raw.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)`)) || [])[1] || "";
      const item = { id: nextId("g"), image_url: "/assets/img/services/service-overview-640.webp", thumb_url: "/assets/img/services/service-overview-640.webp",
        caption: field("caption"), category: field("category"), category_label: field("category"), visible: field("visible") === "true" };
      state.gallery.unshift(item);
      return json(route, item);
    }
    if (path === "/admin/gallery/order") return json(route, { ok: true });
    const photo = path.match(/^\/admin\/gallery\/(\w+)$/);
    if (photo && method === "PUT") return json(route, { ...state.gallery.find((item) => item.id === photo[1]), ...body });
    if (photo && method === "DELETE") { state.gallery = state.gallery.filter((item) => item.id !== photo[1]); return json(route, { ok: true }); }

    if (path === "/admin/reviews" && method === "GET") return json(route, state.reviews);
    if (path === "/admin/reviews" && method === "POST") {
      const created = { id: nextId("r"), ...body };
      state.reviews.unshift(created);
      return json(route, created);
    }
    const review = path.match(/^\/admin\/reviews\/(\w+)$/);
    if (review && method === "PUT") {
      state.reviews = state.reviews.map((item) => (item.id === review[1]
        ? { ...item, ...body, ...(body.visible ? { pending: false } : {}) } : item));
      return json(route, state.reviews.find((item) => item.id === review[1]));
    }
    if (path === "/admin/review-invites") return json(route, state.invites);
    if (path === "/admin/review-invites/run") {
      state.invites = { ...state.invites, waiting: state.invites.waiting - 1, sent: state.invites.sent + 1 };
      return json(route, { checked: 2, sent: 1, stopped: 0, failed: 0, problem: null });
    }
    const label = path.match(/^\/admin\/labels\/([\w-]+)$/);
    if (label) {
      return state.labels[label[1]] ? json(route, state.labels[label[1]])
        : json(route, { detail: "Keine Anfrage mit dieser Nummer gefunden." }, 404);
    }
    if (review && method === "DELETE") return json(route, { ok: true });

    if (path === "/admin/dolibarr/status") {
      return json(route, { enabled: true, connection: { connected: true, version: "24.0.1", message: "API, Interessenten/Firmen und Tickets sind erreichbar." } });
    }
    if (path === "/admin/dolibarr/content") {
      return json(route, { company: { name: "IT-Tabelander", address: "Gasse 1", zip: "6410", town: "Telfs" }, opening_hours: [{ day: "Montag", hours: "09:00–17:00" }],
        categories: [{ id: 5, label: "Website" }], articles: [{ id: 7, question: "Impressum – Ergänzung", status: 1 }], faq_count: 2,
        legal: state.legal, errors: {} });
    }
    const legalDraft = path.match(/^\/admin\/legal\/(\w+)\/draft$/);
    if (legalDraft) {
      state.legal = { ...state.legal,
        texts: state.legal.texts.map((text) => (text.kind === legalDraft[1]
          ? { ...text, article: { id: 11, question: "Datenschutzerklärung", status: 0 }, state: "draft", open_points: ["[BITTE ERGÄNZEN: Anbieter]"] }
          : text)),
        choices: [...state.legal.choices, { id: 11, question: "Datenschutzerklärung", status: 0 }],
        todo: state.legal.todo.filter((item) => item !== "Datenschutzerklärung fehlt").concat("Datenschutzerklärung ist noch ein Entwurf") };
      return json(route, { kind: legalDraft[1], article_id: 11 });
    }
    if (path === "/admin/dolibarr/queue") return json(route, state.queue);
    if (path === "/admin/dolibarr/queue/run") {
      state.queue = { waiting: 0, gave_up: 0, items: [], warning_mail: {} };
      return json(route, { tried: 1, synced: 1, failed: 0, dolibarr_enabled: true });
    }
    if (path === "/admin/dolibarr/migration") {
      return json(route, { inquiries_to_send: 0, inquiries_to_finish: 0, contact_messages: 0, faqs: 0, items: [] });
    }
    return json(route, { detail: `Nicht im Test vorgesehen: ${method} ${path}` }, 404);
  });
  return state;
}

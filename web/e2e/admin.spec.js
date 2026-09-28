import AxeBuilder from "@axe-core/playwright";
import { adminState, mockAdmin } from "./admin-fixtures.js";
import { expect, expectNoSidewaysScroll, test } from "./fixtures.js";

// A tiny PNG, as a phone camera would hand it over.
const PHOTO = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=", "base64");

const PAGES = [
  ["/admin", "Übersicht"], ["/admin/texte", "Texte"], ["/admin/leistungen", "Leistungen"], ["/admin/galerie", "Galerie"],
  ["/admin/bewertungen", "Bewertungen"], ["/admin/dolibarr", "Dolibarr"], ["/admin/technik", "Technik"],
  ["/admin/etikett/ANF-NEU00001", "Etikett drucken"],
];

for (const [path, title] of PAGES) {
  test(`admin page ${title} is accessible and fits the screen (#57)`, async ({ page }) => {
    await mockAdmin(page);
    await page.goto(path);
    await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible();
    await page.waitForLoadState("networkidle");
    const result = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).analyze();
    expect(result.violations.map((item) => `${item.id}: ${item.nodes.slice(0, 2).map((node) => node.target.join(" ")).join(" | ")}`)).toEqual([]);
    await expectNoSidewaysScroll(page);
  });
}

test("sign in, wrong password first (#57)", async ({ page }) => {
  await mockAdmin(page, adminState({ loggedIn: false, refreshOk: false }));
  await page.goto("/admin/galerie");
  await page.getByLabel("E-Mail").fill("chef@example.at");
  await page.getByLabel("Passwort").fill("falsch");
  await page.getByRole("button", { name: "Anmelden" }).click();
  await expect(page.getByRole("alert")).toHaveText("E-Mail oder Passwort ist falsch");
  await page.getByLabel("Passwort").fill("richtiges-passwort");
  await page.getByRole("button", { name: "Anmelden" }).click();
  // Straight to the page that was asked for.
  await expect(page.getByRole("heading", { level: 1, name: "Galerie" })).toBeVisible();
});

test("an expired access renews itself without a sign-in (#57)", async ({ page }) => {
  const state = await mockAdmin(page, adminState({ expireNext: 1 }));
  await page.goto("/admin/bewertungen");
  await expect(page.getByText("Top Arbeit")).toBeVisible();
  expect(state.refreshes).toBeGreaterThanOrEqual(1);
  await expect(page.getByRole("button", { name: "Anmelden" })).toHaveCount(0);
});

test("a session that ended says so and returns to the same page (#57)", async ({ page }) => {
  const state = await mockAdmin(page);
  await page.goto("/admin/technik");
  await expect(page.getByRole("heading", { level: 1, name: "Technik" })).toBeVisible();
  // Only once the page has its data: a session ending while it still loads
  // rightly shows the sign-in form at once, and the button never comes.
  await expect(page.getByRole("button", { name: "Test-Mail senden" })).toBeVisible();
  state.loggedIn = false;
  await page.getByRole("button", { name: "Test-Mail senden" }).click();
  await expect(page.getByText("Deine Anmeldung ist abgelaufen")).toBeVisible();
  await page.getByLabel("E-Mail").fill("chef@example.at");
  await page.getByLabel("Passwort").fill("richtiges-passwort");
  await page.getByRole("button", { name: "Anmelden" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Technik" })).toBeVisible();
});

test("a validation error reads as a sentence (#57)", async ({ page }) => {
  await mockAdmin(page);
  await page.goto("/admin/technik");
  await page.getByLabel(/Google-Profil/).fill("http://g.page/unsicher");
  await page.getByRole("button", { name: "Speichern" }).first().click();
  await expect(page.getByRole("alert")).toHaveText("Google-Profil: Der Link zum Google-Profil muss eine vollständige https-Adresse sein");
});

test("without a successful load there is nothing to save (#57)", async ({ page }) => {
  const state = await mockAdmin(page, adminState({ failing: { "/admin/settings": 500 } }));
  await page.goto("/admin/texte");
  await expect(page.getByText("Konnte nicht geladen werden: Datenbank nicht erreichbar")).toBeVisible();
  await expect(page.getByRole("button", { name: "Speichern" })).toHaveCount(0);
  state.failing = {};
  await page.getByRole("button", { name: "Noch einmal laden" }).click();
  await expect(page.getByRole("button", { name: "Speichern" })).toBeVisible();
});

test("a page that breaks shows a message, not a white screen (#57)", async ({ page }) => {
  await mockAdmin(page, adminState({ brokenDashboard: true }));
  await page.goto("/admin");
  await expect(page.getByRole("heading", { name: "Diese Seite konnte nicht angezeigt werden." })).toBeVisible();
  await expect(page.getByRole("button", { name: "Seite neu laden" })).toBeVisible();
  // The frame stays: the other pages remain one click away.
  await expect(page.getByRole("complementary").or(page.getByRole("banner"))).toBeVisible();
});

test("edit, order and add services (#58)", async ({ page }) => {
  const state = await mockAdmin(page);
  await page.goto("/admin/leistungen");
  await page.getByRole("button", { name: "PC nach Wunsch nach oben" }).click();
  await expect.poll(() => state.sent.find((item) => item.path === "/admin/services/order")?.body.ids).toEqual(["s2", "s1"]);
  await page.getByRole("link", { name: "PC-Reparatur bearbeiten" }).click();
  await page.getByLabel("Überschrift").fill("Neue Überschrift");
  await page.getByLabel("Stichpunkte").fill("Eins\nZwei");
  await page.getByRole("button", { name: "Speichern" }).click();
  await expect(page.getByText("Gespeichert. Die Website zeigt den neuen Stand sofort.")).toBeVisible();
  const put = state.sent.find((item) => item.method === "PUT" && item.path === "/admin/services/s1").body;
  expect(put).toMatchObject({ title: "PC-Reparatur", heading: "Neue Überschrift", bullets: ["Eins", "Zwei"], active: true });
  await expect(page.getByRole("link", { name: "Vorschau ↗" })).toHaveAttribute("href", "/leistungen/pc-reparatur");
  await page.getByRole("link", { name: "← Alle Leistungen" }).click();
  await page.getByRole("link", { name: "Neue Leistung" }).click();
  await page.getByLabel("Name im Tab").fill("Datenrettung");
  await page.getByRole("button", { name: "Speichern" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Leistung bearbeiten" })).toBeVisible();
  expect(state.services.map((item) => item.title)).toContain("Datenrettung");
});

test("a photo from the phone lands in the gallery (#60)", async ({ page }) => {
  const state = await mockAdmin(page);
  await page.goto("/admin/galerie");
  await page.locator('input[capture="environment"]').setInputFiles({ name: "pc.png", mimeType: "image/png", buffer: PHOTO });
  await expect(page.getByRole("img", { name: "Vorschau des gewählten Fotos" })).toBeVisible();
  await page.getByLabel("Titel").first().fill("Wasserkühlung");
  await page.getByRole("group", { name: "Bereich" }).first().getByRole("button", { name: "Upgrade" }).click();
  await page.getByRole("button", { name: "Speichern" }).click();
  await expect(page.getByText("Gespeichert. Das Foto ist jetzt in „Aus der Werkstatt“.")).toBeVisible();
  const upload = state.sent.find((item) => item.method === "POST" && item.path === "/admin/gallery").raw;
  expect(upload).toContain("Wasserkühlung");
  expect(upload).toContain("upgrade");
  await expect(page.getByRole("img", { name: "Wasserkühlung" })).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test("enter a Google review with its link (#59)", async ({ page }) => {
  const state = await mockAdmin(page);
  await page.goto("/admin/bewertungen");
  const form = page.getByRole("region", { name: "Bewertung eintragen" });
  await form.getByLabel(/Link zur Original-Bewertung/).fill("https://g.co/kgs/abc");
  await form.getByLabel("Name").fill("Max M.");
  await form.getByLabel("Datum").fill("2026-09-20");
  await form.getByRole("radio", { name: "4 von 5 Sternen" }).click();
  await form.getByLabel(/^Text/).fill("Schnell und ehrlich.");
  await form.getByRole("button", { name: "Speichern" }).click();
  await expect(page.getByText("Schnell und ehrlich.")).toBeVisible();
  const post = state.sent.find((item) => item.method === "POST" && item.path === "/admin/reviews").body;
  expect(post).toMatchObject({ author: "Max M.", rating: 4, source: "Google", source_url: "https://g.co/kgs/abc", review_date: "2026-09-20", visible: true });
});

test("texts: about me with a photo (#58)", async ({ page }) => {
  const state = await mockAdmin(page);
  await page.goto("/admin/texte");
  await page.getByLabel(/^Text/).fill("Ich repariere seit 2015.\n\nUnd baue PCs.");
  await page.getByLabel(/Qualifikationen/).fill("CompTIA A+\nWIFI Tirol");
  await page.locator('input[type="file"]').setInputFiles({ name: "ich.png", mimeType: "image/png", buffer: PHOTO });
  await expect(page.getByRole("button", { name: "Bild entfernen" })).toBeVisible();
  await page.getByRole("button", { name: "Speichern" }).click();
  await expect(page.getByText("Gespeichert. Die Website zeigt den neuen Stand sofort.")).toBeVisible();
  expect(state.settings).toMatchObject({ about_text: "Ich repariere seit 2015.\n\nUnd baue PCs.", about_qualifications: ["CompTIA A+", "WIFI Tirol"] });
  expect(state.settings.about_photo_url).toMatch(/^\//);
});

test("Dolibarr: the connection check names the version, the queue sends again (#62)", async ({ page }) => {
  await mockAdmin(page);
  await page.goto("/admin/dolibarr");
  await page.getByRole("button", { name: "Verbindung prüfen" }).click();
  await expect(page.getByText(/Verbunden mit Dolibarr 24\.0\.1/)).toBeVisible();
  await expect(page.getByText("ANF-WARTE001")).toBeVisible();
  await page.getByRole("button", { name: "Jetzt erneut senden" }).click();
  await expect(page.getByText("Alle Anfragen sind in Dolibarr angekommen.")).toBeVisible();
});

test("the admin menu on a phone (#57)", async ({ page }) => {
  test.skip(page.viewportSize().width >= 1024, "the sidebar shows from 1024 px");
  await mockAdmin(page);
  await page.goto("/admin");
  await page.getByRole("button", { name: "Menü öffnen" }).click();
  await page.getByRole("dialog", { name: "Menü" }).getByRole("link", { name: "Galerie" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Galerie" })).toBeVisible();
  await expect(page.getByRole("dialog", { name: "Menü" })).toHaveCount(0);
});

test("a review from the link waits for the release; requests go out on demand (#71)", async ({ page }) => {
  const state = await mockAdmin(page);
  await page.goto("/admin");
  await expect(page.getByText("1 Bewertung wartet auf deine Freigabe.")).toBeVisible();
  await page.getByRole("link", { name: "Zu den Bewertungen" }).click();

  const pending = page.getByRole("listitem").filter({ hasText: "Schnell repariert, fair erklärt." });
  await expect(pending.getByText("Wartet auf deine Freigabe")).toBeVisible();
  await expect(pending.getByText(/über den Link nach dem Auftrag/)).toBeVisible();
  await pending.getByRole("button", { name: "Freigeben" }).click();
  await expect(pending.getByText("Wartet auf deine Freigabe")).toHaveCount(0);
  await expect(pending.getByRole("switch", { name: "Bewertung von Max M. sichtbar" })).toHaveAttribute("aria-checked", "true");
  const put = state.sent.find((item) => item.method === "PUT" && item.path === "/admin/reviews/r2").body;
  expect(put).toMatchObject({ visible: true });

  const invites = page.getByRole("region", { name: "Bewertungsbitten" });
  await expect(invites.getByText("verschickt")).toBeVisible();
  await invites.getByRole("button", { name: "Jetzt prüfen" }).click();
  await expect(invites.getByText("1 Mail verschickt.")).toBeVisible();
});

test("a device label with a QR code to the status page (#72)", async ({ page }) => {
  await mockAdmin(page);
  await page.goto("/admin");
  // A contact message is no device: no label for it.
  await expect(page.getByRole("link", { name: "Etikett für ANF-KONTAKT1 drucken" })).toHaveCount(0);
  await page.getByRole("link", { name: "Etikett für ANF-NEU00001 drucken" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Etikett drucken" })).toBeVisible();
  await expect(page.getByRole("img", { name: "QR-Code zur Status-Seite von ANF-NEU00001" })).toBeVisible();
  await expect(page.getByText("Reparatur: Notebook Lenovo ThinkPad T14")).toBeVisible();
  await expect(page.getByText(/TS2609-0001 · 27\.9\.2026/)).toBeVisible();
  // No customer name on a label that sits on the device in the shop.
  await expect(page.getByText("Kein Name auf dem Etikett", { exact: false })).toBeVisible();
  await expectNoSidewaysScroll(page);

  await page.getByRole("link", { name: "Anderes Etikett" }).click();
  await page.getByLabel("Anfrage- oder Ticket-Nummer").fill("ANF-GIBTSNICHT");
  await page.getByRole("button", { name: "Etikett zeigen" }).click();
  await expect(page.getByText("Keine Anfrage mit dieser Nummer gefunden.")).toBeVisible();
});

test("the label prints on 62 x 29 mm or on A4, and nothing else prints (#72)", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== "desktop", "one PDF is enough; the paper size does not depend on the width");
  await mockAdmin(page);
  await page.goto("/admin/etikett/ANF-NEU00001");
  await expect(page.getByRole("img", { name: /QR-Code/ })).toBeVisible();
  const sizes = async () => {
    const pdf = (await page.pdf({ preferCSSPageSize: true, printBackground: true })).toString("latin1");
    return [...pdf.matchAll(/\/MediaBox\s*\[\s*0 0 ([\d.]+) ([\d.]+)\s*\]/g)].map((match) => [Number(match[1]), Number(match[2])]);
  };
  // 62 x 29 mm in PDF points (1 mm = 72 / 25.4 pt), one page only.
  const roll = await sizes();
  expect(roll).toHaveLength(1);
  expect(roll[0][0]).toBeCloseTo(175.7, 0);
  expect(roll[0][1]).toBeCloseTo(82.2, 0);

  await page.getByRole("button", { name: "Normales Blatt (A4)" }).click();
  await expect(page.locator('link[href="/print/label-sheet.css"]')).toHaveCount(1);
  const sheet = await sizes();
  expect(sheet).toHaveLength(1);
  expect(sheet[0][0]).toBeCloseTo(595.3, 0);
  expect(sheet[0][1]).toBeCloseTo(841.9, 0);
  await expect(page.locator('link[href="/print/label-roll.css"]')).toHaveCount(0);
});

test("legal texts: what is missing, and a draft to start from (#68, #69, #75)", async ({ page }) => {
  const state = await mockAdmin(page);
  await page.goto("/admin");
  await expect(page.getByText(/Rechtstexte: Unternehmensgegenstand fehlt im Impressum · Datenschutzerklärung fehlt/)).toBeVisible();
  await page.getByRole("link", { name: "Zu den Rechtstexten" }).click();

  const legal = page.getByRole("region", { name: "Rechtstexte" });
  await expect(legal.getByText("Noch offen: Unternehmensgegenstand fehlt im Impressum", { exact: false })).toBeVisible();
  // The checklist says where in Dolibarr the missing field is.
  await expect(legal.getByText("Dolibarr: Einstellungen → Unternehmen/Institution → „Gegenstand des Unternehmens“")).toBeVisible();
  await expect(legal.getByText("(nur wenn du eine hast)")).toBeVisible();
  await legal.getByText("2 Stellen noch zu ergänzen", { exact: true }).click();
  await expect(legal.getByText("[BITTE MIT DER WKO KLÄREN: nicht abgeholte Geräte]")).toBeVisible();

  await legal.getByRole("button", { name: "Entwurf in Dolibarr anlegen" }).click();
  await expect(legal.getByText(/Entwurf angelegt/)).toBeVisible();
  expect(state.sent.some((item) => item.method === "POST" && item.path === "/admin/legal/datenschutz/draft")).toBe(true);
  await expect(legal.getByRole("button", { name: "Entwurf in Dolibarr anlegen" })).toHaveCount(0);
  await expect(legal.getByLabel("Artikel in Dolibarr").nth(1)).toHaveValue("11");

  // The pick of another article is saved with the other settings.
  await legal.getByLabel("Artikel in Dolibarr").nth(2).selectOption("7");
  await legal.getByRole("button", { name: "Auswahl speichern" }).click();
  await expect(legal.getByText("Gespeichert.")).toBeVisible();
  expect(state.settings).toMatchObject({ dolibarr_privacy_article_id: 11, dolibarr_terms_article_id: 7 });
  await expectNoSidewaysScroll(page);
});

// Screenshots of every admin page in every width (#55), next to the website's.
for (const [path, title] of PAGES) {
  test(`screenshot admin ${title}`, async ({ page }, testInfo) => {
    await mockAdmin(page);
    await page.goto(path);
    await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible();
    await page.waitForLoadState("networkidle");
    const name = `${testInfo.project.name}-admin-${path.split("/")[2] || "uebersicht"}`;
    const body = await page.screenshot({ fullPage: true, path: `screenshots/${name}.png` });
    await testInfo.attach(name, { body, contentType: "image/png" });
  });
}

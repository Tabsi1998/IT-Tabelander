import AxeBuilder from "@axe-core/playwright";
import { adminState, mockAdmin } from "./admin-fixtures.js";
import { expect, expectNoSidewaysScroll, test } from "./fixtures.js";

// A tiny PNG, as a phone camera would hand it over.
const PHOTO = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=", "base64");

const PAGES = [
  ["/admin", "Übersicht"], ["/admin/texte", "Texte"], ["/admin/leistungen", "Leistungen"], ["/admin/galerie", "Galerie"],
  ["/admin/bewertungen", "Bewertungen"], ["/admin/dolibarr", "Dolibarr"], ["/admin/technik", "Technik"],
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

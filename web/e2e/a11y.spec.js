import AxeBuilder from "@axe-core/playwright";
import { mkdirSync } from "node:fs";
import { REVIEW_LINK, expect, expectNoSidewaysScroll, mockApi, test } from "./fixtures.js";

const LEGAL = { impressum: { title: "Impressum", html: "<p>IT-Tabelander<br>Teststraße 1<br>6410 Telfs</p>" } };
const PAGES = [
  ["startseite", "/", "IT-Technik, die"],
  ["rechtliches", "/rechtliches/impressum", "Rechtliches"],
  ["nicht-gefunden", "/gibt-es-nicht", "Diese Seite gibt es nicht."],
  ["bewertung", `/bewertung#${REVIEW_LINK}`, "Wie war’s?"],
];

async function open(page, path, heading) {
  await mockApi(page, { gallery: 5, reviewCount: 4, legal: LEGAL });
  await page.goto(path);
  await expect(page.getByRole("heading", { level: 1 })).toContainText(heading);
  await page.evaluate(() => document.fonts.ready);
}

/** WCAG 2.1 A and AA: contrast, names of fields and buttons, landmarks (#55). */
async function expectAccessible(page) {
  const result = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).analyze();
  const found = result.violations.map((item) =>
    `${item.id} (${item.impact}): ${item.nodes.slice(0, 3).map((node) => node.target.join(" ")).join(" | ")}`);
  expect(found).toEqual([]);
}

for (const scheme of ["light", "dark"]) {
  for (const [name, path, heading] of PAGES) {
    test(`${name} is accessible in ${scheme === "light" ? "hell" : "dunkel"} (#55)`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await open(page, path, heading);
      await expectAccessible(page);
      await expectNoSidewaysScroll(page);
    });
  }
}

test("the forms are accessible in every step (#55)", async ({ page }) => {
  await open(page, "/?kontakt=anfrage", "IT-Technik, die");
  const contact = page.locator("#kontakt");
  await contact.getByRole("button", { name: /^Reparatur/ }).click();
  await contact.getByRole("button", { name: "Weiter" }).click();
  await contact.getByRole("button", { name: "Weiter" }).click();
  await expect(contact.getByRole("alert")).toBeVisible();
  await expectAccessible(page);
  await contact.getByRole("tab", { name: "Status prüfen" }).click();
  await contact.getByLabel("Anfrage-Nummer").fill("ANF-ABCD1234");
  await contact.getByLabel("E-Mail aus der Anfrage").fill("kunde@example.at");
  await contact.getByRole("button", { name: "Status anzeigen" }).click();
  await expect(contact.getByRole("status")).toBeVisible();
  await expectAccessible(page);
});

test("the page works with the keyboard alone (#55)", async ({ page, isMobile }) => {
  test.skip(isMobile, "a phone has no keyboard");
  await open(page, "/", "IT-Technik, die");
  await page.keyboard.press("Tab");
  const skip = page.getByRole("link", { name: "Zum Inhalt springen" });
  await expect(skip).toBeFocused();
  await expect(skip).toBeVisible();
  const outline = await skip.evaluate((node) => getComputedStyle(node).outlineStyle);
  expect(outline).not.toBe("none");
  // From the service tabs on with the arrow keys, then into the panel's button.
  const tabs = page.getByRole("tablist", { name: "Leistungen" });
  await tabs.getByRole("tab", { selected: true }).focus();
  await page.keyboard.press("ArrowRight");
  await expect(tabs.getByRole("tab", { name: "PC nach Wunsch" })).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("link", { name: "PC nach Wunsch anfragen" })).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("tab", { name: "Reparatur anfragen" })).toHaveAttribute("aria-selected", "true");
});

test("a legal page prints just its text, e.g. as PDF for the offers in Dolibarr (#75)", async ({ page }) => {
  await open(page, "/rechtliches/impressum", "Rechtliches");
  await expect(page.getByText("Teststraße 1")).toBeVisible();
  await page.emulateMedia({ media: "print" });
  await expect(page.getByRole("banner")).toBeHidden();
  await expect(page.getByRole("navigation", { name: "Rechtliche Texte" })).toBeHidden();
  await expect(page.getByRole("contentinfo")).toBeHidden();
  await expect(page.getByRole("heading", { level: 2, name: "Impressum" })).toBeVisible();
  await expect(page.getByText("Teststraße 1")).toBeVisible();
});

// Screenshots of every page in every width, for looking through after a run:
// web/screenshots/ and the Playwright report (#55).
for (const [name, path, heading] of PAGES) {
  test(`screenshot ${name}`, async ({ page }, testInfo) => {
    await open(page, path, heading);
    mkdirSync("screenshots", { recursive: true });
    const body = await page.screenshot({ fullPage: true, path: `screenshots/${testInfo.project.name}-${name}.png` });
    await testInfo.attach(`${testInfo.project.name}-${name}`, { body, contentType: "image/png" });
  });
}

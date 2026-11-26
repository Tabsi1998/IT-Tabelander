import AxeBuilder from "@axe-core/playwright";
import { expect, expectNoSidewaysScroll, mockApi, test } from "./fixtures.js";

// The online withdrawal function (§ 13a FAGG, #142).
const LEGAL = { widerruf: { title: "Widerrufsbelehrung", html: "<p>14 Tage.</p>" } };

async function expectAccessible(page) {
  // After a click the pointer may rest on a footer link that moved there: a hover state, not the page.
  await page.mouse.move(0, 0);
  const result = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).analyze();
  expect(result.violations.map((item) => `${item.id}: ${item.nodes.map((node) => node.target.join(" ") + " " + node.failureSummary).join(" | ")}`)).toEqual([]);
}

test("withdraw a contract without signing in: button, form, confirmation (#142)", async ({ page }) => {
  const sent = await mockApi(page, { legal: LEGAL });
  await page.goto("/rechtliches/widerruf");
  const section = page.getByRole("region", { name: "Vertrag online widerrufen" });
  await expect(section.getByRole("textbox")).toHaveCount(0);
  await section.getByRole("button", { name: "Vertrag widerrufen" }).click();
  await expect(section.getByLabel("Dein Name")).toBeFocused();
  await expectAccessible(page);

  await section.getByLabel("Dein Name").fill("Max Muster");
  await section.getByLabel("E-Mail für die Bestätigung").fill("max@example.com");
  await section.getByLabel(/Nummer des Vertrags/).fill("AN202611-0001");
  await section.getByRole("radio", { name: "Nur einen Teil davon" }).check();
  await section.getByLabel("Welchen Teil?").fill("Position 2");
  await section.getByRole("button", { name: "Widerruf bestätigen" }).click();

  const done = section.getByRole("status");
  await expect(done).toContainText("Dein Widerruf ist eingegangen.");
  await expect(done).toContainText("26.11.2026 10:41 Uhr");
  await expect(done).toContainText("WR-1A2B3C4D");
  await expect(done).toContainText("Widerrufener Teil: Position 2");
  const body = sent.find((item) => item.path === "/withdrawals").body;
  expect(body).toMatchObject({ name: "Max Muster", email: "max@example.com", contract: "AN202611-0001",
    scope: "part", parts: "Position 2", honeypot: "" });
  expect(body.request_id).toMatch(/^widerruf/);
  await expectAccessible(page);
  await expectNoSidewaysScroll(page);
});

test("the link from the mail opens the form with the contract filled in (#142)", async ({ page }) => {
  await mockApi(page, { legal: LEGAL });
  await page.goto("/rechtliches/widerruf?vertrag=AN202611-0001#vertrag-widerrufen");
  const section = page.getByRole("region", { name: "Vertrag online widerrufen" });
  await expect(section.getByLabel(/Nummer des Vertrags/)).toHaveValue("AN202611-0001");
  await expect(section.getByRole("button", { name: "Widerruf bestätigen" })).toBeVisible();
});

test("the function is there even before the text is picked, and easy to find (#142)", async ({ page }) => {
  await mockApi(page, { legal: {} });
  await page.goto("/");
  await page.getByRole("contentinfo").getByRole("link", { name: "Vertrag widerrufen" }).click();
  await expect(page).toHaveURL(/\/rechtliches\/widerruf#vertrag-widerrufen$/);
  await expect(page.getByRole("button", { name: "Widerruf bestätigen" })).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test("a print of the notice leaves the form out (#142)", async ({ page }) => {
  await mockApi(page, { legal: LEGAL });
  await page.goto("/rechtliches/widerruf");
  await page.emulateMedia({ media: "print" });
  await expect(page.getByRole("region", { name: "Vertrag online widerrufen" })).toBeHidden();
  await expect(page.getByText("14 Tage.")).toBeVisible();
});

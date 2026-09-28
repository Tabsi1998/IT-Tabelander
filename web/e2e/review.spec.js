import AxeBuilder from "@axe-core/playwright";
import { REVIEW_LINK, expect, expectNoSidewaysScroll, mockApi, test } from "./fixtures.js";

/** A repair inquiry up to the last step, the way a customer fills it in. */
async function inquiryUpToContact(page) {
  await page.goto("/?kontakt=anfrage");
  const contact = page.locator("#kontakt");
  await contact.getByRole("button", { name: /^Reparatur/ }).click();
  await contact.getByRole("button", { name: "Weiter" }).click();
  await contact.getByRole("button", { name: "Notebook" }).click();
  await contact.getByLabel("Was ist passiert?").fill("Startet seit gestern nicht mehr.");
  await contact.getByRole("button", { name: "Weiter" }).click();
  await contact.getByLabel("Name").fill("Max Muster");
  await contact.getByLabel("E-Mail").fill("max@example.at");
  await contact.getByLabel(/Meine Angaben und Fotos/).check();
  return contact;
}

test("asking for a review later needs a yes, which is off at first (#71)", async ({ page }) => {
  const sent = await mockApi(page);
  let contact = await inquiryUpToContact(page);
  const ask = contact.getByRole("checkbox", { name: /um eine kurze Bewertung bitten/ });
  await expect(ask).not.toBeChecked();
  await contact.getByRole("button", { name: "Anfrage senden" }).click();
  await expect(contact.getByText("ANF-NEU12345")).toBeVisible();
  expect(sent.find((item) => item.path === "/inquiries").body.review_ok).toBe(false);

  await contact.getByRole("button", { name: "Neue Anfrage" }).click();
  contact = await inquiryUpToContact(page);
  await contact.getByRole("checkbox", { name: /um eine kurze Bewertung bitten/ }).check();
  await contact.getByRole("button", { name: "Anfrage senden" }).click();
  await expect(contact.getByText("ANF-NEU12345")).toBeVisible();
  expect(sent.filter((item) => item.path === "/inquiries")[1].body.review_ok).toBe(true);
});

test("a review through the personal link from the mail (#71)", async ({ page }) => {
  const sent = await mockApi(page);
  await page.goto(`/bewertung#${REVIEW_LINK}`);
  await expect(page.getByText("ANF-BEWERT01")).toBeVisible();
  await page.getByRole("button", { name: "Bewertung senden" }).click();
  await expect(page.getByRole("alert")).toHaveText("Bitte wähle, wie viele Sterne du vergibst.");

  await page.getByRole("radio", { name: "4 von 5 Sternen" }).click();
  await page.getByLabel("Deine Bewertung").fill("Schnell repariert, fair erklärt.");
  await page.getByLabel(/Name, der angezeigt wird/).fill("Max M.");
  await page.getByRole("button", { name: "Bewertung senden" }).click();
  await expect(page.getByRole("alert")).toHaveText("Bitte bestätige, dass die Bewertung auf der Website erscheinen darf.");
  await page.getByLabel(/darf mit diesem Namen auf der Website erscheinen/).check();
  await page.getByRole("button", { name: "Bewertung senden" }).click();

  await expect(page.getByRole("heading", { name: "Danke für deine Bewertung!" })).toBeVisible();
  const body = sent.find((item) => item.path === "/review-invites/submit").body;
  expect(body).toEqual({ token: REVIEW_LINK, rating: 4, text: "Schnell repariert, fair erklärt.", author: "Max M.", publish_ok: true, honeypot: "" });
  await expectNoSidewaysScroll(page);
});

for (const address of ["/bewertung#abgelaufenerLinkAbgelaufenerLink", "/bewertung"]) {
  test(`a used, expired or cut-off link says so: ${address.includes("#") ? "unknown" : "without"} token (#71)`, async ({ page }) => {
    await mockApi(page);
    await page.goto(address);
    await expect(page.getByRole("heading", { name: "Dieser Link ist abgelaufen oder wurde schon verwendet." })).toBeVisible();
    await expect(page.getByRole("link", { name: "Zum Kontaktformular" })).toBeVisible();
  });
}

for (const scheme of ["light", "dark"]) {
  test(`the review form is accessible in ${scheme === "light" ? "hell" : "dunkel"} (#71)`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await mockApi(page);
    await page.goto(`/bewertung#${REVIEW_LINK}`);
    await expect(page.getByRole("button", { name: "Bewertung senden" })).toBeVisible();
    const result = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).analyze();
    expect(result.violations.map((item) => `${item.id}: ${item.nodes.slice(0, 2).map((node) => node.target.join(" ")).join(" | ")}`)).toEqual([]);
    await expectNoSidewaysScroll(page);
  });
}

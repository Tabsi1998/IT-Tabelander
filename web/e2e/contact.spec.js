import { expect, expectNoSidewaysScroll, mockApi, test } from "./fixtures.js";
import { mockPortal } from "./portal-fixtures.js";

// A tiny PNG, as a phone camera would hand it over.
const PHOTO = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=", "base64");

test("send a message (#49)", async ({ page }) => {
  const sent = await mockApi(page);
  await page.goto("/");
  const contact = page.locator("#kontakt");
  await contact.getByLabel("Name").fill("Eva Muster");
  await contact.getByLabel("E-Mail").fill("eva@example.at");
  await contact.getByLabel("Worum geht es?").fill("Öffnungszeiten");
  await contact.getByRole("textbox", { name: "Nachricht" }).fill("Habt ihr am Samstag geöffnet?");
  await contact.getByLabel(/Meine Angaben dürfen/).check();
  await contact.getByRole("button", { name: "Nachricht senden" }).click();
  await expect(contact.getByText("Danke, deine Nachricht ist da.")).toBeVisible();
  const post = sent.find((item) => item.path === "/contact");
  expect(post.body).toMatchObject({ name: "Eva Muster", email: "eva@example.at", topic: "Öffnungszeiten", consent: true, honeypot: "" });
  expect(post.body.request_id).toMatch(/^kontakt-/);
  await expectNoSidewaysScroll(page);
});

test("the full inquiry with a photo (#47)", async ({ page }) => {
  const sent = await mockApi(page);
  await page.goto("/");
  await page.getByRole("banner").getByRole("link", { name: /^Anfrage/ }).first().click();
  const contact = page.locator("#kontakt");
  await expect(contact.getByRole("tab", { name: "Reparatur anfragen" })).toHaveAttribute("aria-selected", "true");

  await contact.getByRole("button", { name: "Weiter" }).click();
  await expect(contact.getByRole("alert")).toHaveText("Bitte wähle, worum es geht.");
  await contact.getByRole("button", { name: /^Reparatur/ }).click();
  await contact.getByRole("button", { name: "Weiter" }).click();

  await contact.getByRole("button", { name: "Notebook" }).click();
  await contact.getByLabel("Hersteller").fill("Lenovo");
  await contact.getByLabel("Was ist passiert?").fill("Startet seit gestern nicht mehr.");
  await contact.getByLabel("Fotos auswählen").setInputFiles({ name: "defekt.png", mimeType: "image/png", buffer: PHOTO });
  await expect(contact.getByRole("img", { name: "Foto defekt.png" })).toBeVisible();
  await expect(contact.getByRole("button", { name: "Weiter" })).toBeEnabled();
  await contact.getByRole("button", { name: "Weiter" }).click();

  await contact.getByLabel("Name").fill("Max Muster");
  await contact.getByLabel("E-Mail").fill("max@example.at");
  await contact.getByLabel(/Meine Angaben und Fotos/).check();
  await contact.getByRole("button", { name: "Anfrage senden" }).click();
  await expect(contact.getByText("ANF-NEU12345")).toBeVisible();

  const upload = sent.find((item) => item.path === "/uploads/repair-attachment");
  expect(upload.raw).toContain("anfrage-");
  const inquiry = sent.find((item) => item.path === "/inquiries").body;
  expect(inquiry).toMatchObject({
    request_type: "repair", device_type: "notebook", manufacturer: "Lenovo", consent: true,
    attachment_ids: ["6523f0000000000000000001"],
    contact: { name: "Max Muster", email: "max@example.at", preferred_contact: "email", contact_type: "private" },
  });
  expect(inquiry.callback_at).toBeUndefined();
  await expect(contact.getByText(/antworte einfach darauf/)).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test("no status page and no phone call is offered any more (#122)", async ({ page }) => {
  await mockApi(page, { portal: true });
  await page.goto("/?kontakt=anfrage");
  const contact = page.locator("#kontakt");
  await expect(contact.getByRole("tab")).toHaveText(["Nachricht", "Reparatur anfragen"]);
  await expect(page.getByText(/Status prüfen|anrufen lassen|Rückruf/)).toHaveCount(0);
  await expect(page.getByRole("complementary", { name: "Direkter Kontakt" }).getByText("Anrufen")).toHaveCount(0);
});

test("the old link from the confirmation mail leads into the customer area (#122)", async ({ page }) => {
  await mockPortal(page);
  await page.goto("/status/view.php?track_id=ITABCDEFGHIJKLMN");
  await expect(page).toHaveURL(/\/kundenbereich\/anfragen\?track_id=ITABCDEFGHIJKLMN$/);
  await expect(page.getByRole("heading", { level: 1, name: "Anmelden ohne Passwort" })).toBeVisible();
});

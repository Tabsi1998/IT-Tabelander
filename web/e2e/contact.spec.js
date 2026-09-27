import { expect, expectNoSidewaysScroll, mockApi, test } from "./fixtures.js";

// A tiny PNG, as a phone camera would hand it over.
const PHOTO = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=", "base64");

test("send a message (#49)", async ({ page }) => {
  const sent = await mockApi(page);
  await page.goto("/");
  const contact = page.locator("#kontakt");
  await contact.getByLabel("Name").fill("Eva Muster");
  await contact.getByLabel("E-Mail").fill("eva@example.at");
  await contact.getByRole("textbox", { name: "Nachricht" }).fill("Habt ihr am Samstag geöffnet?");
  await contact.getByLabel(/Meine Angaben dürfen/).check();
  await contact.getByRole("button", { name: "Nachricht senden" }).click();
  await expect(contact.getByText("Danke, deine Nachricht ist da.")).toBeVisible();
  const post = sent.find((item) => item.path === "/contact");
  expect(post.body).toMatchObject({ name: "Eva Muster", email: "eva@example.at", consent: true, honeypot: "" });
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

  await contact.getByRole("button", { name: "Status ansehen" }).click();
  await expect(contact.getByLabel("Anfrage-Nummer")).toHaveValue("ANF-NEU12345");
  await expectNoSidewaysScroll(page);
});

test("a callback wish needs a phone number and sends the local time (#73)", async ({ page }) => {
  const sent = await mockApi(page);
  await page.goto("/?kontakt=anfrage");
  const contact = page.locator("#kontakt");
  await contact.getByRole("button", { name: /^Beratung/ }).click();
  await contact.getByRole("button", { name: "Weiter" }).click();
  await contact.getByLabel("Was ist passiert?").fill("Welcher Laptop passt fürs Studium?");
  await contact.getByRole("button", { name: "Weiter" }).click();
  await contact.getByLabel("Name").fill("Max Muster");
  await contact.getByLabel("E-Mail").fill("max@example.at");
  await contact.getByLabel(/Lieber anrufen lassen/).check();
  await contact.getByLabel(/Meine Angaben und Fotos/).check();
  await contact.getByRole("button", { name: "Anfrage senden" }).click();
  await expect(contact.getByRole("alert")).toHaveText("Für einen Anruf brauche ich deine Telefonnummer.");
  await contact.getByLabel("Telefon").fill("+43 660 1234567");
  await contact.getByLabel("Uhrzeit").selectOption("14:00");
  await contact.getByRole("button", { name: "Anfrage senden" }).click();
  await expect(contact.getByText("ANF-NEU12345")).toBeVisible();
  const inquiry = sent.find((item) => item.path === "/inquiries").body;
  expect(inquiry.callback_at).toMatch(/T14:00:00[+-]\d\d:\d\d$/);
  expect(inquiry.contact.preferred_contact).toBe("phone");
});

const STEP_LABELS = {
  eingegangen: "Eingegangen", angebot_bereit: "Angebot bereit", in_arbeit: "In Arbeit",
  wartet_auf_dich: "Wartet auf dich", pausiert: "Pausiert", abholbereit: "Abholbereit",
  abgeschlossen: "Abgeschlossen", abgebrochen: "Beendet",
};

for (const [step, label] of Object.entries(STEP_LABELS)) {
  test(`status shows "${label}" (#48)`, async ({ page }) => {
    await mockApi(page, { status: step });
    await page.goto("/?kontakt=status");
    const contact = page.locator("#kontakt");
    await contact.getByLabel("Anfrage-Nummer").fill("anf-abcd1234");
    await contact.getByLabel("E-Mail aus der Anfrage").fill("kunde@example.at");
    await contact.getByRole("button", { name: "Status anzeigen" }).click();
    await expect(contact.getByRole("status")).toContainText(label);
    await expect(contact.getByRole("status")).toContainText("ANF-ABCD1234");
  });
}

test("a wrong combination of number and e-mail says so (#48)", async ({ page }) => {
  await mockApi(page);
  await page.goto("/?kontakt=status");
  const contact = page.locator("#kontakt");
  await contact.getByLabel("Anfrage-Nummer").fill("ANF-ABCD1234");
  await contact.getByLabel("E-Mail aus der Anfrage").fill("fremd@example.at");
  await contact.getByRole("button", { name: "Status anzeigen" }).click();
  await expect(contact.getByRole("alert")).toContainText("Keine Anfrage mit dieser Nummer und E-Mail gefunden.");
});

test("the link from the confirmation mail shows the status at once (#48)", async ({ page }) => {
  await mockApi(page);
  await page.goto("/status/view.php?track_id=ITABCDEFGHIJKLMN");
  const contact = page.locator("#kontakt");
  await expect(contact.getByRole("tab", { name: "Status prüfen" })).toHaveAttribute("aria-selected", "true");
  await expect(contact.getByRole("status")).toContainText("In Arbeit");
  await expect(contact.getByRole("status")).toContainText("ANF-LINK0001");
});

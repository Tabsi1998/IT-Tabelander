import AxeBuilder from "@axe-core/playwright";
import { mkdirSync } from "node:fs";
import { expect, expectNoSidewaysScroll, test } from "./fixtures.js";
import { PHOTO, SIGN_IN_LINK, TRACK_ID, mockPortal, portalState } from "./portal-fixtures.js";

async function signedIn(page, path = "/kundenbereich") {
  const state = await mockPortal(page, portalState({ signedIn: true }));
  await page.goto(path);
  return state;
}

async function expectAccessible(page) {
  const result = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).analyze();
  expect(result.violations.map((item) => `${item.id}: ${item.nodes.slice(0, 2).map((node) => node.target.join(" ")).join(" | ")}`)).toEqual([]);
}

test("sign in with the link from the mail (#63)", async ({ page }) => {
  const state = await mockPortal(page);
  await page.goto("/kundenbereich");
  await expect(page.getByRole("heading", { level: 1, name: "Anmelden ohne Passwort" })).toBeVisible();
  await page.getByLabel("E-Mail").fill("kunde@example.at");
  await page.getByRole("button", { name: "Anmelde-Link senden" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Schau in dein Postfach" })).toBeVisible();
  expect(state.sent.find((item) => item.path === "/login").body).toEqual({ email: "kunde@example.at" });

  await page.goto(`/kundenbereich/anmelden#${SIGN_IN_LINK}`);
  await expect(page.getByRole("heading", { level: 1, name: "Dein Kundenbereich" })).toBeVisible();
  await expect(page).toHaveURL(/\/kundenbereich$/);
  await expect(page.getByText("für Max Muster")).toBeVisible();
  // The code went out exactly once, in the body.
  expect(state.sent.filter((item) => item.path === "/session")).toEqual([{ method: "POST", path: "/session", body: { token: SIGN_IN_LINK } }]);

  await page.getByRole("button", { name: "Abmelden" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Anmelden ohne Passwort" })).toBeVisible();
});

test("an old or used link says so and offers a new one (#63)", async ({ page }) => {
  await mockPortal(page);
  await page.goto("/kundenbereich/anmelden#abgelaufenerLinkAbgelaufenerLink1");
  await expect(page.getByRole("alert")).toHaveText(/abgelaufen oder wurde schon verwendet/);
  await page.getByRole("link", { name: "Neuen Link anfordern" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Anmelden ohne Passwort" })).toBeVisible();
});

test("the customer area stays hidden until the owner switches it on (#63)", async ({ page }) => {
  await mockPortal(page, portalState({ enabled: false }));
  await page.goto("/");
  await expect(page.getByRole("link", { name: "Kundenbereich" })).toHaveCount(0);
  await page.goto("/kundenbereich");
  await expect(page.getByRole("heading", { level: 1, name: "Der Kundenbereich kommt bald" })).toBeVisible();
});

test("once on, the header and the footer lead to it (#63)", async ({ page }) => {
  await mockPortal(page);
  await page.goto("/");
  await expect(page.getByRole("contentinfo").getByRole("link", { name: "Kundenbereich" })).toBeVisible();
});

test("the overview says what is open (#64)", async ({ page }) => {
  await signedIn(page);
  await expect(page.getByRole("link", { name: /Angebote zum Annehmen\s*1/ })).toBeVisible();
  await expect(page.getByText("Angebot wartet auf dich")).toBeVisible();
  await expect(page.getByRole("link", { name: /Rechnung FA2609-0012/ })).toContainText("89,00");
  await expect(page.getByRole("link", { name: /Reparatur: Notebook Lenovo ThinkPad T14/ })).toContainText("ANF-7K3M9Q2X");

  await page.goto("/kundenbereich/anfragen");
  await expect(page.getByRole("heading", { level: 1, name: "Anfragen" })).toBeVisible();
  const done = page.getByRole("listitem").filter({ hasText: "Controller-Umbau" });
  await expect(done.getByText("Abgeschlossen", { exact: true })).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test("accept an offer: withdrawal right first, a button that says it costs money (#65)", async ({ page }) => {
  const state = await signedIn(page, "/kundenbereich/angebote/31");
  await expect(page.getByRole("heading", { level: 1, name: "Angebot PR2609-0007" })).toBeVisible();
  await expect(page.getByRole("cell", { name: /Akku tauschen/ })).toBeVisible();
  await expect(page.getByRole("link", { name: "Angebot als PDF" })).toHaveAttribute("href", "/api/portal/offers/31/pdf");
  const answer = page.getByRole("region", { name: "Angebot annehmen" });
  await expect(answer.getByText("Dein Rücktrittsrecht:")).toBeVisible();
  await expect(answer.getByText(/Gesamtpreis:/)).toContainText("189,00");

  await answer.getByRole("button", { name: "Angebot kostenpflichtig annehmen" }).click();
  await expect(answer.getByRole("alert")).toHaveText("Bitte gib zur Bestätigung deinen Namen an.");
  await answer.getByLabel("Dein Name zur Bestätigung").fill("Max Muster");
  await answer.getByRole("checkbox", { name: /Bitte sofort beginnen/ }).check();
  await answer.getByRole("button", { name: "Angebot kostenpflichtig annehmen" }).click();

  await expect(page.getByText("Angebot angenommen – danke!")).toBeVisible();
  expect(state.sent.find((item) => item.path === "/offers/31/answer").body)
    .toEqual({ accept: true, name: "Max Muster", start_now: true, reason: "" });
  await expect(page.getByRole("button", { name: "Angebot kostenpflichtig annehmen" })).toHaveCount(0);
  // Withdrawing online, without another sign-in (§ 13a FAGG, #142).
  await expect(page.getByRole("region", { name: "Rücktritt" }).getByRole("link", { name: "Vertrag widerrufen" }))
    .toHaveAttribute("href", "/rechtliches/widerruf?vertrag=PR2609-0007#vertrag-widerrufen");
});

test("decline an offer with a reason (#65)", async ({ page }) => {
  const state = await signedIn(page, "/kundenbereich/angebote/31");
  await page.getByRole("button", { name: "Ablehnen" }).click();
  await page.getByLabel(/Grund/).fill("Zu teuer für das alte Gerät.");
  await page.getByRole("button", { name: "Angebot ablehnen" }).click();
  await expect(page.getByText(/ich habe deine Absage bekommen/)).toBeVisible();
  expect(state.sent.find((item) => item.path === "/offers/31/answer").body)
    .toEqual({ accept: false, name: "", start_now: false, reason: "Zu teuer für das alte Gerät." });
});

test("an open invoice: transfer data to copy and the code for the banking app (#66)", async ({ page, context }) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await signedIn(page, "/kundenbereich/rechnungen/41");
  await expect(page.getByRole("heading", { level: 1, name: "Rechnung FA2609-0012" })).toBeVisible();
  const transfer = page.getByRole("region", { name: "Überweisen" });
  await expect(transfer.getByRole("img", { name: /QR-Code für die Banking-App: €\s89,00 an Jürgen Müller, Verwendungszweck FA2609-0012/ })).toBeVisible();
  await expect(transfer.getByText("AT61 1904 3002 3457 3201")).toBeVisible();
  await transfer.getByRole("button", { name: "IBAN kopieren" }).click();
  await expect(transfer.getByRole("button", { name: "Kopiert" })).toBeVisible();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe("AT611904300234573201");
  await expectNoSidewaysScroll(page);
});

test("what waits for the customer stands first and leads to the inquiry (#122)", async ({ page }) => {
  await signedIn(page);
  const waiting = page.getByRole("link", { name: /Wartet auf dich.*Reparatur: PlayStation 5/ });
  await expect(waiting).toContainText("Jetzt antworten");
  await expect(page.getByRole("link", { name: /Offene Anfragen\s*2/ })).toBeVisible();
  const nav = page.getByRole("navigation", { name: page.viewportSize().width < 1024 ? "Kundenbereich unten" : "Kundenbereich", exact: true });
  await expect(nav.getByRole("link", { name: /Anfragen/ })).toBeVisible();
  await waiting.click();
  await expect(page).toHaveURL(/\/kundenbereich\/anfragen\/8$/);
  await expect(page.getByRole("heading", { level: 1, name: "Reparatur: PlayStation 5" })).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test("an inquiry shows its stand, the timeline and the whole history (#122)", async ({ page }) => {
  await signedIn(page, "/kundenbereich/anfragen/8");
  await expect(page.getByText("ANF-9Z8Y7X6W · Ticket TS2609-0008")).toBeVisible();
  const timeline = page.getByRole("list", { name: "Zeitstrahl" });
  await expect(timeline.getByRole("listitem")).toHaveCount(5);
  await expect(timeline.locator("[aria-current=step]")).toContainText("Wartet auf dich");
  const history = page.getByRole("region", { name: "Verlauf" });
  await expect(history.getByRole("listitem")).toHaveCount(2);
  await expect(history.getByRole("listitem").first()).toContainText("Du");
  await expect(history.getByRole("listitem").first()).toContainText("Das Laufwerk zieht keine Discs mehr ein.");
  await expect(history.getByRole("listitem").nth(1)).toContainText("IT-Tabelander");
  await expect(history.getByRole("listitem").nth(1)).toContainText("per E-Mail");
  await expectNoSidewaysScroll(page);
});

test("answer an inquiry: the message lands in the history (#122)", async ({ page }) => {
  const state = await signedIn(page, "/kundenbereich/anfragen/8");
  const form = page.getByRole("form", { name: "Antworten" });
  await form.getByRole("button", { name: "Nachricht senden" }).click();
  await expect(form.getByRole("alert")).toHaveText("Bitte schreib kurz, was du mitteilen möchtest.");
  await form.getByLabel(/Deine Nachricht/).fill("Die mit Laufwerk, gekauft 2021.");
  await form.getByRole("button", { name: "Nachricht senden" }).click();
  await expect(form.getByRole("status")).toContainText("Danke, deine Nachricht ist da.");
  await expect(page.getByRole("region", { name: "Verlauf" }).getByRole("listitem").last()).toContainText("Die mit Laufwerk, gekauft 2021.");
  expect(state.sent.find((item) => item.path === "/inquiries/8/messages").body).toEqual({ message: "Die mit Laufwerk, gekauft 2021.", files: [] });
  await expect(form.getByLabel(/Deine Nachricht/)).toHaveValue("");
});

test("the ticket's files show and open; a photo and a PDF go with an answer (#123)", async ({ page }) => {
  const state = await signedIn(page, "/kundenbereich/anfragen/8");
  const files = page.getByRole("region", { name: "Dateien" });
  await expect(files.getByRole("link", { name: /kostenvoranschlag\.pdf/ })).toHaveAttribute("href",
    "/api/portal/inquiries/8/files/kostenvoranschlag.pdf");
  await expect(files.getByRole("link", { name: /laufwerk\.webp/ })).toContainText("von dir");

  const form = page.getByRole("form", { name: "Antworten" });
  await form.getByLabel("Datei hinzufügen").setInputFiles([
    { name: "notiz.txt", mimeType: "text/plain", buffer: Buffer.from("nein") },
  ]);
  await expect(form.getByRole("alert")).toContainText("nur Fotos (JPG, PNG, WebP) oder PDF-Dateien");
  await form.getByLabel("Datei hinzufügen").setInputFiles([
    { name: "seriennummer.png", mimeType: "image/png", buffer: PHOTO },
    { name: "kaufbeleg.pdf", mimeType: "application/pdf", buffer: Buffer.from("%PDF-1.4\n%%EOF\n") },
  ]);
  const picked = form.getByRole("list", { name: "Ausgewählte Dateien" });
  await expect(picked.getByRole("listitem")).toHaveCount(2);
  await form.getByRole("button", { name: "Nachricht senden" }).click();
  await expect(form.getByRole("status")).toContainText("Danke, deine Nachricht ist da.");
  const uploads = state.sent.filter((item) => item.path === "/inquiries/8/files");
  expect(uploads).toHaveLength(2);
  const message = state.sent.find((item) => item.path === "/inquiries/8/messages").body;
  expect(message.message).toBe("");
  expect(message.files).toHaveLength(2);
  await expect(page.getByRole("region", { name: "Verlauf" }).getByRole("listitem").last()).toContainText("Angehängt:");
  await expect(files.getByRole("listitem")).toHaveCount(4);
  await expectNoSidewaysScroll(page);
});

test("a new inquiry in the customer area, with a file, then straight to it (#123)", async ({ page }) => {
  const state = await signedIn(page, "/kundenbereich/anfragen");
  await page.getByRole("link", { name: "Neue Anfrage" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Neue Anfrage" })).toBeVisible();
  const form = page.getByRole("form", { name: "Neue Anfrage" });
  await form.getByRole("button", { name: "Anfrage senden" }).click();
  await expect(form.getByRole("alert")).toHaveText("Bitte wähle, worum es geht.");
  await form.getByRole("button", { name: /^Reparatur/ }).click();
  await form.getByRole("button", { name: "Notebook" }).click();
  await form.getByLabel("Hersteller").fill("Lenovo");
  await form.getByLabel("Was ist passiert?").fill("Der Bildschirm flackert seit dem Sturz.");
  await form.getByLabel("Datei hinzufügen").setInputFiles({ name: "display.png", mimeType: "image/png", buffer: PHOTO });
  await form.getByRole("button", { name: "Anfrage senden" }).click();
  await expect(page).toHaveURL(/\/kundenbereich\/anfragen\/9$/);
  await expect(page.getByRole("status")).toContainText("Danke, deine Anfrage ist da.");
  expect(state.sent.find((item) => item.path === "/inquiries" && item.method === "POST").body).toEqual({
    request_type: "repair", device_type: "notebook", manufacturer: "Lenovo", model: "",
    description: "Der Bildschirm flackert seit dem Sturz.",
  });
  expect(state.sent.filter((item) => item.path === "/inquiries/9/files")).toHaveLength(1);
  await expect(page.getByRole("region", { name: "Dateien" }).getByRole("listitem")).toHaveCount(1);
  await expectNoSidewaysScroll(page);
});

test("close an inquiry after a question; closed stays readable (#122)", async ({ page }) => {
  const state = await signedIn(page, "/kundenbereich/anfragen/5");
  await page.getByRole("button", { name: "Hat sich erledigt" }).click();
  const ask = page.getByRole("group", { name: /Anfrage wirklich schließen/ });
  await ask.getByRole("button", { name: "Nein, offen lassen" }).click();
  expect(state.sent.filter((item) => item.path === "/inquiries/5/close")).toEqual([]);
  await page.getByRole("button", { name: "Hat sich erledigt" }).click();
  await page.getByRole("button", { name: "Ja, Anfrage schließen" }).click();
  await expect(page.getByRole("status")).toContainText("die Anfrage ist geschlossen");
  await expect(page.getByRole("form", { name: "Antworten" })).toHaveCount(0);
  await expect(page.getByText("antworten geht hier nicht mehr")).toBeVisible();
  await expect(page.getByRole("region", { name: "Verlauf" })).toContainText("Der Akku hält nur noch 20 Minuten.");
});

test("someone else's inquiry is not there (#122)", async ({ page }) => {
  await signedIn(page, "/kundenbereich/anfragen/99");
  await expect(page.getByRole("alert")).toHaveText(/Diese Anfrage gibt es hier nicht/);
  await expect(page.getByRole("link", { name: "← Alle Anfragen" })).toBeVisible();
});

test("the link from Dolibarr's mail: sign in, then straight to the inquiry (#122)", async ({ page }) => {
  const state = await mockPortal(page);
  await page.goto(`/kundenbereich/anfragen?track_id=${TRACK_ID}`);
  await expect(page.getByText("Danach geht es direkt zu deiner Anfrage.")).toBeVisible();
  await page.getByLabel("E-Mail").fill("kunde@example.at");
  await page.getByRole("button", { name: "Anmelde-Link senden" }).click();
  expect(state.sent.find((item) => item.path === "/login").body)
    .toEqual({ email: "kunde@example.at", next: `/kundenbereich/anfragen?track_id=${TRACK_ID}` });

  await page.goto(`/kundenbereich/anmelden?ziel=${encodeURIComponent(`/kundenbereich/anfragen?track_id=${TRACK_ID}`)}#${SIGN_IN_LINK}`);
  await expect(page).toHaveURL(/\/kundenbereich\/anfragen\/8$/);
  await expect(page.getByRole("heading", { level: 1, name: "Reparatur: PlayStation 5" })).toBeVisible();

  // A link to another customer's inquiry shows the own list instead.
  await page.goto("/kundenbereich/anfragen?track_id=ITFREMDESTICKET1");
  await expect(page.getByRole("status")).toContainText("gehört nicht zu deiner Anmeldung");
  await expect(page.getByRole("heading", { level: 1, name: "Anfragen" })).toBeVisible();
});

test("a target outside the customer area is ignored after sign-in (#122)", async ({ page }) => {
  await mockPortal(page);
  await page.goto(`/kundenbereich/anmelden?ziel=${encodeURIComponent("https://example.com/")}#${SIGN_IN_LINK}`);
  await expect(page).toHaveURL(/\/kundenbereich$/);
});

// The documents of the list, not the filter above it.
const DOCUMENT_LINK = /^(Rechnung|Gutschrift|Auftragsbestätigung|Lieferschein|Arbeitsbericht|Vertrag) /;

test("every document under Belege, filtered by kind (#100)", async ({ page }) => {
  await signedIn(page, "/kundenbereich/belege");
  await expect(page.getByRole("heading", { level: 1, name: "Belege" })).toBeVisible();
  const kinds = page.getByRole("navigation", { name: "Belegarten" });
  await expect(kinds.getByRole("link", { name: /Alle/ })).toHaveAttribute("aria-current", "page");
  for (const ref of ["FA2609-0012", "AV2609-0001", "CO2609-0004", "SH2609-0002", "FI2609-0003", "CT2609-0001"]) {
    await expect(page.getByRole("main").getByRole("link", { name: new RegExp(ref) })).toBeVisible();
  }
  await expect(page.getByRole("link", { name: /Gutschrift AV2609-0001/ })).toContainText("Gutschrift");

  await kinds.getByRole("link", { name: /Lieferscheine/ }).click();
  await expect(page).toHaveURL(/\/kundenbereich\/belege\/lieferscheine$/);
  await expect(page.getByRole("heading", { level: 1, name: "Lieferscheine" })).toBeVisible();
  await expect(page.getByRole("main").getByRole("link", { name: DOCUMENT_LINK })).toHaveCount(1);
  await page.getByRole("link", { name: /Lieferschein SH2609-0002/ }).click();
  await expect(page).toHaveURL(/\/kundenbereich\/belege\/lieferscheine\/61$/);
  await expect(page.getByRole("heading", { level: 1, name: "Lieferschein SH2609-0002" })).toBeVisible();
  await expect(page.getByText("Sendungsnummer AT123456789")).toBeVisible();
  await expect(page.getByRole("link", { name: "Lieferschein als PDF" })).toHaveAttribute("href", "/api/portal/documents/shipments/61/pdf");

  // The invoices keep their address from the mails and belong to the tab Belege.
  await page.goto("/kundenbereich/rechnungen");
  await expect(page.getByRole("heading", { level: 1, name: "Rechnungen" })).toBeVisible();
  const nav = page.getByRole("navigation", { name: page.viewportSize().width < 1024 ? "Kundenbereich unten" : "Kundenbereich", exact: true });
  await expect(nav.getByRole("link", { name: /Belege/ })).toHaveAttribute("aria-current", "page");
  await expect(page.getByRole("main").getByRole("link", { name: DOCUMENT_LINK })).toHaveCount(3);
  await expectNoSidewaysScroll(page);
});

test("the overview shows the running services with their terms (#101)", async ({ page }) => {
  await signedIn(page);
  const services = page.getByRole("region", { name: "Laufende Leistungen" });
  const service = services.getByRole("link", { name: /Wartung Büro-PCs/ });
  await expect(service).toContainText("monatlich");
  await expect(service).toContainText("45,00");
  await expect(service).toContainText("Mindestlaufzeit 12 Monate · Kündigungsfrist 3 Monate zum Quartalsende");
  await service.click();
  await expect(page).toHaveURL(/\/kundenbereich\/belege\/vertraege\/81$/);
  await expect(page.getByRole("heading", { level: 1, name: "Vertrag CT2609-0001" })).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test("a work report and a contract show what was done and what runs (#100)", async ({ page }) => {
  await signedIn(page, "/kundenbereich/belege/arbeitsberichte/71");
  await expect(page.getByRole("heading", { level: 1, name: "Arbeitsbericht FI2609-0003" })).toBeVisible();
  await expect(page.getByText("Notebook gereinigt und Lüfter getauscht.")).toBeVisible();
  await expect(page.getByRole("row", { name: /Gesamt/ })).toContainText("1,5 Std.");
  await expect(page.getByRole("link", { name: "Arbeitsbericht als PDF" })).toHaveAttribute("href", "/api/portal/documents/interventions/71/pdf");

  await page.goto("/kundenbereich/belege/vertraege/81");
  await expect(page.getByRole("heading", { level: 1, name: "Vertrag CT2609-0001" })).toBeVisible();
  await expect(page.getByText("Mindestlaufzeit")).toBeVisible();
  await expect(page.getByText("3 Monate zum Quartalsende")).toBeVisible();
  await expect(page.getByRole("cell", { name: /Wartung Büro-PCs/ })).toContainText("läuft");
  await expect(page.getByText("Für diesen Beleg gibt es noch kein PDF.")).toBeVisible();

  await page.goto("/kundenbereich/belege/vertraege/99");
  await expect(page.getByRole("alert")).toHaveText(/Das gibt es hier nicht/);
  await page.goto("/kundenbereich/belege/unbekannt");
  await expect(page).toHaveURL(/\/kundenbereich\/belege$/);
});

for (const scheme of ["light", "dark"]) {
  for (const [name, path, heading, signIn] of [
    ["anmelden", "/kundenbereich", "Anmelden ohne Passwort", false],
    ["anfrage", "/kundenbereich/anfragen/8", "Reparatur: PlayStation 5", true],
    ["anfrage-geschlossen", "/kundenbereich/anfragen/3", "Controller-Umbau: Controller", true],
    ["anfragen", "/kundenbereich/anfragen", "Anfragen", true],
    ["neue-anfrage", "/kundenbereich/anfragen/neu", "Neue Anfrage", true],
    ["uebersicht", "/kundenbereich", "Dein Kundenbereich", true],
    ["angebot", "/kundenbereich/angebote/31", "Angebot PR2609-0007", true],
    ["rechnung", "/kundenbereich/rechnungen/41", "Rechnung FA2609-0012", true],
    ["belege", "/kundenbereich/belege", "Belege", true],
    ["auftrag", "/kundenbereich/belege/auftraege/51", "Auftragsbestätigung CO2609-0004", true],
    ["arbeitsbericht", "/kundenbereich/belege/arbeitsberichte/71", "Arbeitsbericht FI2609-0003", true],
    ["vertrag", "/kundenbereich/belege/vertraege/81", "Vertrag CT2609-0001", true],
  ]) {
    test(`customer area ${name} is accessible in ${scheme === "light" ? "hell" : "dunkel"} (#63)`, async ({ page }, testInfo) => {
      await page.emulateMedia({ colorScheme: scheme });
      await mockPortal(page, portalState({ signedIn: signIn }));
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1, name: heading })).toBeVisible();
      await page.waitForLoadState("networkidle");
      await expectAccessible(page);
      await expectNoSidewaysScroll(page);
      if (scheme === "light") {
        mkdirSync("screenshots", { recursive: true });
        const shot = `${testInfo.project.name}-kundenbereich-${name}`;
        const body = await page.screenshot({ fullPage: true, path: `screenshots/${shot}.png` });
        await testInfo.attach(shot, { body, contentType: "image/png" });
      }
    });
  }
}

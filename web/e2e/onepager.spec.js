import { expect, expectNoSidewaysScroll, mockApi, test } from "./fixtures.js";

test("the built page carries its text without JavaScript (#44)", async ({ request }) => {
  const html = await (await request.get("/")).text();
  expect(html).toContain("IT-Technik, die");
  expect(html).toContain("Was ich für dich mache");
  expect(html).toContain("Von der Anfrage bis zur Abholung");
  expect(html).toContain("Wie kann ich helfen?");
  expect(html).not.toContain("fonts.googleapis.com");
  const legal = await (await request.get("/rechtliches/datenschutz/")).text();
  expect(legal).toContain("<title>Datenschutzerklärung – IT-Tabelander</title>");
});

test("one-pager with all sections, own fonts, no foreign requests (#45, #46)", async ({ page, foreign }) => {
  await mockApi(page);
  const fonts = [];
  page.on("request", (request) => { if (/\.woff2?$/.test(request.url())) fonts.push(request.url()); });
  await page.goto("/");
  await expect(page.getByRole("heading", { level: 1 })).toContainText("IT-Technik, die");
  for (const title of ["Was ich für dich mache", "Von der Anfrage bis zur Abholung", "Zuletzt gemacht", "Was Kunden sagen",
    "Jemand, der Hardware wirklich versteht", "Wie kann ich helfen?"]) {
    await expect(page.getByRole("heading", { name: title })).toBeVisible();
  }
  await expect(page.getByRole("contentinfo")).toContainText("office@example.at");
  await page.evaluate(() => document.fonts.ready);
  expect(fonts.length).toBeGreaterThan(0);
  expect(foreign).toEqual([]);
  await expectNoSidewaysScroll(page);
});

test("services switch as tabs, also with the keyboard", async ({ page }) => {
  await mockApi(page);
  await page.goto("/");
  const tabs = page.getByRole("tablist", { name: "Leistungen" });
  await expect(tabs.getByRole("tab")).toHaveCount(3);
  await tabs.getByRole("tab", { name: "PC nach Wunsch" }).click();
  await expect(page.getByRole("heading", { name: "Dein PC, passend gebaut" })).toBeVisible();
  await tabs.getByRole("tab", { name: "PC nach Wunsch" }).press("ArrowRight");
  await expect(tabs.getByRole("tab", { name: "Controller" })).toHaveAttribute("aria-selected", "true");
  await expect(page.getByRole("heading", { name: "Stick Drift adé" })).toBeVisible();
});

test("a service link opens its tab", async ({ page }) => {
  await mockApi(page);
  await page.goto("/leistungen/controller-reparatur");
  await expect(page.getByRole("tab", { name: "Controller" })).toHaveAttribute("aria-selected", "true");
});

for (const [count, expected] of [[0, 0], [3, 3], [30, 8]]) {
  test(`workshop with ${count} photos (#50)`, async ({ page }) => {
    await mockApi(page, { gallery: count });
    await page.goto("/");
    await expect(page.getByRole("heading", { name: "Wie kann ich helfen?" })).toBeVisible();
    const section = page.locator("#werkstatt");
    if (!count) {
      await expect(section).toHaveCount(0);
      await expect(page.getByRole("navigation", { name: "Bereiche" }).getByRole("link", { name: "Werkstatt" })).toHaveCount(0);
    } else {
      await expect(section.getByRole("img")).toHaveCount(expected);
      await expect(section.getByRole("button", { name: "Mehr Fotos anzeigen" })).toHaveCount(count > 8 ? 1 : 0);
    }
    await expectNoSidewaysScroll(page);
  });
}

for (const count of [0, 1, 12]) {
  test(`reviews with ${count} entries (#51)`, async ({ page }) => {
    await mockApi(page, { reviewCount: count });
    await page.goto("/");
    await expect(page.getByRole("heading", { name: "Wie kann ich helfen?" })).toBeVisible();
    const section = page.locator("#bewertungen");
    if (!count) {
      await expect(section).toHaveCount(0);
    } else {
      await expect(section.getByRole("img", { name: "5 von 5 Sternen" })).toHaveCount(Math.min(count, 3));
      await expect(section.getByRole("link", { name: "Auf Google bewerten" })).toHaveAttribute("href", "https://g.page/r/bewerten");
    }
    await expectNoSidewaysScroll(page);
  });
}

test("the menu opens, leads to a section and closes with Escape", async ({ page }) => {
  test.skip(page.viewportSize().width >= 1024, "the menu button shows below 1024 px");
  await mockApi(page);
  await page.goto("/");
  await page.getByRole("button", { name: "Menü öffnen" }).click();
  const menu = page.getByRole("dialog", { name: "Menü" });
  await expect(menu.getByRole("link", { name: /Über mich/ })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(menu).toHaveCount(0);
  await page.getByRole("button", { name: "Menü öffnen" }).click();
  await menu.getByRole("link", { name: /Status prüfen/ }).click();
  await expect(menu).toHaveCount(0);
  await expect(page.getByRole("tab", { name: "Status prüfen" })).toHaveAttribute("aria-selected", "true");
});

test("legal texts as tabs, a draft says so (#52)", async ({ page }) => {
  await mockApi(page, { legal: {
    impressum: { title: "Impressum", html: "<p>IT-Tabelander<br>Teststraße 1</p>" },
    nutzungsbedingungen: { title: "Nutzungsbedingungen", html: "<p>Test-Bedingungen</p>", draft: true },
  } });
  await page.goto("/rechtliches/impressum");
  await expect(page.getByText("Teststraße 1")).toBeVisible();
  await expect(page.getByText("Entwurf: Dieser Text ist in Dolibarr noch nicht freigegeben.")).toHaveCount(0);
  await page.getByRole("navigation", { name: "Rechtliche Texte" }).getByRole("link", { name: "Nutzungsbedingungen" }).click();
  await expect(page.getByText("Entwurf: Dieser Text ist in Dolibarr noch nicht freigegeben.")).toBeVisible();
  await page.getByRole("navigation", { name: "Rechtliche Texte" }).getByRole("link", { name: "Datenschutz" }).click();
  await expect(page.getByText("Dieser Text ist noch nicht hinterlegt.")).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test("an unknown address shows the error page", async ({ page }) => {
  await mockApi(page);
  await page.goto("/gibt-es-nicht");
  await expect(page.getByRole("heading", { name: "Diese Seite gibt es nicht." })).toBeVisible();
  await page.getByRole("link", { name: "Zur Startseite" }).first().click();
  await expect(page.getByRole("heading", { level: 1 })).toContainText("IT-Technik, die");
});

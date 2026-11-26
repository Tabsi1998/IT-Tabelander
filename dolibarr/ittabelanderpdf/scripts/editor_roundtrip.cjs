// Open e-mail templates in Dolibarr's editor (CKEditor) and save them unchanged,
// the way a person would (scripts/dev_dolibarr.py, stage "editor").
//   node editor_roundtrip.cjs <playwright module> <base url> <login.json> <rowid,rowid,...>
// Only for the disposable local Dolibarr.
const { chromium } = require(process.argv[2]);
const fs = require('fs');

const base = process.argv[3];
const login = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'));
const rowids = process.argv[5].split(',').filter(Boolean);

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto(base + '/index.php');
  await page.fill('#username', login.login);
  await page.fill('#password', login.password);
  await Promise.all([page.waitForNavigation(), page.click('input[type=submit], button[type=submit]')]);
  const result = {};
  for (const rowid of rowids) {
    await page.goto(base + '/admin/mails_templates.php?sortfield=type_template,lang,position,label&sortorder=ASC');
    const edit = page.locator(`a[href*="rowid=${rowid}&"][href*="action=edit"]`).first();
    await Promise.all([page.waitForNavigation(), edit.click()]);
    // The editor has loaded the text when its instance is ready.
    await page.waitForFunction(() => window.CKEDITOR && Object.values(window.CKEDITOR.instances).some((i) => i.status === 'ready'), null, { timeout: 30000 });
    const save = page.locator('input[name="actionmodify"]').first();
    await Promise.all([page.waitForNavigation(), save.click()]);
    result[rowid] = 'saved';
  }
  await browser.close();
  console.log(JSON.stringify(result));
})().catch((error) => {
  console.error(error);
  process.exit(1);
});

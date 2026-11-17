const { chromium } = require('playwright');
async function main() {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const context = browser.contexts()[0]; const page = context.pages()[0];
  page.on('pageerror', e => console.log('PAGEERROR:', e.message));
  page.on('console', m => console.log('CONSOLE:', m.type(), m.text()));
  await page.goto('http://localhost/greenportal/index.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: 'Modelo' });
  await page.locator('#userid').fill('admin'); await page.locator('#senha').fill(process.env.GREEN_TEST_PASSWORD || '');
  await page.locator('form.login-form button[type="submit"]').click(); await page.waitForTimeout(1500);
  await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais');
  await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click(); await page.waitForTimeout(1500);
  const frame = page.frames().find(f => f.url().includes('modulolegal_requisitoslegais.php'));
  const state = await frame.evaluate(() => {
    const fragment = document.querySelector('.modulo-legal-requisitos-fragment');
    const button = document.querySelector('[data-open-legislacao-pdf]');
    const events = window.jQuery && fragment ? window.jQuery._data(fragment, 'events') : null;
    return { fragment: !!fragment, button: button ? button.outerHTML : null, jquery: !!window.jQuery, bootstrap: !!window.bootstrap, eventTypes: events ? Object.keys(events) : [] };
  });
  console.log(JSON.stringify(state));
  const row = frame.locator('#requisitosLegaisBatchGrid tbody tr').first(); await row.click();
  await frame.locator('[data-open-legislacao-pdf]').first().click({ force: true }); await page.waitForTimeout(1500);
  console.log(JSON.stringify(await frame.evaluate(() => ({ pdfModal: !!document.querySelector('#greenPortalPdfModal'), oldModal: !!document.querySelector('#requisitosPdfModal'), bodyHas: document.body.innerHTML.includes('greenPortalPdfModal') }))));
  await browser.close();
}
main().catch(e => { console.error(e.stack || e.message); process.exit(1); });

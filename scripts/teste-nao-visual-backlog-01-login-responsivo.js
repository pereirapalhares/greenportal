const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const output = path.resolve(arg('--saida', 'tests/resultado-backlog-01-login-responsivo.json'));
const timeout = Number(arg('--timeout', '30000'));
const viewports = [{ width: 1024, height: 768 }, { width: 1280, height: 720 }, { width: 1440, height: 900 }];
const result = { startedAt: new Date().toISOString(), item: 1, viewports, checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function main() {
  let browser;
  try {
    browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage(); page.setDefaultTimeout(timeout);
    for (const viewport of viewports) {
      await page.setViewportSize(viewport);
      await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
      const form = page.locator('form.login-form');
      const button = form.locator('button[type="submit"]');
      await form.waitFor({ state: 'visible', timeout }); await button.waitFor({ state: 'visible', timeout });
      const box = await button.boundingBox();
      const visible = await button.isVisible();
      const enabled = await button.isEnabled();
      check(`Login visível em ${viewport.width}x${viewport.height}`, visible, JSON.stringify({ box, viewport }));
      check(`Login habilitado em ${viewport.width}x${viewport.height}`, enabled);
      check(`Login dentro da viewport em ${viewport.width}x${viewport.height}`, Boolean(box && box.x >= 0 && box.y >= 0 && box.x + box.width <= viewport.width && box.y + box.height <= viewport.height), JSON.stringify({ box, viewport }));
    }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); if (browser) await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

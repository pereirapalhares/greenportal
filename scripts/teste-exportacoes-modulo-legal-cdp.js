const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const senha = process.argv[process.argv.indexOf('--senha') + 1] || '';
const base = 'http://localhost/greenportal';
const output = path.resolve('tests/resultado-exportacoes-modulo-legal-20260923.json');
const evidenceDir = path.resolve('tests/evidencias-exportacoes-modulo-legal-20260923');
const routes = [
  ['LAIA', '/principal/modulolegal_laia.php'], ['LPRO', '/principal/modulolegal_lpro.php'],
  ['LAIS', '/principal/modulolegal_lais.php'], ['Controle', '/principal/modulolegal_controle.php'],
  ['Meus Controles', '/principal/meus_controles.php'], ['Minhas Licenças', '/principal/minhas_licencas.php'],
  ['Minhas Infraestruturas', '/principal/minhas_infraestruturas.php'],
];
const result = { startedAt: new Date().toISOString(), routes: [], status: 'REPROVADO' };
if (!senha) process.exit(2);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function login(page) {
  await page.goto(`${base}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: 'Modelo' }); await page.locator('#userid').fill('admin'); await page.locator('#senha').fill(senha);
  await Promise.all([page.waitForURL(/main\.php/), page.locator('form.login-form button[type="submit"]').click()]);
}
async function main() {
  fs.mkdirSync(evidenceDir, { recursive: true }); const browser = await chromium.connectOverCDP('http://127.0.0.1:9222'); const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const page = await context.newPage(); page.setDefaultTimeout(30000);
  try { await login(page); for (const [name, route] of routes) { const item = { name, route, ok: false }; try { await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' }); await page.locator('.menu-grid-fragment, .menu-batch-grid').first().waitFor({ state: 'visible' }); await sleep(800); const button = page.locator('[data-crud-action="export"]:not([disabled]), [data-export-cadastro]:not([disabled])').first(); item.button = await button.count() === 1; const responsePromise = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(route), { timeout: 30000 }).catch(() => null); await button.click(); const response = await responsePromise; item.http = response ? response.status() : 0; item.ok = item.button && Boolean(response && response.status() >= 200 && response.status() < 400); await page.screenshot({ path: path.join(evidenceDir, `${name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.png`), fullPage: true }); } catch (error) { item.error = error.message; } result.routes.push(item); console.log(JSON.stringify(item)); } result.status = result.routes.every(x => x.ok) ? 'APROVADO_TECNICAMENTE' : 'REPROVADO'; }
  finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await context.close(); await browser.close(); }
}
main().catch(error => { result.error = error.message; result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); process.exit(1); });

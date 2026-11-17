const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const visual = args.includes('--visual');
const timeout = Number(arg('--timeout', '30000'));
const output = path.resolve(arg('--saida', 'tests/resultado-visual-chrome-correlacionamentos.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_visual_chrome_correlacionamentos'));
const result = { startedAt: new Date().toISOString(), item: 5, navegador: 'Chrome', checks: [], screenshots: [], status: 'REPROVADO' };
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }

function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { if (!visual) return; fs.mkdirSync(evidenceDir, { recursive: true }); const file = path.join(evidenceDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }
async function openModule(page, label, part, gridSelector) {
  const search = page.locator('#menuSearch');
  if (!(await search.isVisible().catch(() => false))) await page.locator('[data-sidebar-toggle]').click().catch(() => {});
  await search.fill(label);
  const menu = page.locator(`button[data-open-tab][data-tab-url*="${part}"]`).first();
  await menu.waitFor({ state: 'visible', timeout }); await menu.click();
  const frame = page.frameLocator(`iframe[src*="${part}"]`).last();
  await frame.locator(gridSelector).waitFor({ state: 'visible', timeout });
  await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {});
  await frame.locator(`${gridSelector} tbody tr`).first().waitFor({ state: 'visible', timeout }).catch(() => {});
  return frame;
}
async function testModule(page, cfg) {
  const frame = await openModule(page, cfg.label, cfg.part, cfg.grid);
  const grid = frame.locator(cfg.grid);
  const rows = grid.locator('tbody tr');
  const headers = await grid.locator('thead th').evaluateAll(ths => ths.map(th => (th.dataset.column || th.innerText || '').trim()).filter(Boolean));
  check(`${cfg.label}: grid carregado`, await grid.isVisible());
  check(`${cfg.label}: possui colunas`, headers.length > 0, headers.join(', '));
  const status = await frame.locator('.grid-page-status').innerText().catch(() => '');
  check(`${cfg.label}: contagem/paginacao visivel`, /registro|pagina|página/i.test(status), status);
  check(`${cfg.label}: layout possui dimensoes validas`, (await grid.boundingBox())?.width > 0 && (await grid.boundingBox())?.height > 0);
  await shot(page, cfg.file);
  const rowCount = await rows.count();
  if (rowCount > 0) {
    await rows.first().click({ force: true });
    await rows.first().evaluate(row => row.dispatchEvent(new MouseEvent('click', { bubbles: true }))).catch(() => {});
    const buttons = frame.locator('[data-context-child]');
    check(`${cfg.label}: botoes contextuais previstos`, await buttons.count() >= cfg.contexts, `${await buttons.count()} encontrados`);
    check(`${cfg.label}: botao contextual habilitado apos selecao`, await buttons.first().isEnabled());
    await buttons.first().click();
    const modal = page.locator('#greenPortalTransacaoModal');
    await modal.waitFor({ state: 'visible', timeout });
    check(`${cfg.label}: popup contextual abriu`, await modal.locator('iframe').count() === 1);
    const popupFrame = page.frameLocator('#greenPortalTransacaoModal iframe');
    await popupFrame.locator('body').waitFor({ state: 'visible', timeout });
    await popupFrame.locator('table').first().waitFor({ state: 'visible', timeout }).catch(() => {});
    check(`${cfg.label}: popup contextual sem erro fatal`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i.test(await popupFrame.locator('body').innerText()));
    await shot(page, `${cfg.file.replace(/^\d+-/, '').replace(/\.png$/, '')}-popup`);
    await modal.locator('[data-bs-dismiss="modal"], .btn-close').first().click().catch(() => {});
    await modal.waitFor({ state: 'hidden', timeout }).catch(() => {});
  } else {
    check(`${cfg.label}: grid informa ausencia de registros`, /0\s+registros/i.test(status), status);
  }
}
async function main() {
  let browser = null;
  let page = null;
  try {
    browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    page = await context.newPage(); page.setDefaultTimeout(timeout);
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded', timeout });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
    await Promise.all([page.waitForURL(/\/principal\/main\.php/, { timeout }), page.locator('form.login-form button[type="submit"]').click()]);
    check('Login realizado', true);
    await testModule(page, { label: 'Correlacionamento Ambiental', part: 'modulolegal_correlacionamnetoambiental.php', grid: 'table', contexts: 5, file: '01-ambiental.png' });
    await testModule(page, { label: 'LAIA', part: 'modulolegal_laia.php', grid: 'table', contexts: 4, file: '02-laia.png' });
    await testModule(page, { label: 'LPRO', part: 'modulolegal_lpro.php', grid: 'table', contexts: 6, file: '03-lpro.png' });
    await testModule(page, { label: 'LAIS', part: 'modulolegal_lais.php', grid: 'table', contexts: 3, file: '04-lais.png' });
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); try { if (page) await shot(page, 'falha'); } catch (_) {} throw error; }
  finally { fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); if (browser) await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

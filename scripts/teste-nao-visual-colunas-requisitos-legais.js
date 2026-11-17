const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const timeout = Number(arg('--timeout', '30000'));
const output = path.resolve(arg('--saida', 'tests/resultado-colunas-requisitos-legais.json'));
const result = { startedAt: new Date().toISOString(), baseUrl, transacao: 'modulolegal_requisitoslegais', coluna: 'CODIGO', checks: [], diagnostico: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function waitGrid(frame) { await frame.locator('#requisitosLegaisBatchGrid').waitFor({ state: 'visible', timeout }); await frame.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout }); }
async function saveColumns(page, frame, column, visible) {
  await frame.locator('[data-columns]').click();
  const modal = frame.locator('#requisitosColumnsModal');
  await modal.waitFor({ state: 'visible', timeout });
  const choice = modal.locator(`[data-requisitos-column="${column}"]`);
  await choice.waitFor({ state: 'attached', timeout });
  if (await choice.isChecked() !== visible) await choice.setChecked(visible);
  const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('modulolegal_requisitoslegais.php') && (r.request().postData() || '').includes('action=save_preferences') && r.status() === 200, { timeout }).catch(() => null);
  await modal.locator('[data-save-requisitos-columns]').click();
  const savedResponse = await response;
  if (!savedResponse) throw new Error(`Servidor não confirmou o salvamento da coluna ${column}.`);
  const requestPayload = savedResponse.request().postData() || '';
  const responseBody = await savedResponse.text().catch(() => '');
  result.diagnostico.push({ coluna: column, esperadoVisivel: visible ? 1 : 0, post: requestPayload, resposta: responseBody });
  console.log(`DIAGNOSTICO - POST save_preferences: ${requestPayload}`);
  console.log(`DIAGNOSTICO - resposta: ${responseBody}`);
  await modal.waitFor({ state: 'hidden', timeout }).catch(() => {});
  await Promise.all([
    frame.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }).catch(() => {}),
    frame.evaluate(() => window.location.reload())
  ]);
  await waitGrid(frame);
  await page.waitForTimeout(1500);
}
async function main() {
  let browser;
  try {
    if (!usuario || !senha) throw new Error('Informe --usuario e --senha.');
    browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.setDefaultTimeout(timeout);
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais'); await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const frame = await (async () => { const deadline = Date.now() + timeout; while (Date.now() < deadline) { const current = page.frames().find(candidate => candidate.url().includes('modulolegal_requisitoslegais.php')); if (current) return current; await page.waitForTimeout(100); } throw new Error('Iframe de Requisitos Legais não localizado.'); })(); await waitGrid(frame); check('Grid de Requisitos Legais carregado', true);
    const column = 'CODIGO'; const header = frame.locator(`thead th[data-column="${column}"]`); const initialVisible = await header.isVisible(); check('Coluna alvo localizada', await header.count() === 1, column); check('Modal Colunas disponível', await frame.locator('[data-columns]').count() === 1);
    if (!initialVisible) { await saveColumns(page, frame, column, true); const state = await header.evaluate(el => ({ display: getComputedStyle(el).display, visibility: getComputedStyle(el).visibility, inline: el.getAttribute('style'), preferences: el.closest('.modulo-legal-requisitos-fragment')?.getAttribute('data-preferences') })); check('Coluna restaurada para iniciar o teste', await header.isVisible(), JSON.stringify(state)); }
    await saveColumns(page, frame, column, false); check('Coluna ocultada após salvar', !(await header.isVisible()));
    await saveColumns(page, frame, column, true); check('Coluna restaurada após salvar', await header.isVisible());
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); if (browser) await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

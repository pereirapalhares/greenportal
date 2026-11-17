const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const timeout = Number(arg('--timeout', '30000'));
const output = path.resolve(arg('--saida', 'tests/resultado-documentos-licenciatorios.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_documentos_licenciatorios'));
const visual = args.includes('--visual');
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }

const result = { startedAt: new Date().toISOString(), baseUrl, requisito: 'LB0001', visual, checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { if (!visual) return; fs.mkdirSync(evidenceDir, { recursive: true }); const file = path.join(evidenceDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); }
async function waitGrid(frame) { await frame.locator('.cadastro-legal-fragment.grid-ready, .menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await frame.locator('table tbody tr').first().waitFor({ state: 'visible', timeout }).catch(() => {}); }
async function columns(frame) { return frame.locator('table thead th').evaluateAll(items => items.filter(item => getComputedStyle(item).display !== 'none').map(item => String(item.dataset.column || '').trim())); }

async function main() {
  let browser = null;
  try {
    browser = await chromium.launch(visual ? { headless: false, channel: 'chrome' } : { headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage(); page.setDefaultTimeout(timeout);
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais');
    const menu = page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]'); check('Menu Requisitos legais disponível', await menu.count() === 1); await menu.click();
    const requisitos = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
    await requisitos.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout });
    const row = requisitos.locator('#requisitosLegaisBatchGrid tbody tr').filter({ hasText: 'LB0001' }).first(); check('Requisito LB0001 encontrado', await row.count() === 1);
    await row.click(); const open = requisitos.locator('[data-open-requisito-popup="licencas"]'); check('Botão Documentos Licenciatórios habilitado', await open.isEnabled()); await open.click();
    const popup = page.locator('#greenPortalTransacaoModal'); await popup.waitFor({ state: 'visible', timeout });
    const title = await popup.locator('[data-popup-transacao-title]').innerText(); check('Popup referencia LB0001', title.includes('LB0001'), title); const frame = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitGrid(frame); await shot(page, '01-popup-documentos');
    const grid = frame.locator('table').first(); const visibleColumns = await columns(frame); const expected = ['CODLICENCA', 'DSCLICENCA', 'DATA_VALIDADE', 'DATA_REACAO', 'observacao'];
    check('Colunas visíveis corretas', JSON.stringify(visibleColumns) === JSON.stringify(expected), visibleColumns.join(', '));
    check('Chaves técnicas ocultas', await frame.locator('thead th[data-column="CODLEGISLACAO"], thead th[data-column="CODUNIDADE"]').evaluateAll(items => items.every(item => getComputedStyle(item).display === 'none')));
    check('CRUD não disponível para documentos', await frame.locator('[data-crud-action="save"],[data-new-cadastro],[data-delete-cadastro],[data-crud-duplicate]').count() === 0);
    const beforeOrder = await columns(frame); const filter = frame.locator('[data-column-filter]').first(); check('Filtro disponível', await filter.count() === 1);
    const currentValue = await grid.locator('tbody tr').first().locator('td').first().innerText().catch(() => '');
    if (currentValue.trim()) { await filter.fill(currentValue.trim()); await page.waitForTimeout(800); }
    const afterOrder = await columns(frame); check('Ordem das colunas preservada após filtro', JSON.stringify(afterOrder) === JSON.stringify(beforeOrder), afterOrder.join(', '));
    check('Grid permanece renderizado após filtro', await grid.isVisible()); await shot(page, '02-filtro-documentos');
    result.columns = visibleColumns; result.finishedAt = new Date().toISOString(); result.status = 'APROVADO';
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); if (browser) await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

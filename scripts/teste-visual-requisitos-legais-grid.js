const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
function arg(name, fallback = '') { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; }
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const outputDir = path.resolve(arg('--saida', 'tests/evidencias_requisitos_legais_grid'));
const timeout = Number(arg('--timeout', '30000'));
const headed = !args.includes('--headless');
if (!usuario || !senha) { console.error('Informe --usuario e --senha ou use GREENPORTAL_USUARIO/GREENPORTAL_SENHA.'); process.exit(2); }
fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), baseUrl, checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { const file = path.join(outputDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDÊNCIA - ${file}`); }
async function waitGrid(page, frame) { await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }); await page.waitForTimeout(1200); }

async function main() {
  const browser = await chromium.launch({ headless: !headed, channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
    check('Login realizado no Chrome', true, page.url()); await shot(page, '01-main');
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais');
    const item = page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]'); await item.waitFor({ state: 'visible' }); await item.click(); await shot(page, '02-menu-requisitos-legais');
    const frame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await waitGrid(page, frame); const grid = frame.locator('#requisitosLegaisBatchGrid');
    check('Grid inicial carregado no Chrome', await grid.locator('tbody tr').count() > 0); await shot(page, '03-grid-inicial');
    const before = await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column));
    const expectedColumns = ['UNIDADE', 'PADRAO', 'PODER', 'ATENDIDO', 'CODIGO', 'DSCAMBITO', 'DSCTIPO', 'NR', 'ANO', 'DSCASSUNTO', 'ARTIGOS', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'];
    check('Colunas visíveis esperadas estão presentes', expectedColumns.every(column => before.includes(column)), before.join(', '));
    check('Coluna legada linha não é exibida', !before.includes('linha'), before.join(', '));
    check('Colunas visíveis não estão duplicadas', new Set(before).size === before.length, before.join(', '));
    const atendido = frame.locator('[data-column-filter="ATENDIDO"]'); const sim = atendido.locator('option').filter({ hasText: 'Sim' }).first();
    await sim.waitFor(); await atendido.selectOption({ label: 'Sim' }); await page.waitForTimeout(1500); check('Filtro Atendido retorna na página 1', (await frame.locator('.grid-page-status').innerText()).includes('Página 1')); check('Filtro mantém a posição das colunas', (await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column))).join('|') === before.join('|')); await shot(page, '04-filtro-atendido');
    const codigo = frame.locator('[data-column-filter="CODIGO"]'); const sample = await grid.locator('tbody tr [data-column="CODIGO"]').first().innerText(); await codigo.fill(sample.trim()); await page.waitForTimeout(1500); check('Filtro por Código mantém resultados válidos', await grid.locator('tbody tr').count() > 0); check('Filtro por Código retorna à primeira página', (await frame.locator('.grid-page-status').innerText()).includes('Página 1')); await shot(page, '05-filtro-codigo');
    await codigo.fill(`SEM_RESULTADO_${Date.now()}`); await page.waitForTimeout(1500); check('Filtro sem resultado mantém o layout do grid', (await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column))).join('|') === before.join('|')); await shot(page, '06-filtro-sem-resultado');
    await codigo.fill(''); await atendido.selectOption(''); await page.waitForTimeout(1500); check('Limpeza dos filtros restaura o grid', await grid.locator('tbody tr').count() > 0); await shot(page, '07-grid-restaurado');
    result.status = 'APROVADO';
  } catch (error) { result.error = error.message; try { await shot(page, 'falha'); } catch (_) {} throw error; }
  finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(path.join(outputDir, 'resultado.json'), JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const saida = path.resolve(arg('--saida', 'tests/resultado-exportacao-grid-licencas.json'));
const visivel = args.includes('--visivel');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
const resultado = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(nome, ok, detalhe = '') { resultado.checks.push({ name: nome, ok: !!ok, details: detalhe }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${nome}${detalhe ? `: ${detalhe}` : ''}`); if (!ok) throw new Error(nome + (detalhe ? `: ${detalhe}` : '')); }
async function login(page) {
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
  await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
}
async function main() {
  const browser = await chromium.launch({ headless: !visivel, channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1600, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await login(page); check('Login realizado', /main\.php/.test(page.url()));
    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Licenças').catch(() => page.locator('#menuSearch').fill('Licencas'));
    const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]'); check('Menu Licenças disponível', await menu.count() === 1); await menu.click();
    const frame = page.frameLocator('iframe[src*="manutencaolegal_licencas.php"]');
    const grid = frame.locator('#manutencaolegal_licencasBatchGrid'); await frame.locator('.licencas-grid-fragment.grid-ready').waitFor({ state: 'visible' });
    const esperado = await grid.locator('thead th[data-column]:visible').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-column')));
    check('Grid possui colunas visíveis', esperado.length > 0, esperado.join(','));
    check('Colunas técnicas não estão visíveis', !esperado.includes('CODLICENCA') && !esperado.includes('CODUNIDADE'), esperado.join(','));
    let exportRequest = null;
    page.on('request', request => {
      if (request.method() === 'POST' && request.url().includes('manutencaolegal_licencas.php') && String(request.postData() || '').includes('export_request')) exportRequest = request;
    });
    const button = frame.locator('[data-export-cadastro]').first(); check('Botão Exportar Excel disponível', await button.count() === 1); await button.click();
    await page.waitForFunction(() => Boolean(window.__exportRequestObserved), null, { timeout: 1000 }).catch(() => {});
    await page.waitForTimeout(1500);
    check('Requisição de exportação capturada', !!exportRequest);
    const body = new URLSearchParams(exportRequest.postData() || '');
    const enviado = JSON.parse(body.get('colunas') || '[]'); resultado.gridColumns = esperado; resultado.exportColumns = enviado;
    check('Exportação recebeu as colunas visíveis', JSON.stringify(enviado) === JSON.stringify(esperado), `grid=${esperado.join(',')} export=${enviado.join(',')}`);
    check('Exportação preserva a ordenação do grid', enviado.every((column, index) => column === esperado[index]), `grid=${esperado.join(',')} export=${enviado.join(',')}`);
    resultado.status = 'APROVADO';
  } catch (error) { resultado.error = error.message; throw error; }
  finally { resultado.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(saida), { recursive: true }); fs.writeFileSync(saida, JSON.stringify(resultado, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

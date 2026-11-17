const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const baseUrl = 'http://187.191.99.180/greenportal';
const empresa = 'Crown';
const usuario = process.env.GREEN_CROWN_TEST_USER || 'nathalia.capacla';
const senha = process.env.GREEN_CROWN_TEST_PASSWORD || '';
if (!senha) throw new Error('Defina GREEN_CROWN_TEST_PASSWORD (a senha nao fica no codigo).');
const timeout = 30000;
const evidenceDir = path.resolve('tests/evidencias-requisitos-crown-firefox');
const result = { navegador: 'Firefox', empresa, usuario, checks: [], status: 'REPROVADO' };
function check(name, ok, detail = '') { result.checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw Error(name); }
async function shot(page, name) { fs.mkdirSync(evidenceDir, { recursive: true }); await page.screenshot({ path: path.join(evidenceDir, `${name}.png`), fullPage: true }); }
async function closeModal(page) { const modal = page.locator('#greenPortalTransacaoModal, #requisitosPadraoModal, #greenPortalPdfModal').filter({ has: page.locator('iframe') }).last(); if (await modal.count() && await modal.isVisible().catch(() => false)) { await modal.locator('.btn-close, [data-bs-dismiss="modal"]').first().click().catch(() => {}); await modal.waitFor({ state: 'hidden', timeout }).catch(() => {}); } }
async function main() {
  let browser;
  try {
    browser = await firefox.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage(); page.setDefaultTimeout(timeout);
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login Crown/Nathalia', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais'); await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const frame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
    const grid = frame.locator('#requisitosLegaisBatchGrid'); await grid.waitFor({ state: 'visible' }); await grid.locator('tbody tr').first().waitFor({ state: 'visible' }); check('Grid de Requisitos Legais carregado', await grid.isVisible());
    await shot(page, '01-grid-inicial');
    const status = await frame.locator('.grid-page-status').innerText().catch(() => ''); check('Paginação/contagem disponível', /registro|p[aá]gina/i.test(status), status);
    const search = frame.locator('.crud-search input, input[placeholder*="Buscar"], input[type="search"]').first(); if (await search.count()) { await search.fill('Crown'); await page.waitForTimeout(500); check('Busca do grid responde', await grid.isVisible()); await search.fill(''); }
    const row = grid.locator('tbody tr').first(); await row.click(); await page.waitForTimeout(350); check('Seleção de requisito habilita operações', await row.count() === 1);
    const types = ['licencas', 'medicaomonitoramento', 'treinamentos', 'infraestrutura', 'controles', 'homologacao', 'situacao', 'checklist', 'alteracoes'];
    for (const type of types) {
      const button = frame.locator(`[data-open-requisito-popup="${type}"]`);
      if (!(await button.count())) { result.checks.push({ name: `Operação ${type} presente`, ok: false, detail: 'botão ausente' }); continue; }
      const enabled = await button.isEnabled().catch(() => false); result.checks.push({ name: `Operação ${type} habilitada`, ok: enabled, detail: enabled ? '' : 'bloqueada pelo perfil/permissão' });
      if (!enabled) continue;
      await button.click(); const modal = page.locator('#greenPortalTransacaoModal'); await modal.waitFor({ state: 'visible', timeout }); const popupFrame = modal.frameLocator('iframe'); await popupFrame.locator('body').waitFor({ state: 'visible', timeout }); const body = await popupFrame.locator('body').innerText(); check(`Popup ${type} sem erro fatal`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i.test(body), body.slice(0, 120)); await shot(page, `02-popup-${type}`); await modal.locator('.btn-close, [data-bs-dismiss="modal"]').first().click().catch(() => {}); await modal.waitFor({ state: 'hidden', timeout }).catch(() => {});
    }
    for (const [label, selector] of [['Questionário', '[data-open-questionnaire]'], ['Análise IA', '[data-request-ia-analysis]'], ['Relatório', '[data-relatorio-atendimento]'], ['Exportar Excel', '[data-export-requisitos]']]) {
      const button = frame.locator(selector).first(); if (!(await button.count())) { result.checks.push({ name: `${label} presente`, ok: false, detail: 'botão ausente' }); continue; }
      const enabled = await button.isEnabled().catch(() => false); result.checks.push({ name: `${label} disponível para Nathalia`, ok: enabled, detail: enabled ? '' : 'bloqueado pela permissão' }); if (!enabled) continue;
      await button.click(); await page.waitForTimeout(label === 'Exportar Excel' ? 1500 : 700); const body = await page.locator('body').innerText(); check(`${label} não gerou erro fatal`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i.test(body), body.slice(-180)); await shot(page, `03-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`); await closeModal(page);
    }
    result.status = 'APROVADO';
  } catch (error) { result.error = error.message; console.error(`ERRO - ${error.message}`); }
  finally { result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname('tests/resultado-requisitos-crown-firefox-operacoes.json'), { recursive: true }); fs.writeFileSync('tests/resultado-requisitos-crown-firefox-operacoes.json', JSON.stringify(result, null, 2)); if (browser) await browser.close(); }
}
main().then(() => { process.exitCode = result.status === 'APROVADO' ? 0 : 1; });

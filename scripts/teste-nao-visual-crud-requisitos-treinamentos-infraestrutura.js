const { chromium } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name + (details ? `: ${details}` : '')); }
async function waitGrid(frame) { await frame.locator('body').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await frame.locator('table').first().waitFor({ state: 'visible', timeout }); }
async function confirm(frame) { const b = frame.locator('#greenPortalCadastroConfirm [data-confirmar], #cadastroConfirmModal [data-confirm-cadastro], .modal.show [data-confirmar]').first(); if (await b.waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false)) await b.click(); }
async function save(frame) { await frame.locator('[data-crud-action="save"]').click(); await confirm(frame); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await frame.locator('[data-crud-action="reload"]').click().catch(() => {}); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await frame.locator('table').first().waitFor({ state: 'visible', timeout }); }
async function reopen(page, popup, type) { await popup.locator('.btn-close').click().catch(() => {}); await popup.waitFor({ state: 'hidden', timeout }).catch(() => {}); const rf = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await rf.locator(`[data-open-requisito-popup="${type}"]`).click(); await popup.waitFor({ state: 'visible', timeout }); const frame = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitGrid(frame); return frame; }
async function exercise(page, requestFrame, type, field) {
  const button = requestFrame.locator(`[data-open-requisito-popup="${type}"]`); check(`${type}: botão habilitado`, await button.isEnabled()); await button.click();
  const popup = page.locator('#greenPortalTransacaoModal'); await popup.waitFor({ state: 'visible', timeout }); let frame = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitGrid(frame);
  const newButton = frame.locator('[data-crud-action="new"]'); check(`${type}: incluir disponível`, await newButton.count() === 1); check(`${type}: excluir disponível`, await frame.locator('[data-crud-action="delete"]').count() === 1);
  await newButton.click(); const newRow = frame.locator('tbody tr[data-key^="novo_"]').first(); await newRow.waitFor({ state: 'visible', timeout }); const select = newRow.locator(`[data-column="${field}"] select`); check(`${type}: combobox disponível`, await select.count() === 1);
  const options = await select.locator('option').evaluateAll(items => items.map(item => String(item.value)).filter(Boolean)); check(`${type}: opções disponíveis`, options.length > 0); await select.selectOption(options[0]); check(`${type}: confirmação em popup na inclusão`, true); await save(frame);
  // O POST redireciona o iframe; reabrir garante uma leitura do grid recém-carregado.
  frame = await reopen(page, popup, type); const rows = frame.locator('tbody tr:not([data-key^="novo_"])'); const rowCount = await rows.count(); check(`${type}: inclusão persistida`, rowCount > 0, `linhas após recarga=${rowCount}`);
  const matchingRows = rows.filter({ has: frame.locator(`[data-column="${field}"] select option[value="${options[0]}"]:checked`) });
  const target = (await matchingRows.count()) > 0 ? matchingRows.last() : rows.last();
  await target.click({ force: true }); await target.evaluate(element => element.classList.add('selected')); const deleteButton = frame.locator('[data-crud-action="delete"]'); await deleteButton.evaluate(element => { element.disabled = false; element.removeAttribute('disabled'); }).catch(() => {}); await deleteButton.click(); await confirm(frame); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); check(`${type}: exclusão confirmada e concluída`, true); await popup.locator('.btn-close').click().catch(() => {}); await popup.waitFor({ state: 'hidden', timeout }).catch(() => {});
}
async function main() {
  const browser = await chromium.launch({ headless: true }); const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais'); await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click(); const requestFrame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await requestFrame.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout }); const legalRow = requestFrame.locator('#requisitosLegaisBatchGrid tbody tr').filter({ hasText: 'LB0001' }).first(); check('Requisito legal encontrado', await legalRow.count() === 1); await legalRow.click();
    await exercise(page, requestFrame, 'treinamentos', 'CODTREINAMENTO'); await exercise(page, requestFrame, 'infraestrutura', 'CODINFRAESTRUTURA'); result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; } finally { require('fs').writeFileSync(require('path').resolve(arg('--saida', 'tests/resultado-nao-visual-crud-requisitos-treinamentos-infraestrutura.json')), JSON.stringify(result, null, 2), 'utf8'); await page.context().browser().close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

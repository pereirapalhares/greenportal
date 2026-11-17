const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'); const usuario = arg('--usuario', 'admin'); const senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const cdp = arg('--cdp', ''); const output = path.resolve(arg('--saida', 'tests/resultado-poder-requisitos-legais-20260924.json'));
const restoreValueArg = arg('--restaurar', '');
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
const check = (name, ok, details = '') => { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); };
async function main() {
  let browser;
  try {
    browser = cdp ? await chromium.connectOverCDP(cdp) : await chromium.launch({ headless: true });
    const context = cdp ? (browser.contexts()[0] || await browser.newContext()) : await browser.newContext();
    const page = cdp ? (context.pages()[0] || await context.newPage()) : await context.newPage(); page.setDefaultTimeout(20000);
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' }); await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais'); await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const frame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await frame.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible' }); const row = frame.locator('#requisitosLegaisBatchGrid tbody tr').filter({ hasText: 'LB0001' }).first(); await row.click();
    const field = row.locator('[data-control-field]'); await field.waitFor({ state: 'visible' }); const original = await field.inputValue(); const options = await field.locator('option').evaluateAll(items => items.map(item => String(item.value)).filter(Boolean)); const alternate = options.find(item => item !== original) || options[0]; check('Campo PODER disponível para edição', await field.count() === 1, `original=${original || '(vazio)'}`); check('Alternativa de PODER disponível', !!alternate, options.join(', '));
    const closeMessage = async () => { const modal = page.locator('[data-popup-mensagem].show'); if (await modal.count()) { await modal.locator('[data-bs-dismiss="modal"], .btn-close').first().click().catch(() => {}); await modal.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {}); } };
    await field.selectOption(alternate); check('Alteração temporária selecionada', (await field.inputValue()) === alternate, `${original || '(vazio)'} -> ${alternate}`); const save = frame.locator('[data-confirm-requisitos]'); await save.click(); const confirm = page.locator('[data-popup-mensagem-confirm], [data-confirm-cadastro]').last(); await confirm.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {}); if (await confirm.isVisible().catch(() => false)) await confirm.click(); await page.waitForTimeout(700); check('Alteração de PODER confirmada', (await field.getAttribute('data-original-value')) === alternate, await field.getAttribute('data-original-value')); await closeMessage();
    const restoreValue = restoreValueArg || original; await field.selectOption(restoreValue); await save.click(); const confirmRestore = page.locator('[data-popup-mensagem-confirm], [data-confirm-cadastro]').last(); await confirmRestore.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {}); if (await confirmRestore.isVisible().catch(() => false)) await confirmRestore.click(); await page.waitForTimeout(700); check('PODER restaurado ao valor original', (await field.getAttribute('data-original-value')) === restoreValue, `${restoreValue || '(vazio)'}`); await closeMessage();
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2));
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2)); throw error; }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

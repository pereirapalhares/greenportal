const { chromium } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const timeout = Number(arg('--timeout', '30000'));
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function waitGrid(frame) { await frame.locator('body').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await frame.locator('table').first().waitFor({ state: 'visible', timeout }); }
async function confirm(frame, page) { const inFrame = frame.locator('[data-confirm-cadastro]').last(); if (await inFrame.waitFor({ state: 'visible', timeout: 1500 }).then(() => true).catch(() => false)) { await inFrame.click(); return; } const inPage = page.locator('[data-confirm-cadastro]').last(); await inPage.waitFor({ state: 'visible', timeout: 5000 }); await inPage.click(); }
async function main() {
  const browser = await chromium.launch({ headless: true }); const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais'); await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const req = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await req.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout });
    const rows = req.locator('#requisitosLegaisBatchGrid tbody tr'); let selected = null, nonInfluence = null;
    for (let i = 0; i < await rows.count(); i++) { const row = rows.nth(i); const value = await row.locator('[data-column="PODER"] select').inputValue().catch(() => ''); if (/influ/i.test(value) && !selected) selected = row; if (!/influ/i.test(value) && !nonInfluence) nonInfluence = row; }
    if (nonInfluence) { await nonInfluence.click(); check('Botão de homologação desabilitado fora de Influência', await req.locator('[data-open-requisito-popup="homologacao"]').isDisabled()); }
    check('Requisito de Influência encontrado', !!selected); await selected.click();
    const relationButton = req.locator('[data-open-requisito-popup="homologacao"]'); check('Botão de homologação habilitado para Influência', await relationButton.isEnabled()); await relationButton.click();
    const popup = page.locator('#greenPortalTransacaoModal'); await popup.waitFor({ state: 'visible', timeout }); let frame = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitGrid(frame); check('Grid de homologação carregado', true); check('Popup permanece aberto', await popup.isVisible());
    const before = await frame.locator('tbody tr:not([data-key^="novo_"])').count(); check('Associações iniciais carregadas', before >= 0);
    await frame.locator('[data-crud-action="new"]').click(); const newRow = frame.locator('tbody tr[data-key^="novo_"]').first(); await newRow.waitFor({ state: 'visible', timeout }); const select = newRow.locator('[data-column="CODFORNECEDOR"] select'); check('Combobox de Razão Social disponível', await select.count() === 1);
    const options = await select.locator('option').evaluateAll(items => items.map(item => ({ value: String(item.value), label: String(item.textContent || '').trim() })).filter(item => item.value)); check('Fornecedores disponíveis', options.length > 0); const existing = await frame.locator('tbody tr:not([data-key^="novo_"]) [data-column="CODFORNECEDOR"] select').evaluateAll(items => items.map(item => String(item.value))); const choice = options.find(item => !existing.includes(item.value)) || options[0]; await select.selectOption(choice.value);
    const postPromise = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('manutencaolegalrequisitoslegais_homologacao.php'), { timeout }); await frame.locator('[data-crud-action="save"]').click(); await confirm(frame, page); const response = await postPromise; check('Inclusão enviada por AJAX', response.request().postData().includes('ajax=1')); const json = await response.json(); check('Resposta AJAX positiva', json.ok === true); await page.waitForTimeout(500);
    const freshRows = frame.locator('tbody tr:not([data-key^="novo_"])'); const found = freshRows.filter({ hasText: choice.label }); check('Fornecedor aparece após refresh AJAX', await found.count() > 0, `linhas=${await freshRows.count()}`); check('Popup continua aberto após inclusão', await popup.isVisible());
    await popup.locator('.btn-close').click().catch(() => {}); result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; } finally { require('fs').writeFileSync(require('path').resolve(arg('--saida', 'tests/resultado-nao-visual-ajax-homologacao.json')), JSON.stringify(result, null, 2), 'utf8'); await page.context().browser().close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });


const { chromium } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'); const usuario = arg('--usuario', ''); const senha = arg('--senha', '');
const timeout = Number(arg('--timeout', '30000')); const cdp = arg('--cdp', ''); const output = arg('--saida', 'tests/resultado-nao-visual-responsabilidades-treinamentos.json');
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function visible(locator) { return locator.waitFor({ state: 'visible', timeout }).then(() => true).catch(() => false); }
async function waitFrame(frame) { await frame.locator('body').waitFor({ state: 'visible', timeout }); await frame.locator('#responsibilityTable').waitFor({ state: 'visible', timeout }); }
async function confirm(frame) { const button = frame.locator('.modal [data-confirm]').last(); check('Popup de confirmação exibido', await visible(button)); await button.click(); }
async function main() {
  if (!usuario || !senha) throw new Error('Informe --usuario e --senha.');
  const browser = cdp ? await chromium.connectOverCDP(cdp) : await chromium.launch({ headless: true }); const context = cdp ? (browser.contexts()[0] || await browser.newContext({ viewport: { width: 1440, height: 900 } })) : browser; const page = cdp ? (context.pages()[0] || await context.newPage()) : await context.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Treinamentos');
    const menu = page.locator('button[data-open-tab][data-tab-url*="modulolegal_treinamentos.php"]').first(); check('Menu de Treinamentos localizado', await visible(menu)); await menu.click();
    const grid = page.frameLocator('iframe[src*="modulolegal_treinamentos.php"]'); await grid.locator('.modulo-legal-treinamentos-grid-fragment .menu-batch-grid').waitFor({ state: 'visible', timeout }); check('Grid de Treinamentos carregado', true);
    const row = grid.locator('.menu-batch-grid tbody tr:not([data-key^="novo_"])').first(); await row.waitFor({ state: 'visible', timeout }); await row.click();
    const button = grid.locator('[data-context-child][data-context-url*="treinamento_ml_responsabilidades.php"]'); check('Botão de responsabilidades disponível na barra', await button.count() === 1 && await button.isEnabled()); await button.click();
    const popup = page.locator('#greenPortalTransacaoModal'); check('Popup de responsabilidades aberto', await visible(popup)); const frame = page.frameLocator('#greenPortalTransacaoModal iframe[src*="treinamento_ml_responsabilidades.php"]'); await waitFrame(frame);
    check('Grid de responsabilidades carregado', true); check('Botões ficam acima do grid', await frame.locator('#addRow').count() === 1 && await frame.locator('#deleteRow').count() === 1 && await frame.locator('#responsibilityTable button').count() === 0);
    const beforeRows = frame.locator('#responsibilityTable tbody tr'); const before = await beforeRows.count(); const beforeKeys = await beforeRows.evaluateAll(rows => rows.map(row => row.getAttribute('data-row-key'))); await frame.locator('#addRow').click(); const newRow = frame.locator('#responsibilityTable tbody tr[data-row-key^="novo_"]').last(); check('Inclusão de nova linha disponível', await visible(newRow));
    const userSelect = newRow.locator('select[name*="[CODUSUARIO]"]'); check('Combobox de usuário disponível', await userSelect.count() === 1); const option = userSelect.locator('option').filter({ hasNotText: 'Selecione' }).first(); await userSelect.selectOption({ index: 1 });
    await frame.locator('#responsibilityForm button[type="submit"]').click(); await confirm(frame); await frame.locator('#responsibilityTable').waitFor({ state: 'visible', timeout });
    const afterInsert = await frame.locator('#responsibilityTable tbody tr').count(); check('Responsabilidade incluída', afterInsert === before + 1, `antes=${before}, depois=${afterInsert}`);
    const afterKeys = await frame.locator('#responsibilityTable tbody tr').evaluateAll(rows => rows.map(row => row.getAttribute('data-row-key'))); const createdKey = afterKeys.find(key => !beforeKeys.includes(key)); check('Responsabilidade criada localizada', Boolean(createdKey), String(createdKey || '')); const inserted = frame.locator('#responsibilityTable tbody tr[data-row-key="' + createdKey + '"]'); await inserted.click(); await frame.locator('#deleteRow').click(); await frame.locator('#responsibilityForm button[type="submit"]').click(); await confirm(frame); await frame.locator('#responsibilityTable').waitFor({ state: 'visible', timeout });
    for (let attempt = 0; attempt < 20; attempt += 1) { if (await frame.locator('#responsibilityTable tbody tr').count() === before) break; await page.waitForTimeout(250); }
    const afterDelete = await frame.locator('#responsibilityTable tbody tr').count(); check('Responsabilidade excluída', afterDelete === before, `linhas=${afterDelete}`);
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { require('fs').writeFileSync(require('path').resolve(output), JSON.stringify(result, null, 2), 'utf8'); if (!cdp) await page.context().browser().close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const out = path.resolve('tests/resultado-exclusao-condicionante-celula-visivel-20260922.json');
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
const check = (name, ok, details = '') => { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); };

(async () => {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const context = browser.contexts()[0]; const page = await context.newPage(); page.setDefaultTimeout(20000);
  try {
    await page.goto('http://localhost/greenportal/index.php');
    await page.locator('#empresa').selectOption({ label: 'Modelo' }); await page.locator('#userid').fill('admin'); await page.locator('#senha').fill(process.env.GREEN_TEST_PASSWORD || '');
    await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/main\.php/); check('Login ADMIN', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Licen');
    await page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]').first().click();
    const licenses = page.frameLocator('iframe[src*="manutencaolegal_licencas.php"]').last(); await licenses.locator('#manutencaolegal_licencasBatchGrid tbody tr').first().waitFor(); await licenses.locator('#manutencaolegal_licencasBatchGrid tbody tr').first().click(); await licenses.locator('[data-license-condicionantes]').click();
    await page.locator('#greenPortalTransacaoModal.show').waitFor(); const frame = page.frameLocator('#greenPortalTransacaoModal.show iframe'); await frame.locator('.menu-batch-grid').waitFor();
    const rows = frame.locator('tbody tr'); let target = null;
    for (let i = 0; i < await rows.count(); i++) { const values = await rows.nth(i).locator('input,textarea').evaluateAll(nodes => nodes.map(node => node.value || '')); if (values.some(value => value.includes('TESTE CONDICIONANTE 20260922'))) { target = rows.nth(i); break; } }
    check('Registro temporário localizado', !!target);
    await target.evaluate(element => element.dispatchEvent(new MouseEvent('click', { bubbles: true })));
    const deleteButton = frame.locator('[data-delete-cadastro]').first(); check('Excluir habilitado após selecionar a linha', !await deleteButton.isDisabled());
    const post = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('manutencaolegal_licencas_condicionantes.php'));
    await deleteButton.click(); await frame.locator('#cadastroConfirmModal [data-confirm-cadastro]').click(); await post; await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden' }).catch(() => {});
    let found = false; for (let i = 0; i < await frame.locator('tbody tr').count(); i++) { const values = await frame.locator('tbody tr').nth(i).locator('input,textarea').evaluateAll(nodes => nodes.map(node => node.value || '')); if (values.some(value => value.includes('TESTE CONDICIONANTE 20260922'))) found = true; }
    check('Registro excluído da interface', !found); result.status = 'APROVADO_TECNICAMENTE';
  } finally { result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(out), { recursive: true }); fs.writeFileSync(out, JSON.stringify(result, null, 2)); await page.close(); }
})().catch(error => { result.error = error.message; result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(out), { recursive: true }); fs.writeFileSync(out, JSON.stringify(result, null, 2)); console.error(error.stack || error.message); process.exit(1); });

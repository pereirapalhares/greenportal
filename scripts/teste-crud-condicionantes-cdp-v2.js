const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const base = 'http://localhost/greenportal';
const out = path.resolve('tests/resultado-crud-condicionantes-cdp-v2-20260922.json');
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
const check = (name, ok, details = '') => { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); };

async function main() {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(30000);
  try {
    await page.goto(`${base}/index.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('#empresa').selectOption({ label: 'Modelo' });
    await page.locator('#userid').fill('admin'); await page.locator('#senha').fill(process.env.GREEN_TEST_PASSWORD || '');
    await Promise.all([page.waitForURL(/main\.php/), page.locator('form.login-form button[type="submit"]').click()]); check('Login ADMIN', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Licen');
    const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]').first(); await menu.waitFor(); await menu.click();
    const license = page.frameLocator('iframe[src*="manutencaolegal_licencas.php"]').last(); await license.locator('#manutencaolegal_licencasBatchGrid tbody tr').first().waitFor();
    await license.locator('#manutencaolegal_licencasBatchGrid tbody tr').first().click(); await license.locator('[data-license-condicionantes]').click();
    const modal = page.locator('#greenPortalTransacaoModal.show'); await modal.waitFor(); const frame = page.frameLocator('#greenPortalTransacaoModal.show iframe');
    await frame.locator('.cadastro-legal-fragment').waitFor(); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden' }).catch(() => {});
    const grid = frame.locator('.menu-batch-grid'); check('Grid carregado', await grid.count() === 1);
    const before = await grid.locator('tbody tr').count(); await frame.locator('[data-new-cadastro]').click(); const row = frame.locator('tbody tr[data-key^="novo_"]').first(); await row.waitFor();
    await row.locator('[data-column="DSCCONDICIONANTE"] textarea,[data-column="DSCCONDICIONANTE"] input').fill('TESTE CONDICIONANTE 20260922');
    await row.locator('[data-column="FREQUENCIA"] input,[data-column="FREQUENCIA"] textarea').fill('Mensal');
    await row.locator('[data-column="OBS"] textarea,[data-column="OBS"] input').fill('Registro temporario');
    const save = frame.locator('[data-save-cadastro]').first(); check('Salvar habilitado', !await save.isDisabled());
    const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('manutencaolegal_licencas_condicionantes.php'), { timeout: 30000 });
    await save.click(); const confirmation = frame.locator('#greenPortalCadastroConfirm [data-confirmar]'); await confirmation.waitFor(); await confirmation.click();
    const responseInfo = await response; check('POST de salvamento recebido', responseInfo.status() >= 200 && responseInfo.status() < 400, String(responseInfo.status()));
    await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout: 30000 }).catch(() => {}); await page.waitForTimeout(500);
    const after = await grid.locator('tbody tr').count(); check('Grid recarregado', after >= before); result.before = before; result.after = after; result.status = 'APROVADO_TECNICAMENTE';
  } finally { result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(out), { recursive: true }); fs.writeFileSync(out, JSON.stringify(result, null, 2)); await context.close(); await browser.close(); }
}
main().catch(error => { result.error = error.message; result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(out), { recursive: true }); fs.writeFileSync(out, JSON.stringify(result, null, 2)); console.error(error.stack || error.message); process.exit(1); });

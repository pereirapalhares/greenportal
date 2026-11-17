const fs = require('fs');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const saida = arg('--saida', 'tests/resultado-salvar-ajax-licencas.json');
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
const check = (name, ok, details = '') => { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); };

async function login(page) {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
}
async function main() {
    if (!usuario || !senha) throw new Error('Informe --usuario e --senha.');
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1600, height: 900 } }); page.setDefaultTimeout(30000);
    let navigations = 0;
    page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations += 1; });
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()));
        await page.goto(`${baseUrl}/principal/manutencaolegal_licencas.php`, { waitUntil: 'networkidle' });
        const grid = page.locator('#manutencaolegal_licencasBatchGrid'); await grid.waitFor({ state: 'visible' });
        const form = page.locator('#manutencaolegal_licencasGridForm'); check('Grid de licenças carregado', await form.count() === 1);
        const rowsBefore = await grid.locator('tbody tr').count(); check('Grid possui registros', rowsBefore > 0, String(rowsBefore));
        const urlBefore = page.url();
        const row = grid.locator('tbody tr').first(); await row.click();
        const field = row.locator('[data-column="OBSERVACAO"] textarea, [data-column="OBSERVACAO"] input').first();
        check('Campo editável localizado', await field.count() === 1);
        const original = await field.inputValue();
        await field.fill(original + ' teste ajax');
        await field.fill(original);
        const save = page.locator('[data-crud-action="save"]').first(); check('Botão Salvar disponível', await save.count() === 1);
        check('Botão Salvar habilitado após alteração', await save.isEnabled());
        const navigationBeforeSave = navigations;
        await save.click();
        const response = await page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('manutencaolegal_licencas.php'), { timeout: 30000 });
        check('Salvamento enviado por AJAX', response.ok() || response.status() === 302, `${response.status()} ${response.url()}`);
        await page.locator('.licencas-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout: 15000 });
        check('Página não foi recarregada', navigations === navigationBeforeSave, `navegações: ${navigations - navigationBeforeSave}`);
        check('URL e filtros foram preservados', page.url() === urlBefore, page.url());
        check('Grid permaneceu visível após salvar', await grid.isVisible());
        check('Quantidade de linhas foi preservada', await grid.locator('tbody tr').count() === rowsBefore, String(await grid.locator('tbody tr').count()));
        result.status = 'APROVADO';
    } catch (error) { result.error = error.message; throw error; }
    finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(saida, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

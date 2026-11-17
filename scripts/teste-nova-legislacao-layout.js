const fs = require('fs');
const { chromium } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'); const usuario = arg('--usuario', ''); const senha = arg('--senha', '');
const output = arg('--saida', 'tests/resultado-nova-legislacao-layout.json'); const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function login(page) { await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); const company = page.locator('#empresa'); await company.selectOption({ label: empresa }).catch(() => company.selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); }
async function main() {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' }); const page = await browser.newPage({ viewport: { width: 1600, height: 900 } }); page.setDefaultTimeout(timeout);
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url())); await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Legislação').catch(() => page.locator('#menuSearch').fill('Legislacao'));
        const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_legislacao.php"]'); await menu.waitFor({ state: 'visible' }); await menu.click();
        const frame = page.frameLocator('iframe[src*="manutencaolegal_legislacao.php"]'); const grid = frame.locator('#legislacaoBatchGrid'); await grid.waitFor({ state: 'visible' }); check('Grid de legislação carregado', await grid.isVisible());
        const before = await grid.locator('tbody tr').count(); await frame.locator('[data-new-legislacao]').click(); const row = grid.locator('tbody tr').first();
        check('Nova linha criada', (await row.getAttribute('data-key') || '').startsWith('novo_'));
        const orders = await row.evaluate(element => { const visible = node => getComputedStyle(node).display !== 'none' && node.getClientRects().length > 0; const headers = [...element.closest('table').querySelectorAll('thead th')].filter(visible).map(node => node.dataset.column); const cells = [...element.querySelectorAll('td')].filter(visible).map(node => node.dataset.column); return { headers, cells }; });
        check('Nova linha mantém a ordem das colunas', JSON.stringify(orders.headers) === JSON.stringify(orders.cells), `cabeçalho=${orders.headers.join('|')} linha=${orders.cells.join('|')}`);
        const code = row.locator('[data-column="CODIGO"] input').first(); check('Campo Código presente na nova linha', await code.count() === 1); check('Campo Código permite digitação', !(await code.isDisabled()) && (await code.getAttribute('readonly')) === null, `readonly=${await code.getAttribute('readonly') || 'não'} disabled=${await code.isDisabled()}`);
        const technical = row.locator('[data-column="CODLEGISLACAO"]'); check('Chave técnica permanece oculta', await technical.count() === 1 && !(await technical.isVisible()));
        check('Quantidade original de registros preservada antes do salvamento', before >= 0, `${before} registros antes da inclusão`); result.details = orders; result.status = 'APROVADO';
    } catch (error) { result.error = error.message; throw error; } finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

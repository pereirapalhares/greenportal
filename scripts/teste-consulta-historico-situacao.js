const fs = require('fs');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const situacao = arg('--situacao', '39');
const saida = arg('--saida', 'tests/resultado-consulta-historico-situacao.json');
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
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(30000);
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()));
        await page.goto(`${baseUrl}/principal/modulolegal_requisitoslegais_situacaohistorico.php?CODSITUACAO=${encodeURIComponent(situacao)}`, { waitUntil: 'networkidle' });
        const grid = page.locator('#modulolegal_requisitoslegais_situacaohistoricoBatchGrid'); await grid.waitFor({ state: 'visible' });
        const columns = await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column));
        check('Somente as três colunas de consulta estão visíveis', JSON.stringify(columns) === JSON.stringify(['DESCRICAO', 'DATAHISTORICO', 'CODUSUARIO']), columns.join(','));
        for (const action of ['save', 'new', 'duplicate', 'delete']) check(`Botão ${action} não está disponível`, await page.locator(`[data-crud-action="${action}"]`).count() === 0);
        check('Registro anterior permanece oculto', await grid.locator('thead th[data-column="registroanterior"]').evaluate(el => getComputedStyle(el).display === 'none'));
        check('Grid carregado', (await grid.locator('tbody tr').count()) > 0 || (await page.locator('body').innerText()).includes('0 registros'));
        result.status = 'APROVADO';
    } catch (error) { result.error = error.message; throw error; }
    finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(saida, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

const fs = require('fs');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const codigoEmpresa = arg('--codigo-empresa', '4');
const saida = arg('--saida', 'tests/resultado-filtros-legislacao-empresa.json');
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
const check = (name, ok, details = '') => { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); };

function filtersUrl(filters) { return `${baseUrl}/principal/manutencaolegal_legislacaoxempresa.php?filtros=${encodeURIComponent(JSON.stringify(filters))}`; }
function gridCodes(page) {
    return page.locator('#legislacaoEmpresaBatchGrid tbody [data-column="CODIGO"] input').evaluateAll(inputs => inputs.map(input => String(input.value || '').trim()));
}
async function login(page) {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
}
async function assertOnly(page, expected, label, requireMatch = true) {
    const codes = await gridCodes(page);
    check(`${label}: grid carregado`, await page.locator('#legislacaoEmpresaBatchGrid').count() === 1);
    check(`${label}: nenhum código diferente foi retornado`, codes.every(code => code === expected), codes.join(',') || '(vazio)');
    if (requireMatch) check(`${label}: código esperado está presente`, codes.includes(expected), codes.join(','));
}
async function main() {
    if (!usuario || !senha) throw new Error('Informe --usuario e --senha.');
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(30000);
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()));
        await page.goto(filtersUrl({ CODEMPRESA: codigoEmpresa }), { waitUntil: 'networkidle' });
        const grid = page.locator('#legislacaoEmpresaBatchGrid'); await grid.waitFor({ state: 'visible' });
        const codeFilter = page.locator('[data-column-filter="CODIGO"]'); check('Filtro da coluna Código presente', await codeFilter.count() === 1);

        await codeFilter.fill('LIC0099'); await page.waitForTimeout(2200); await grid.waitFor({ state: 'visible' });
        await assertOnly(page, 'LIC0099', 'Filtro digitado LIC0099', false);
        check('LIC0099 revogada não aparece no relacionamento', (await gridCodes(page)).length === 0, (await gridCodes(page)).join(',') || '(vazio)');

        const direct = await page.goto(filtersUrl({ CODEMPRESA: codigoEmpresa, CODIGO: 'LIC0088' }), { waitUntil: 'networkidle' });
        check('Filtro direto carregado', !!direct && direct.ok(), String(direct && direct.status()));
        await grid.waitFor({ state: 'visible' }); await assertOnly(page, 'LIC0088', 'Filtro direto LIC0088');
        check('URL mantém o filtro CODIGO', new URL(page.url()).searchParams.get('filtros')?.includes('CODIGO') === true, page.url());
        result.status = 'APROVADO';
    } catch (error) { result.error = error.message; throw error; }
    finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(saida, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

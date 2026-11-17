const fs = require('fs');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const index = args.indexOf(name); return index >= 0 && args[index + 1] ? args[index + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const output = arg('--saida', 'tests/resultado-nao-visual-crud-legislacao.json');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function login(page) {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    const company = page.locator('#empresa');
    await company.selectOption({ label: empresa }).catch(() => company.selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
}
async function confirm(page) {
    const button = page.locator('#legislacaoConfirmModal [data-confirm]').first();
    await button.waitFor({ state: 'visible', timeout: 5000 }); await button.click();
}
async function save(page) { await page.locator('[data-crud-action="save"]').click(); await confirm(page); await page.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout }); }
function cell(row, column) { return row.locator(`[data-column="${column}"]`); }
async function firstOption(select) { return select.locator('option').evaluateAll(options => options.map(option => option.value).find(value => value !== '') || ''); }
async function main() {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
    let testRow = null; let testKey = ''; let created = false;
    const testCode = `GPTEST${Date.now()}`;
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()));
        await page.goto(`${baseUrl}/principal/manutencaolegal_legislacao.php`, { waitUntil: 'networkidle' });
        const grid = page.locator('#legislacaoBatchGrid'); await grid.waitFor({ state: 'visible' });
        check('Grid de legislação carregado', await grid.locator('tbody tr').count() >= 0);
        check('Botões CRUD presentes', await page.locator('[data-new-legislacao]').count() === 1 && await page.locator('[data-delete-legislacao]').count() === 1 && await page.locator('[data-crud-action="save"]').count() === 1);
        await page.locator('[data-new-legislacao]').click(); testRow = grid.locator('tbody tr').first();
        check('Nova linha criada no grid', await testRow.getAttribute('data-key') && (await testRow.getAttribute('data-key')).startsWith('novo_'));
        await cell(testRow, 'CODIGO').locator('input').fill(testCode);
        await cell(testRow, 'NR').locator('input').fill('1'); await cell(testRow, 'ANO').locator('input').fill('2099');
        for (const column of ['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'situacao_da_lei', 'devido_da_lei']) {
            const select = cell(testRow, column).locator('select'); if (await select.count()) { const value = await firstOption(select); if (value) await select.selectOption(value); }
        }
        await save(page); created = true;
        testKey = await testRow.getAttribute('data-key') || '';
        check('Inclusão da legislação concluída', /^\d+$/.test(testKey) && await page.locator('.grid-ajax-message').textContent().then(text => /sucesso|conclu/i.test(text)));
        await cell(testRow, 'NR').locator('input').fill('2'); await save(page);
        check('Alteração da legislação concluída', await cell(testRow, 'NR').locator('input').inputValue() === '2');
        await testRow.click(); await page.locator('[data-delete-legislacao]').click(); await confirm(page);
        await page.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout });
        check('Exclusão da legislação concluída', await grid.locator(`tbody tr[data-key="${testKey}"]`).count() === 0);
        created = false; result.status = 'APROVADO';
    } catch (error) { result.error = error.message; throw error; }
    finally {
        if (created && testKey) { try { await testRow.click(); await page.locator('[data-delete-legislacao]').click(); await confirm(page); } catch (_) { result.cleanup = 'Não foi possível limpar o registro de teste pela interface.'; } }
        result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await browser.close();
    }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

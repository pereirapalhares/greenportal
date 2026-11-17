const fs = require('fs');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const output = arg('--saida', 'tests/resultado-nao-visual-contexto-legislacao.json');
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function login(page) {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    const company = page.locator('#empresa'); await company.selectOption({ label: empresa }).catch(() => company.selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
}
async function confirm(page) { const button = page.locator('#legislacaoConfirmModal [data-confirm]').first(); await button.waitFor({ state: 'visible', timeout: 5000 }); await button.click(); }
async function save(page) { await page.locator('[data-crud-action="save"]').click(); await confirm(page); await page.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout: 15000 }); }
async function deleteRow(page, row) { await row.click(); await page.locator('[data-delete-legislacao]').click(); await confirm(page); await page.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout: 15000 }); }
function cell(row, column) { return row.locator(`[data-column="${column}"]`); }
async function firstNonEmpty(select) { return select.locator('option').evaluateAll(options => options.map(o => o.value).find(v => v !== '') || ''); }
async function findRowByCode(grid, code) {
    const rows = grid.locator('tbody tr');
    for (let i = 0; i < await rows.count(); i++) {
        const candidate = rows.nth(i); const input = cell(candidate, 'CODIGO').locator('input');
        if (await input.count() && (await input.inputValue()) === code) return candidate;
    }
    return null;
}

async function main() {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(15000);
    let createdRows = [];
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()));
        await page.goto(`${baseUrl}/principal/manutencaolegal_legislacao.php`, { waitUntil: 'networkidle' });
        const grid = page.locator('#legislacaoBatchGrid'); await grid.waitFor({ state: 'visible' }); check('Grid de legislação carregado', await grid.count() === 1);
        const companyFilter = page.locator('#legislacaoFilterCODEMPRESA'), unitFilter = page.locator('#legislacaoFilterCODUNIDADE');
        check('Filtros de Empresa e Unidade presentes', await companyFilter.count() === 1 && await unitFilter.count() === 1);

        // Contexto incompleto: somente Empresa deve impedir a criação.
        const companyValue = await firstNonEmpty(companyFilter); check('Empresa disponível para teste', !!companyValue);
        await companyFilter.selectOption(companyValue); await page.waitForTimeout(150);
        let blockedAlert = ''; page.once('dialog', dialog => { blockedAlert = dialog.message(); dialog.dismiss(); });
        await page.locator('[data-new-legislacao]').click(); await page.waitForTimeout(200);
        check('Cadastro bloqueado com contexto incompleto', !!blockedAlert && /Empresa|Company/i.test(blockedAlert));

        // Contexto Empresa + Unidade: cria a lei e permite duplicação sem copiar o código.
        const unitValue = await firstNonEmpty(unitFilter); check('Unidade disponível para teste', !!unitValue);
        await unitFilter.selectOption(unitValue); await page.waitForTimeout(150);
        await page.locator('[data-new-legislacao]').click(); let row = grid.locator('tbody tr').first(); const linkedCode = `GPVINC${Date.now()}`;
        await cell(row, 'CODIGO').locator('input').fill(linkedCode); await save(page); row = await findRowByCode(grid, linkedCode);
        check('Lei vinculada localizada após salvar', !!row); const linkedKey = row ? await row.getAttribute('data-key') : ''; if (row) createdRows.push(row); check('Lei vinculada criada com Empresa e Unidade', /^\d+$/.test(linkedKey || ''));
        await row.click(); await page.locator('[data-duplicate-legislacao]').click(); await confirm(page); const duplicate = grid.locator('tbody tr[data-key^="novo_"]').first();
        check('Duplicação cria nova linha', (await duplicate.getAttribute('data-key') || '').startsWith('novo_'));
        check('Duplicação exige novo Código', await cell(duplicate, 'CODIGO').locator('input').inputValue() === '');
        await page.locator('#legislacaoConfirmModal').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {}); await page.waitForTimeout(300);
        await duplicate.evaluate(element => element.remove());
        await deleteRow(page, row); createdRows = [];

        // Sem contexto: cria lei geral somente na base de legislação.
        await companyFilter.selectOption(''); await page.waitForTimeout(100); await page.locator('[data-new-legislacao]').click(); row = grid.locator('tbody tr').first(); const generalCode = `GPGERAL${Date.now()}`;
        await cell(row, 'CODIGO').locator('input').fill(generalCode); await save(page); row = await findRowByCode(grid, generalCode); const generalKey = row ? await row.getAttribute('data-key') : ''; if (row) createdRows.push(row);
        check('Lei geral criada sem Empresa e Unidade', /^\d+$/.test(generalKey || ''));
        await deleteRow(page, row); createdRows = []; result.status = 'APROVADO';
    } catch (error) { result.error = error.message; throw error; }
    finally { for (const row of createdRows) { try { await deleteRow(page, row); } catch (_) {} } result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

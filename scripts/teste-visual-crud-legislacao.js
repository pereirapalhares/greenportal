const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const index = args.indexOf(name); return index >= 0 && args[index + 1] ? args[index + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const outputDir = path.resolve(arg('--saida', 'tests/evidencias_crud_visual_legislacao'));
const visible = args.includes('--visivel'); const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), visual: true, checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function screenshot(page, name) { const file = path.join(outputDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }
async function login(page) { await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); const company = page.locator('#empresa'); await company.selectOption({ label: empresa }).catch(() => company.selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); }
async function confirm(frame) { const button = frame.locator('#legislacaoConfirmModal [data-confirm]').first(); await button.waitFor({ state: 'visible', timeout: 5000 }); await button.click(); }
function cell(row, column) { return row.locator(`[data-column="${column}"]`); }
async function firstOption(select) { return select.locator('option').evaluateAll(options => options.map(option => option.value).find(value => value !== '') || ''); }
async function save(page, frame) { const response = page.waitForResponse(request => request.request().method() === 'POST' && request.url().includes('manutencaolegal_legislacao.php'), { timeout }); await frame.locator('[data-crud-action="save"]').click(); await confirm(frame); const received = await response; check('Salvamento enviado por AJAX', received.ok() || received.status() === 302, `${received.status()} ${received.url()}`); await frame.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout }); }
async function main() {
    const browser = await chromium.launch({ headless: !visible, channel: 'chrome' }); const context = await browser.newContext({ viewport: { width: 1600, height: 900 } }); const page = await context.newPage(); page.setDefaultTimeout(timeout);
    let frame; let row; let key = ''; let created = false; const code = `GPVIS${Date.now()}`;
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()), page.url()); await screenshot(page, '01-login');
        await page.locator('[data-sidebar-toggle]').click().catch(() => {});
        await page.locator('#menuSearch').fill('Legislação').catch(() => page.locator('#menuSearch').fill('Legislacao'));
        const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_legislacao.php"]'); await menu.waitFor({ state: 'visible' }); check('Item de menu Legislação disponível', await menu.count() === 1); await menu.click();
        frame = page.frameLocator('iframe[src*="manutencaolegal_legislacao.php"]'); const grid = frame.locator('#legislacaoBatchGrid'); await grid.waitFor({ state: 'visible' }); const rows = await grid.locator('tbody tr').count(); check('Grid de legislação carregado', rows > 0, `${rows} linhas`); check('Botões CRUD visíveis', await frame.locator('[data-new-legislacao],[data-duplicate-legislacao],[data-delete-legislacao],[data-crud-action="save"]').count() >= 4); await screenshot(page, '02-grid');
        await frame.locator('[data-new-legislacao]').click(); row = grid.locator('tbody tr').first(); key = await row.getAttribute('data-key') || ''; check('Nova linha exibida', key.startsWith('novo_')); await cell(row, 'CODIGO').locator('input').fill(code); await cell(row, 'NR').locator('input').fill('1'); await cell(row, 'ANO').locator('input').fill('2099');
        for (const column of ['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'situacao_da_lei', 'devido_da_lei']) { const select = cell(row, column).locator('select'); if (await select.count()) { const value = await firstOption(select); if (value) await select.selectOption(value); } }
        await screenshot(page, '03-nova-legislacao'); await save(page, frame); created = true; key = await row.getAttribute('data-key') || ''; check('Inclusão concluída', /^\d+$/.test(key)); await screenshot(page, '04-apos-inclusao');
        await cell(row, 'NR').locator('input').fill('2'); await save(page, frame); check('Alteração concluída', await cell(row, 'NR').locator('input').inputValue() === '2'); await screenshot(page, '05-apos-alteracao');
        await row.click(); await frame.locator('[data-delete-legislacao]').click(); await confirm(frame); await frame.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout }); check('Exclusão concluída', await grid.locator(`tbody tr[data-key="${key}"]`).count() === 0); created = false; await screenshot(page, '06-apos-exclusao'); result.status = 'APROVADO';
    } catch (error) { result.error = error.message; try { await screenshot(page, 'falha'); } catch (_) {} throw error; }
    finally { if (created && key && frame) { try { await row.click(); await frame.locator('[data-delete-legislacao]').click(); await confirm(frame); } catch (_) { result.cleanup = 'Registro de teste não removido automaticamente.'; } } result.finishedAt = new Date().toISOString(); fs.writeFileSync(path.join(outputDir, 'resultado.json'), JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

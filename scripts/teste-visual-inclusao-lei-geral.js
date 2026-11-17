const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'); const usuario = arg('--usuario', ''); const senha = arg('--senha', '');
const outputDir = path.resolve(arg('--saida', 'tests/evidencias_inclusao_lei_geral')); const visible = args.includes('--visivel');
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), visual: true, checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function shot(page, name) { const file = path.join(outputDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }
async function login(page) { await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); const company = page.locator('#empresa'); await company.selectOption({ label: empresa }).catch(() => company.selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); }
async function confirm(frame) { const button = frame.locator('#legislacaoConfirmModal [data-confirm]').first(); await button.waitFor({ state: 'visible', timeout: 5000 }); await button.click(); }
function cell(row, column) { return row.locator(`[data-column="${column}"]`); }
async function firstOption(select) { return select.locator('option').evaluateAll(options => options.map(o => o.value).find(v => v !== '') || ''); }
async function findRowByCode(grid, code) { const rows = grid.locator('tbody tr'); const index = await rows.evaluateAll((items, expected) => items.findIndex(row => { const target = row.querySelector('[data-column="CODIGO"]'); if (!target) return false; const field = target.querySelector('input,select,textarea'); const value = field ? String(field.value || '') : String(target.textContent || ''); return value.trim() === String(expected).trim(); }), code); return index >= 0 ? rows.nth(index) : null; }
async function waitWorkspaceReady(page, frame) {
    const deadline = Date.now() + 30000;
    await page.locator('.workspace-pane-loading').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
    await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout: 30000 });

    // O grid pode receber a classe grid-ready antes da transição do modal terminar.
    // Aguarde o estado efetivamente interativo, sem engolir timeout do overlay.
    let stableSince = null;
    while (Date.now() < deadline) {
        const loading = await frame.locator('.grid-loading-modal.show').count();
        const backdrop = await frame.locator('.modal-backdrop.show').count();
        const ready = await frame.locator('.menu-grid-fragment.grid-ready').count();
        if (loading === 0 && backdrop === 0 && ready > 0) {
            if (stableSince === null) stableSince = Date.now();
            if (Date.now() - stableSince >= 700) return;
        } else {
            stableSince = null;
        }
        await page.waitForTimeout(200);
    }

    const loading = await frame.locator('.grid-loading-modal.show').count();
    const backdrop = await frame.locator('.modal-backdrop.show').count();
    throw new Error(`Grid ainda bloqueado após o carregamento: overlay=${loading}, backdrop=${backdrop}`);
}
async function reloadGridWithSearch(page, frame, code) { if (frame === page) { const url = new URL(page.url()); if (code) url.searchParams.set('busca', code); else url.searchParams.delete('busca'); url.searchParams.set('_teste', String(Date.now())); await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 30000 }); } else { const iframe = page.locator('iframe[src*="manutencaolegal_legislacao.php"]').first(); await iframe.evaluate((element, value) => { const url = new URL(element.contentWindow.location.href); url.searchParams.set('busca', value); url.searchParams.set('_teste', String(Date.now())); element.src = url.toString(); }, code); } await frame.locator('#legislacaoBatchGrid').waitFor({ state: 'visible', timeout: 30000 }); await waitWorkspaceReady(page, frame); }
async function save(page, frame) { const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('manutencaolegal_legislacao.php'), { timeout: 30000 }); await frame.locator('[data-crud-action="save"]').click(); await confirm(frame); const received = await response; check('Salvamento enviado', received.ok() || received.status() === 302, `${received.status()} ${received.url()}`); await frame.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout: 30000 }); }

async function main() {
    const browser = await chromium.launch({ headless: !visible, channel: 'chrome' }); const context = await browser.newContext({ viewport: { width: 1600, height: 900 } }); const page = await context.newPage(); page.setDefaultTimeout(30000);
    let frame; let grid; let createdKey = ''; let created = false; const code = 'EA0047Z';
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()), page.url()); await shot(page, '01-login');
        await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Legislação').catch(() => page.locator('#menuSearch').fill('Legislacao'));
        const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_legislacao.php"]'); await menu.waitFor({ state: 'visible', timeout: 30000 }); await menu.click();
        frame = page.frameLocator('iframe[src*="manutencaolegal_legislacao.php"]'); grid = frame.locator('#legislacaoBatchGrid'); await grid.waitFor({ state: 'visible', timeout: 30000 }); await waitWorkspaceReady(page, frame);
        check('Grid de legislação carregado', await grid.locator('tbody tr').count() > 0); check('Empresa não selecionada', await frame.locator('#legislacaoFilterCODEMPRESA').inputValue() === ''); check('Unidade não selecionada', await frame.locator('#legislacaoFilterCODUNIDADE').inputValue() === '');
        await reloadGridWithSearch(page, frame, code); const previousRow = await findRowByCode(grid, code); if (previousRow) { await previousRow.click(); await frame.locator('[data-delete-legislacao]').click(); await confirm(frame); await frame.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout: 30000 }); check('Registro anterior EA0047Z excluído', true); } else { check('Registro anterior EA0047Z não encontrado', true); } await reloadGridWithSearch(page, frame, ''); await shot(page, '02-grid-sem-contexto');
        await frame.locator('[data-new-legislacao]').click(); let row = grid.locator('tbody tr').first(); const key = await row.getAttribute('data-key') || ''; check('Nova linha criada', key.startsWith('novo_'));
        const tipoSelect = cell(row, 'CODTIPO').locator('select');
        if (await tipoSelect.count()) {
            const labels = await tipoSelect.locator('option').evaluateAll(options => options.map(option => option.textContent.trim()).filter(label => label && label !== 'Selecione'));
            const sorted = [...labels].sort((a, b) => a.localeCompare(b, 'pt-BR', { sensitivity: 'base' }));
            check('Tipos ordenados alfabeticamente', JSON.stringify(labels) === JSON.stringify(sorted));
        }
        await cell(row, 'CODIGO').locator('input').fill(code);
        for (const column of ['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'situacao_da_lei', 'devido_da_lei']) { const select = cell(row, column).locator('select'); if (await select.count()) { const value = await firstOption(select); if (value) await select.selectOption(value); } }
        await cell(row, 'NR').locator('input').fill('47'); await cell(row, 'ANO').locator('input').fill('2026'); await cell(row, 'ARTIGOS').locator('input').fill('Artigo 1');
        await cell(row, 'data_atualizacao').locator('input').fill('2026-08-26T10:00'); const patternButton = cell(row, 'PADRAO').locator('[data-edit-pattern]'); if (await patternButton.count()) { await patternButton.click(); const patternModal = frame.locator('#legislacaoPatternModal'); await patternModal.waitFor({ state: 'visible' }); await patternModal.locator('[data-pattern-text]').fill('EA0047Z - cadastro de lei geral'); await patternModal.locator('[data-confirm-pattern]').click(); }
        await shot(page, '03-nova-lei-preenchida');
        await save(page, frame); created = true; await reloadGridWithSearch(page, frame, code); row = await findRowByCode(grid, code); check('Lei geral localizada após salvar', !!row); createdKey = row ? await row.getAttribute('data-key') || '' : ''; check('Lei geral incluída sem Empresa/Unidade', /^\d+$/.test(createdKey)); await shot(page, '04-lei-geral-incluida');
        const assuntoButton = cell(row, 'ASSUNTOS').locator('[data-edit-assuntos]'); check('Botão de Assunto presente', await assuntoButton.count() === 1); await assuntoButton.click(); const assuntoPopup = page.locator('[data-popup-transacao-independent]').last(); await assuntoPopup.waitFor({ state: 'visible' }); check('Popup independente de Assunto aberto', await assuntoPopup.isVisible()); const assuntoFrame = page.frameLocator('iframe[src*="manutencaolegal_legislacao_assuntos.php"]'); const assunto = assuntoFrame.locator('[data-list] input[type="checkbox"]').first(); await assunto.waitFor({ state: 'visible' }); await shot(page, '05-popup-assunto'); await assunto.check(); await assuntoFrame.locator('[data-save]').click(); await assuntoPopup.waitFor({ state: 'hidden', timeout: 15000 }); await page.waitForTimeout(500); check('Assunto selecionado', await cell(row, 'ASSUNTOS').locator('input').inputValue().then(value => value.trim() !== ''));
        result.status = 'APROVADO';
    } catch (error) { result.error = error.message; try { await shot(page, 'falha'); } catch (_) {} throw error; }
    finally { if (created) result.note = 'O registro EA0047Z foi mantido conforme solicitado; não há exclusão automática.'; result.finishedAt = new Date().toISOString(); fs.writeFileSync(path.join(outputDir, 'resultado.json'), JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

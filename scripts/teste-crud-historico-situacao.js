const fs = require('fs');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const saida = arg('--saida', 'tests/resultado-crud-historico-situacao.json');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha ou use GREENPORTAL_USUARIO/GREENPORTAL_SENHA.'); process.exit(2); }

const result = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
const check = (name, ok, details = '') => { result.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); };
async function login(page) { await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); }
async function confirmSave(frame) { const button = frame.locator('#cadastroConfirmModal [data-confirm-cadastro], #greenPortalCadastroConfirm [data-confirmar]').first(); await button.waitFor({ state: 'visible', timeout: 5000 }); await button.click(); await new Promise(resolve => setTimeout(resolve, 900)); }
async function save(frame) { await frame.locator('[data-crud-action="save"]').click(); await confirmSave(frame); await frame.waitForLoadState('domcontentloaded').catch(() => {}); await frame.waitForTimeout(1200); }
async function reloadHistory(frame, situationId) { await frame.goto(`${baseUrl}/principal/modulolegal_requisitoslegais_situacaohistorico.php?CODSITUACAO=${encodeURIComponent(situationId)}`, { waitUntil: 'networkidle' }); await frame.locator('#modulolegal_requisitoslegais_situacaohistoricoBatchGrid').waitFor({ state: 'visible' }); }
function field(row, column) { return row.locator(`[data-column="${column}"] input, [data-column="${column}"] textarea, [data-column="${column}"] select`).first(); }
async function rowByField(grid, column, value) { const rows = grid.locator('tbody tr'), total = await rows.count(), observed = []; for (let i = 0; i < total; i++) { const row = rows.nth(i), control = field(row, column); if (await control.count()) { const current = String(await control.inputValue()).trim(); observed.push(current); if (current === String(value).trim()) return row; } } throw new Error(`Registro não localizado no campo ${column}: ${value}. Valores carregados: ${observed.join(' | ') || '(nenhum)'}`); }
async function main() {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
    const created = [];
    try {
        await login(page); check('Login realizado', /main\.php/.test(page.url()));
        await page.locator('[data-sidebar-toggle]').click().catch(() => {});
        await page.locator('#menuSearch').fill('Requisitos legais');
        await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
        const req = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
        const reqRow = req.locator('tbody tr').first(); await reqRow.waitFor({ state: 'visible' }); await reqRow.click();
        const situationButton = req.locator('[data-open-requisito-popup="situacao"]'); check('Botão Situação habilitado', await situationButton.isEnabled()); await situationButton.click();
        const situationPopup = page.locator('#greenPortalTransacaoModal'); await situationPopup.waitFor({ state: 'visible' });
        const situation = situationPopup.locator('iframe').contentFrame(); await situation.locator('.situacao-grid-fragment .menu-batch-grid tbody tr').first().waitFor({ state: 'visible' });
        const situationRow = situation.locator('.situacao-grid-fragment .menu-batch-grid tbody tr').first(); await situationRow.click(); const situationId = await situationRow.getAttribute('data-key');
        const historyButton = situation.locator('[data-requisito-situacao-historico]'); check('Botão Histórico habilitado', await historyButton.isEnabled());
        const lawCode = await situation.locator('[data-situacao-law-code]').getAttribute('data-situacao-law-code');
        const situationText = (await field(situationRow, 'dscsituacao').inputValue()).trim();
        await historyButton.click();
        const historyPopup = page.locator('[id^="greenPortalSituacaoHistoricoModal_"]').last(); await historyPopup.waitFor({ state: 'visible' });
        check('Popup do histórico independente aberto', await historyPopup.isVisible());
        check('Popup de Situação permanece aberto', await situationPopup.isVisible());
        const expectedTitle = `${lawCode} - ${situationText}`;
        const actualTitle = await historyPopup.locator('.modal-title').textContent(); check('Título do histórico identifica lei e situação', (actualTitle || '').trim() === expectedTitle, actualTitle || '');
        const historyFrameHandle = await historyPopup.locator('iframe').elementHandle(); const history = await historyFrameHandle.contentFrame(); const grid = history.locator('#modulolegal_requisitoslegais_situacaohistoricoBatchGrid'); await grid.waitFor({ state: 'visible' });
        const visibleColumns = await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column));
        check('Somente as três colunas de negócio estão visíveis', JSON.stringify(visibleColumns) === JSON.stringify(['DESCRICAO', 'DATAHISTORICO', 'CODUSUARIO']), visibleColumns.join(','));
        for (const action of ['save', 'new', 'duplicate', 'delete', 'reload', 'export', 'columns']) check(`Botão CRUD ${action} presente`, await history.locator(`[data-crud-action="${action}"]`).count() === 1);
        const suffix = Date.now(); const originalText = `Teste CRUD histórico ${suffix}`; const editedText = `${originalText} editado`; const duplicatedText = `${originalText} duplicado`; const testDate = '2026-01-15';
        await history.locator('[data-crud-action="new"]').click(); let newRow = history.locator('tbody tr').first(); check('Novo registro criado no grid', (await newRow.getAttribute('data-key') || '').startsWith('novo_'));
        await field(newRow, 'DESCRICAO').fill(originalText); await field(newRow, 'DATAHISTORICO').fill(testDate); const userSelect = field(newRow, 'CODUSUARIO'); if (await userSelect.locator('option').count() > 1) await userSelect.selectOption({ index: 1 });
        await save(history); await reloadHistory(history, situationId); const insertedRow = await rowByField(grid, 'DESCRICAO', originalText); check('Inclusão do histórico concluída', !!insertedRow);
        let row = insertedRow; await row.click(); await field(row, 'DESCRICAO').fill(editedText); await save(history); await reloadHistory(history, situationId); const editedRow = await rowByField(grid, 'DESCRICAO', editedText); check('Edição do histórico concluída', !!editedRow);
        row = editedRow; await row.click(); await history.locator('[data-crud-action="duplicate"]').click(); const duplicateRow = history.locator('tbody tr').first(); await field(duplicateRow, 'DESCRICAO').fill(duplicatedText); await save(history); await reloadHistory(history, situationId); const duplicatedRow = await rowByField(grid, 'DESCRICAO', duplicatedText); check('Duplicação do histórico concluída', !!duplicatedRow);
        for (const textValue of [editedText, duplicatedText]) { const target = await rowByField(grid, 'DESCRICAO', textValue); await target.click(); await history.locator('[data-crud-action="delete"]').click(); await history.locator('#cadastroConfirmModal [data-confirm-cadastro]').click(); await new Promise(resolve => setTimeout(resolve, 900)); let remains = 0; try { await rowByField(grid, 'DESCRICAO', textValue); remains = 1; } catch (_) {} check(`Exclusão do registro ${textValue} concluída`, remains === 0); }
        result.status = 'APROVADO';
    } catch (error) { result.error = error.message; throw error; }
    finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(saida, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

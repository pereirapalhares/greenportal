const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
function arg(name, fallback = '') { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; }
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const timeout = Number(arg('--timeout', '30000'));
const output = arg('--saida', 'tests/resultado-crud-situacao-requisitos-legais.json');
const visual = args.includes('--visual');
const cdp = arg('--cdp', '');
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_crud_situacao_requisitos_legais'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha ou use GREENPORTAL_USUARIO/GREENPORTAL_SENHA.'); process.exit(2); }

const result = { startedAt: new Date().toISOString(), baseUrl, visual, checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
function wait(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
async function screenshot(page, name) { if (!visual) return; fs.mkdirSync(evidenceDir, { recursive: true }); const file = path.join(evidenceDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); }
async function waitForSituation(frame) {
  await frame.locator('.situacao-grid-fragment').waitFor({ state: 'visible', timeout });
  await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {});
  await wait(500);
}
async function confirmSave(frame) {
  const confirmation = frame.locator('#cadastroConfirmModal [data-confirm-cadastro]');
  await confirmation.waitFor({ state: 'visible', timeout });
  await confirmation.click();
  await wait(1200);
  await waitForSituation(frame);
}
async function rows(frame) {
  return frame.locator('.situacao-grid-fragment .menu-batch-grid tbody tr').evaluateAll(trs => trs.map(tr => ({ key: String(tr.dataset.key || ''), description: String(tr.querySelector('[name*="[dscsituacao]"]')?.value || '').trim(), responsible: String(tr.querySelector('[name*="[CODUSUARIO]"]')?.value || '').trim() })));
}
async function main() {
  const browser = cdp
    ? await chromium.connectOverCDP(cdp)
    : await chromium.launch(visual ? { headless: false, channel: 'chrome' } : { headless: true });
  const context = browser.contexts()[0] || await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = context.pages()[0] || await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/, { timeout }); check('Login realizado', true, page.url());
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais');
    const menu = page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]'); await menu.waitFor({ state: 'visible' }); await menu.click();
    const requisitos = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
    await requisitos.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout });
    const requisito = requisitos.locator('#requisitosLegaisBatchGrid tbody tr').first();
    const requisitoCodigo = await requisito.locator('[data-column="CODIGO"]').innerText();
    await requisito.click();
    const situacaoButton = requisitos.locator('[data-open-requisito-popup="situacao"]');
    await situacaoButton.waitFor({ state: 'visible' });
    await wait(250);
    const enabledDeadline = Date.now() + timeout;
    while (Date.now() < enabledDeadline && !(await situacaoButton.isEnabled())) await wait(100);
    check('Botão Situação foi habilitado após selecionar o requisito', await situacaoButton.isEnabled(), requisitoCodigo.trim());
    await situacaoButton.click();
    await page.locator('#greenPortalTransacaoModal').waitFor({ state: 'visible', timeout });
    check('Popup de Situação foi aberto', await page.locator('#greenPortalTransacaoModal').isVisible(), requisitoCodigo.trim());
    const popupTitle = await page.locator('#greenPortalTransacaoModal [data-popup-transacao-title]').innerText();
    check('Cabeçalho do popup referencia o código da lei', popupTitle.includes(requisitoCodigo.trim()), popupTitle);
    const situacao = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitForSituation(situacao); await screenshot(page, '01-popup-situacao');

    const save = situacao.locator('[data-crud-action="save"]');
    const novo = situacao.locator('[data-crud-action="new"]');
    const duplicar = situacao.locator('[data-crud-action="duplicate"]');
    const excluir = situacao.locator('[data-crud-action="delete"]');
    check('Popup possui Salvar, Novo, Duplicar e Excluir', await save.count() === 1 && await novo.count() === 1 && await duplicar.count() === 1 && await excluir.count() === 1);
    const crudTooltips = await Promise.all([save, novo, duplicar, excluir].map(button => button.getAttribute('title')));
    check('Botões CRUD possuem tooltip', crudTooltips.every(Boolean), crudTooltips.join(' | '));
    check('Botão Consultar responsáveis possui tooltip', Boolean(await situacao.locator('[data-requisito-responsabilidades]').getAttribute('title')));
    check('Botão Administrar responsáveis possui tooltip', Boolean(await situacao.locator('[data-requisito-responsabilidades-edicao]').getAttribute('title')));
    check('Botões de responsabilidades iniciam desabilitados', await situacao.locator('[data-requisito-responsabilidades][disabled],[data-requisito-responsabilidades-edicao][disabled]').count() === 2);

    const marker = `TESTE CRUD SITUAÇÃO ${Date.now()}`;
    await novo.click();
    const newRow = situacao.locator('.situacao-grid-fragment tbody tr').first();
    await newRow.locator('textarea[name*="[dscsituacao]"]').fill(marker);
    await newRow.locator('select[name*="[atendido]"]').selectOption('S');
    check('Novo registro foi inserido no grid', await newRow.count() === 1 && (await newRow.locator('textarea[name*="[dscsituacao]"]').inputValue()) === marker);
    await save.click(); await confirmSave(situacao);
    let current = await rows(situacao); const created = current.find(row => row.description === marker);
    check('Inclusão da Situação foi persistida', Boolean(created && /^\d+$/.test(created.key)), JSON.stringify(current));

    const createdRow = situacao.locator(`tbody tr[data-key="${created.key}"]`);
    const responsible = createdRow.locator('select[name*="[CODUSUARIO]"]');
    const responsibleOptions = await responsible.locator('option').evaluateAll(options => options.map(option => String(option.value || '')).filter(Boolean));
    check('Existem responsáveis disponíveis para testar alteração', responsibleOptions.length > 0, responsibleOptions.join(', '));
    await responsible.selectOption(responsibleOptions[0]); await save.click(); await confirmSave(situacao);
    current = await rows(situacao); const responsibleUpdated = current.find(row => row.key === created.key);
    check('Alteração do responsável foi persistida', responsibleUpdated && responsibleUpdated.responsible === responsibleOptions[0], JSON.stringify(current));

    const updatedMarker = `${marker} ALTERADA`;
    await createdRow.locator('textarea[name*="[dscsituacao]"]').fill(updatedMarker); await save.click(); await confirmSave(situacao);
    current = await rows(situacao); check('Alteração da Situação foi persistida', current.some(row => row.key === created.key && row.description === updatedMarker), JSON.stringify(current));

    await situacao.locator(`tbody tr[data-key="${created.key}"]`).click(); await duplicar.click();
    check('Duplicação criou uma nova linha', await situacao.locator('tbody tr').count() >= 2);
    await save.click(); await confirmSave(situacao);
    current = await rows(situacao); const duplicate = current.find(row => row.key !== created.key && row.description === updatedMarker);
    check('Duplicação da Situação foi persistida', Boolean(duplicate && /^\d+$/.test(duplicate.key)), JSON.stringify(current));

    await situacao.locator(`tbody tr[data-key="${duplicate.key}"]`).click(); await excluir.click();
    await confirmSave(situacao); current = await rows(situacao);
    check('Exclusão da Situação foi persistida', !current.some(row => row.key === duplicate.key), JSON.stringify(current));

    await situacao.locator(`tbody tr[data-key="${created.key}"]`).click(); await excluir.click();
    await confirmSave(situacao); current = await rows(situacao);
    check('Registro de teste original foi removido', !current.some(row => row.key === created.key), JSON.stringify(current));

    check('Botões de responsabilidades permanecem disponíveis no popup', await situacao.locator('[data-requisito-responsabilidades],[data-requisito-responsabilidades-edicao]').count() === 2);
    await screenshot(page, '02-crud-situacao-concluido');
    result.status = 'APROVADO';
  } catch (error) { result.error = error.message; try { await screenshot(page, 'falha'); } catch (_) {} throw error; }
  finally { result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(path.resolve(output)), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); if (!cdp) await browser.close(); }
}
main().catch(() => process.exit(1));

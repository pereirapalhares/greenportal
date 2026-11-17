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
const output = arg('--saida', 'tests/resultado-responsavel-situacao-lb0001.json');
const visual = args.includes('--visual');
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_responsavel_situacao_lb0001'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }

const result = { startedAt: new Date().toISOString(), baseUrl, requisito: 'LB0001', visual, checks: [], requests: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
function wait(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
async function screenshot(page, name) { if (!visual) return; fs.mkdirSync(evidenceDir, { recursive: true }); const file = path.join(evidenceDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); }
async function waitSituation(frame) { await frame.locator('.situacao-grid-fragment').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await wait(700); }
async function saveAndConfirm(frame, page) {
  const requestData = [];
  const listener = request => { if (request.method() === 'POST' && /manutencaolegalrequisitoslegais_situacao\.php/.test(request.url())) requestData.push({ url: request.url(), postData: request.postData() || '' }); };
  page.on('request', listener);
  await frame.locator('[data-crud-action="save"]').click();
  const confirmation = frame.locator('#cadastroConfirmModal [data-confirm-cadastro]'); await confirmation.waitFor({ state: 'visible', timeout }); await confirmation.click();
  await wait(1500); page.off('request', listener);
  result.requests.push(...requestData.map(item => ({ url: item.url, postDataHasResponsavel: /CODUSUARIO/.test(item.postData), postDataHasSaveRows: /save_rows/.test(item.postData), postDataPreview: item.postData.slice(0, 700) })));
  await waitSituation(frame);
  check('POST de salvamento da Situação foi enviado', requestData.length > 0, JSON.stringify(requestData.map(item => ({ url: item.url, postData: item.postData.slice(0, 700) }))));
  check('POST contém CODUSUARIO', requestData.some(item => /CODUSUARIO/.test(item.postData)), JSON.stringify(requestData.map(item => item.postData.slice(0, 700))));
}
async function readRow(frame, key) {
  const row = frame.locator(`tbody tr[data-key="${key}"]`);
  return { responsible: await row.locator('select[name*="[CODUSUARIO]"]').inputValue(), description: await row.locator('textarea[name*="[dscsituacao]"]').inputValue() };
}

async function main() {
  const browser = await chromium.launch(visual ? { headless: false, channel: 'chrome' } : { headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais'); await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const requisitos = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await requisitos.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout });
    const requisito = requisitos.locator('#requisitosLegaisBatchGrid tbody tr').filter({ has: requisitos.locator('[data-column="CODIGO"]', { hasText: 'LB0001' }) }).first();
    check('Requisito LB0001 encontrado', await requisito.count() === 1);
    await requisito.click(); const open = requisitos.locator('[data-open-requisito-popup="situacao"]'); await wait(250); check('Botão Situação habilitado', await open.isEnabled()); await open.click(); await page.locator('#greenPortalTransacaoModal').waitFor({ state: 'visible', timeout });
    const title = await page.locator('#greenPortalTransacaoModal [data-popup-transacao-title]').innerText(); check('Popup referencia LB0001', title.includes('LB0001'), title);
    const frame = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitSituation(frame); await screenshot(page, '01-situacao-lb0001');
    const existing = frame.locator('.situacao-grid-fragment tbody tr[data-key]').first(); await existing.waitFor({ state: 'visible', timeout }); const key = await existing.getAttribute('data-key'); const select = existing.locator('select[name*="[CODUSUARIO]"]'); const before = await readRow(frame, key); const options = await select.locator('option').evaluateAll(items => items.map(item => String(item.value || '')).filter(value => value));
    check('Situação existente encontrada', Boolean(key), key); check('Responsável atual foi lido', true, before.responsible || '(vazio)'); check('Há outro responsável para teste', options.some(value => value !== before.responsible), options.join(', '));
    const alternate = options.find(value => value !== before.responsible); await select.selectOption(alternate); const selectedBeforeSave = await select.inputValue(); check('Novo responsável foi selecionado', selectedBeforeSave === alternate, `${before.responsible} -> ${selectedBeforeSave}`);
    await saveAndConfirm(frame, page); const afterSave = await readRow(frame, key); check('Responsável permaneceu alterado após refresh', afterSave.responsible === alternate, `${before.responsible} -> ${afterSave.responsible}`);
    await frame.locator(`tbody tr[data-key="${key}"] select[name*="[CODUSUARIO]"]`).selectOption(before.responsible || ''); await saveAndConfirm(frame, page); const restored = await readRow(frame, key); check('Responsável original foi restaurado', restored.responsible === before.responsible, `${alternate} -> ${restored.responsible}`);
    await screenshot(page, '02-responsavel-restaurado'); result.status = 'APROVADO';
  } catch (error) { result.error = error.message; try { await screenshot(page, 'falha'); } catch (_) {} throw error; } finally { result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(path.resolve(output)), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(() => process.exit(1));

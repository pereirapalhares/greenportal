const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const output = path.resolve('tests/resultado-edicao-telas-direcionadas-20260924.json');
const base = 'http://localhost/greenportal';
const marker = ' [CRUD-DIRECIONADO-20260924]';
const cases = [
  { name: 'Meus Controles', route: '/principal/meus_controles.php', field: 'TITULO' },
  { name: 'Minhas Licenças', route: '/principal/minhas_licencas.php', field: 'DSCLICENCA' },
  { name: 'Minhas Infraestruturas', route: '/principal/minhas_infraestruturas.php', field: 'DSCINFRAESTRUTURA' },
];
const result = { startedAt: new Date().toISOString(), cases: [], status: 'REPROVADO' };
const timeout = 30000;
function check(item, name, ok, details = '') { item.checks.push({ name, ok: Boolean(ok), details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${item.name} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${item.name}: ${name}`); }
async function login(page) {
  await page.goto(`${base}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: 'Modelo' });
  await page.locator('#userid').fill('admin'); await page.locator('#senha').fill(process.env.GREEN_TEST_PASSWORD || '');
  await Promise.all([page.waitForURL(/\/principal\/main\.php/), page.locator('form.login-form button[type="submit"]').click()]);
}
async function waitGrid(page) { await page.locator('.menu-batch-grid').waitFor({ state: 'visible', timeout }); await page.locator('.grid-loading-modal:visible').waitFor({ state: 'hidden', timeout }).catch(() => {}); }
async function save(page, item) {
  const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(item.route), { timeout }).catch(() => null);
  await page.locator('[data-crud-action="save"]').first().click();
  const confirm = page.locator('#greenPortalCadastroConfirm [data-confirmar], #cadastroConfirmModal [data-confirm-cadastro], .modal.show [data-confirmar]').last();
  if (await confirm.waitFor({ state: 'visible', timeout: 10000 }).then(() => true).catch(() => false)) await confirm.click();
  const responseResult = await response;
  await page.waitForLoadState('domcontentloaded').catch(() => {}); await waitGrid(page);
  return responseResult;
}
async function editAndRestore(page, item) {
  const out = { name: item.name, route: item.route, field: item.field, checks: [] };
  await page.goto(base + item.route, { waitUntil: 'domcontentloaded' }); await waitGrid(page);
  const row = page.locator('.menu-batch-grid tbody tr[data-row]').first(); await row.waitFor({ state: 'visible', timeout });
  const input = row.locator(`[data-column="${item.field}"] input, [data-column="${item.field}"] textarea, [data-column="${item.field}"] select`).first();
  const original = await input.inputValue(); out.original = original; check(out, 'Campo editável encontrado', await input.count() === 1 && !(await input.isDisabled()));
  const edited = original + marker; await input.fill(edited); const saveResponse = await save(page, item); check(out, 'POST de edição concluído', Boolean(saveResponse && saveResponse.status() >= 200 && saveResponse.status() < 400), saveResponse ? String(saveResponse.status()) : 'sem resposta');
  const afterEdit = page.locator('.menu-batch-grid tbody tr[data-row]').first().locator(`[data-column="${item.field}"] input, [data-column="${item.field}"] textarea, [data-column="${item.field}"] select`).first();
  check(out, 'Edição persistida após recarga', (await afterEdit.inputValue()).includes(marker));
  await afterEdit.fill(original); const restoreResponse = await save(page, item); check(out, 'Valor original restaurado', Boolean(restoreResponse && restoreResponse.status() >= 200 && restoreResponse.status() < 400), restoreResponse ? String(restoreResponse.status()) : 'sem resposta');
  const restored = page.locator('.menu-batch-grid tbody tr[data-row]').first().locator(`[data-column="${item.field}"] input, [data-column="${item.field}"] textarea, [data-column="${item.field}"] select`).first(); check(out, 'Restauração confirmada após recarga', (await restored.inputValue()) === original); out.status = 'APROVADO'; return out;
}
async function main() {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222'); const context = browser.contexts()[0]; const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try { await login(page); for (const item of cases) { try { result.cases.push(await editAndRestore(page, item)); } catch (error) { result.cases.push({ name: item.name, route: item.route, status: 'REPROVADO', checks: [], error: error.message }); } } result.status = result.cases.length === cases.length && result.cases.every(item => item.status === 'APROVADO') ? 'APROVADO' : 'REPROVADO'; }
  finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await page.close().catch(() => {}); }
}
main().catch(error => { result.error = error.message; result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); process.exit(1); });

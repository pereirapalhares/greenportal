const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const base = 'http://localhost/greenportal';
const senha = process.argv.includes('--senha') ? process.argv[process.argv.indexOf('--senha') + 1] : '';
const output = path.resolve('tests/resultado-crud-laia-lpro-lais-controle-20260923.json');
const evidenceDir = path.resolve('tests/evidencias-crud-laia-lpro-lais-controle-20260923');
if (!senha) { console.error('Informe --senha.'); process.exit(2); }

const cases = [
  { name: 'LAIA', route: '/principal/modulolegal_laia.php', table: 'laia', primary: 'CODLAIA', markerField: 'detalhamento', marker: 'TESTE CRUD LAIA 20260923' },
  { name: 'LPRO', route: '/principal/modulolegal_lpro.php', table: 'lpro', primary: 'CODLPRO', markerField: 'detalhamento', marker: 'TESTE CRUD LPRO 20260923' },
  { name: 'LAIS', route: '/principal/modulolegal_lais.php', table: 'lais', primary: 'CODLAIS', markerField: 'MODO_DA_OCORRENCIA', marker: 'TESTE CRUD LAIS 20260923' },
  { name: 'Controle', route: '/principal/modulolegal_controle.php', table: 'documentos', primary: 'CODDOCUMENTO', markerField: 'CODIGO', marker: 'TESTE-CRUD-CONTROLE-20260923', secondField: 'TITULO', secondValue: 'Registro temporário de validação' },
];
const result = { startedAt: new Date().toISOString(), user: 'admin', empresa: 'Modelo', cases: [], status: 'REPROVADO' };
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (item, name, ok, details = '') => { item.checks.push({ name, ok: Boolean(ok), details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${item.name} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${item.name}: ${name}${details ? `: ${details}` : ''}`); };

async function login(page) {
  await page.goto(`${base}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: 'Modelo' });
  await page.locator('#userid').fill('admin'); await page.locator('#senha').fill(senha);
  await Promise.all([page.waitForURL(/\/principal\/main\.php/), page.locator('form.login-form button[type="submit"]').click()]);
}

async function waitGrid(page) {
  await page.locator('.menu-grid-fragment.grid-ready, .menu-grid-fragment').first().waitFor({ state: 'visible', timeout: 30000 });
  await page.locator('.grid-loading-modal:visible').waitFor({ state: 'hidden', timeout: 30000 }).catch(() => {});
  await sleep(500);
}

async function saveThroughUi(page, item) {
  const form = page.locator('form.menu-grid-form').first();
  const save = page.locator('[data-crud-action="save"], [data-save-cadastro]').first();
  check(item, 'Botão salvar disponível', await save.count() === 1);
  const responsePromise = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(item.route), { timeout: 30000 }).catch(() => null);
  await save.click();
  const confirm = page.locator('#greenPortalCadastroConfirm [data-confirmar], #cadastroConfirmModal [data-confirm-cadastro]').last();
  await confirm.waitFor({ state: 'visible', timeout: 10000 });
  await confirm.click();
  const response = await responsePromise;
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await waitGrid(page);
  return response;
}

async function deleteThroughUi(page, item, key) {
  const row = page.locator(`tbody tr[data-key="${key}"]`).first();
  await row.click({ force: true });
  if (!await row.evaluate(el => el.classList.contains('selected')).catch(() => false)) await row.dispatchEvent('click');
  const del = page.locator('[data-delete-cadastro]').first();
  check(item, 'Botão excluir habilitado', await del.count() === 1 && !(await del.isDisabled()));
  const responsePromise = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(item.route), { timeout: 30000 }).catch(() => null);
  await del.click();
  const confirm = page.locator('#cadastroConfirmModal [data-confirm-cadastro], #greenPortalCadastroConfirm [data-confirmar]').last();
  await confirm.waitFor({ state: 'visible', timeout: 10000 });
  await confirm.click();
  const response = await responsePromise;
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await waitGrid(page);
  return response;
}

async function rowWithValue(grid, field, value) {
  const rows = grid.locator('tbody tr[data-row]');
  for (let i = 0; i < await rows.count(); i += 1) {
    const cell = rows.nth(i).locator(`[data-column="${field}"]`);
    const actual = await cell.locator('input, textarea, select').first().evaluate(el => el.tagName.toLowerCase() === 'select' ? (el.value || el.options[el.selectedIndex]?.text || '') : (el.value || el.textContent || '')).catch(() => '');
    if (String(actual).includes(value)) return rows.nth(i);
  }
  return rows.locator('xpath=..').locator('tr[data-never-found]');
}

async function runCase(page, item, index) {
  const out = { name: item.name, route: item.route, table: item.table, primary: item.primary, marker: item.marker, checks: [], screenshot: '' };
  await page.goto(`${base}${item.route}`, { waitUntil: 'domcontentloaded' }); await waitGrid(page);
  const grid = page.locator('table.menu-batch-grid').first(); check(out, 'Grid carregado', await grid.count() === 1);
  const before = await grid.locator('tbody tr[data-row]').count(); out.before = before;
  const newButton = page.locator('[data-new-cadastro]').first(); check(out, 'Botão novo disponível', await newButton.count() === 1 && !(await newButton.isDisabled()));
  await newButton.click();
  const newRow = grid.locator('tbody tr[data-key^="novo_"]').first(); await newRow.waitFor({ state: 'visible' });
  const markerCell = newRow.locator(`[data-column="${item.markerField}"]`);
  const markerInput = markerCell.locator('input, textarea, select').first();
  if (await markerInput.evaluate(el => el.tagName.toLowerCase()) === 'select') await markerInput.selectOption({ label: item.marker }).catch(() => markerInput.selectOption({ value: item.marker }));
  else await markerInput.fill(item.marker);
  if (item.secondField) await newRow.locator(`[data-column="${item.secondField}"] input, [data-column="${item.secondField}"] textarea`).first().fill(item.secondValue);
  const response = await saveThroughUi(page, out); check(out, 'POST de inclusão concluído', Boolean(response && response.status() >= 200 && response.status() < 400), response ? String(response.status()) : 'sem resposta');
  await page.goto(`${base}${item.route}?filtros=${encodeURIComponent(JSON.stringify({ [item.markerField]: item.marker }))}`, { waitUntil: 'domcontentloaded' });
  await waitGrid(page);
  const createdRow = await rowWithValue(grid, item.markerField, item.marker);
  await createdRow.waitFor({ state: 'visible', timeout: 30000 });
  const key = await createdRow.getAttribute('data-key'); out.createdKey = key; check(out, 'Inclusão persistida e visível no grid', /^\d+$/.test(String(key || '')));
  await page.screenshot({ path: path.join(evidenceDir, `${String(index).padStart(2, '0')}-${item.name.toLowerCase()}-incluido.png`), fullPage: true });

  const editValue = `${item.marker} EDITADO`;
  const editCell = grid.locator(`tbody tr[data-key="${key}"] [data-column="${item.markerField}"]`);
  const editInput = editCell.locator('input, textarea, select').first();
  if (await editInput.evaluate(el => el.tagName.toLowerCase()) === 'select') await editInput.selectOption({ label: editValue }).catch(() => editInput.selectOption({ value: editValue }));
  else await editInput.fill(editValue);
  await saveThroughUi(page, out); check(out, 'Edição concluída', true);
  await page.goto(`${base}${item.route}?filtros=${encodeURIComponent(JSON.stringify({ [item.markerField]: editValue }))}`, { waitUntil: 'domcontentloaded' });
  await waitGrid(page);
  const editedRow = await rowWithValue(grid, item.markerField, editValue);
  check(out, 'Edição persistida e visível no grid', await editedRow.count() === 1);
  await page.screenshot({ path: path.join(evidenceDir, `${String(index).padStart(2, '0')}-${item.name.toLowerCase()}-editado.png`), fullPage: true });

  await deleteThroughUi(page, out, key);
  check(out, 'Exclusão concluída pela interface', await grid.locator(`tbody tr[data-key="${key}"]`).count() === 0);
  check(out, 'Registro temporário removido do grid', await grid.locator('tbody tr[data-row]').filter({ hasText: item.marker }).count() === 0);
  await page.screenshot({ path: path.join(evidenceDir, `${String(index).padStart(2, '0')}-${item.name.toLowerCase()}-excluido.png`), fullPage: true });
  out.status = 'APROVADO_TECNICAMENTE'; return out;
}

async function main() {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(30000);
  try {
    await login(page);
    for (let i = 0; i < cases.length; i += 1) {
      try { result.cases.push(await runCase(page, cases[i], i + 1)); }
      catch (error) { result.cases.push({ name: cases[i].name, route: cases[i].route, checks: [], error: error.message, status: 'REPROVADO' }); console.error(error.stack || error.message); }
    }
    result.status = result.cases.length === cases.length && result.cases.every(item => item.status === 'APROVADO_TECNICAMENTE') ? 'APROVADO_TECNICAMENTE' : 'REPROVADO';
  } finally {
    result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await context.close(); await browser.close();
  }
}
main().catch(error => { result.error = error.message; result.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); process.exit(1); });

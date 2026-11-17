const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const saida = path.resolve(arg('--saida', 'tests/evidencias_visual_firefox_assuntos'));
const visual = args.includes('--visual');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
fs.mkdirSync(saida, { recursive: true });
const marker = `[VISUAL ASSUNTOS ${Date.now()}]`;
const resultado = { startedAt: new Date().toISOString(), navegador: 'Firefox', tipo: 'CRUD visual', checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { resultado.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function shot(page, name) { const file = path.join(saida, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); resultado.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }
function cell(row, column) { return row.locator(`[data-column="${column}"]`); }
async function login(page) {
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
  await Promise.all([page.waitForURL(/\/principal\/main\.php/, { timeout }), page.locator('form.login-form button[type="submit"]').click()]);
  check('Login realizado', true);
}
async function activeConfirm(page) {
  const button = page.locator('.modal.show [data-confirmar], .modal.show [data-confirm-cadastro], .modal.show [data-confirm]').last();
  await button.waitFor({ state: 'visible', timeout });
  return button;
}
async function save(page, url) {
  const saveButton = page.locator('[data-save-cadastro], [data-crud-action="save"]').last();
  check('Botao Salvar habilitado', await saveButton.isEnabled());
  await saveButton.click({ noWaitAfter: true });
  const confirm = await activeConfirm(page);
  const request = page.waitForRequest(r => r.method() === 'POST' && r.url().split('?')[0] === url && (r.postData() || '').includes('action=save_rows'), { timeout });
  // Dispara o evento DOM do botao ativo; este modal usa um listener jQuery
  // proprio e pode ignorar a interacao sintetica durante a animacao.
  await confirm.evaluate(button => button.click());
  await request;
  await page.waitForLoadState('domcontentloaded', { timeout }).catch(() => {});
  await page.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(500);
  check('Salvamento enviado', true);
}
async function deleteRow(page, url, row) {
  await row.click({ force: true });
  await page.locator('[data-delete-cadastro], [data-crud-action="delete"]').last().click({ noWaitAfter: true });
  const confirm = await activeConfirm(page);
  const request = page.waitForRequest(r => r.method() === 'POST' && r.url().split('?')[0] === url && (r.postData() || '').includes('action=delete_row'), { timeout });
  await confirm.evaluate(button => button.click());
  await request;
  await page.waitForTimeout(700);
}
async function findMarker(grid, value) {
  const rows = grid.locator('tbody tr');
  const count = await rows.count();
  for (let i = 0; i < count; i++) {
    const cellValue = rows.nth(i).locator('[data-column="DSCASSUNTO"]');
    const field = cellValue.locator('input,textarea').first();
    const text = await cellValue.textContent();
    if ((await field.count() && (await field.inputValue()).includes(value)) || String(text || '').includes(value)) return rows.nth(i);
  }
  return null;
}
async function openSearch(page, grid, value) {
  const searchUrl = `${page.url().split('?')[0]}?busca=${encodeURIComponent(value)}&_teste=${Date.now()}`;
  await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout });
  await grid.waitFor({ state: 'visible', timeout });
  await page.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(500);
}
async function main() {
  let browser; let page; let created = false; const url = `${baseUrl}/principal/manutencaolegal_assuntos.php`;
  try {
    browser = await firefox.launch({ headless: !visual });
    page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage(); page.setDefaultTimeout(timeout);
    await login(page); await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
    const grid = page.locator('#manutencaolegal_assuntosBatchGrid'); await grid.waitFor({ state: 'visible', timeout });
    await page.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
    check('Grid de Assuntos carregado', await grid.locator('tbody tr').count() > 0);
    check('Coluna Codigo oculta por padrao', !(await grid.locator('thead th[data-column="CODASSUNTO"]').isVisible()));
    check('Coluna Assunto visivel', await grid.locator('thead th[data-column="DSCASSUNTO"]').isVisible());
    await shot(page, '01-grid-inicial');
    await page.locator('[data-new-cadastro], [data-crud-action="new"]').last().click();
    const newRow = grid.locator('tbody tr[data-key^="novo_"]').last(); await newRow.waitFor({ state: 'visible', timeout });
    check('Novo cria linha', await newRow.isVisible());
    const code = String(900000 + (Date.now() % 99999));
    await cell(newRow, 'CODASSUNTO').locator('input').fill(code);
    await cell(newRow, 'DSCASSUNTO').locator('input,textarea').fill(`${marker} inclusao`);
    await shot(page, '02-novo-preenchido'); await save(page, url); created = true;
    await openSearch(page, grid, `${marker} inclusao`);
    let row = await findMarker(grid, `${marker} inclusao`); check('Inclusao persistida', !!row); await shot(page, '03-inclusao-persistida');
    await cell(row, 'DSCASSUNTO').locator('input,textarea').fill(`${marker} alteracao`); await save(page, url);
    await openSearch(page, grid, `${marker} alteracao`);
    row = await findMarker(grid, `${marker} alteracao`); check('Alteracao persistida', !!row);
    await row.click({ force: true }); await page.locator('[data-duplicate-cadastro], [data-crud-action="duplicate"]').last().click();
    const duplicate = grid.locator('tbody tr[data-key^="novo_"]').last(); await duplicate.waitFor({ state: 'visible', timeout });
    check('Duplicacao cria nova linha', await duplicate.isVisible());
    await cell(duplicate, 'CODASSUNTO').locator('input').fill(String(Number(code) + 1));
    await cell(duplicate, 'DSCASSUNTO').locator('input,textarea').fill(`${marker} duplicacao`); await save(page, url);
    await openSearch(page, grid, `${marker} duplicacao`);
    row = await findMarker(grid, `${marker} duplicacao`); check('Duplicacao persistida', !!row); await deleteRow(page, url, row);
    await openSearch(page, grid, `${marker} duplicacao`);
    check('Exclusao persistida', !(await findMarker(grid, `${marker} duplicacao`)));
    resultado.status = 'APROVADO'; await shot(page, '04-final');
  } catch (error) { resultado.error = error.message; if (page) await shot(page, 'falha').catch(() => {}); throw error; }
  finally { resultado.finishedAt = new Date().toISOString(); fs.writeFileSync(path.join(saida, 'resultado.json'), JSON.stringify(resultado, null, 2), 'utf8'); if (browser) await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

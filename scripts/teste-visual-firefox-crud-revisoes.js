const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const has = name => args.includes(name);
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', 'admin');
const senha = arg('--senha', '');
const visual = has('--visual');
const timeout = Number(arg('--timeout', '30000'));
const output = path.resolve(arg('--saida', 'tests/resultado-visual-firefox-crud-revisoes.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_visual_firefox_crud_revisoes'));
const runMarker = `[VISUAL CRUD ${Date.now()}]`;
const result = { startedAt: new Date().toISOString(), navegador: 'Firefox', tipo: 'CRUD visual', casos: [], status: 'REPROVADO' };

function check(name, ok, details = '') {
  result.checks = result.checks || [];
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
  if (!ok) throw new Error(name + (details ? `: ${details}` : ''));
}
async function shot(page, name) {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const file = path.join(evidenceDir, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  console.log(`EVIDENCIA - ${file}`);
}
async function login(page) {
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario);
  await page.locator('#senha').fill(senha);
  await Promise.all([page.waitForURL(/\/principal\/main\.php/, { timeout }), page.locator('form.login-form button[type="submit"]').click()]);
  check('Login realizado', true);
}
async function save(page, url) {
  const saveButton = page.locator('[data-save-cadastro]');
  check('Botão Salvar habilitado', await saveButton.isEnabled());
  await saveButton.click({ noWaitAfter: true });
  // Seleciona somente o botao do modal ativo; o aplicativo pode manter
  // instancias antigas ocultas no DOM.
  const confirm = page.locator('.modal.show [data-confirmar], .modal.show [data-confirm-cadastro]').last();
  await confirm.waitFor({ state: 'visible', timeout: 10000 });
  await confirm.scrollIntoViewIfNeeded();
  const saveRequest = page.waitForRequest(request => request.method() === 'POST' && request.url().split('?')[0] === url && (request.postData() || '').includes('action=save_rows'), { timeout });
  await confirm.click({ force: true });
  await saveRequest;
  await page.waitForLoadState('domcontentloaded', { timeout }).catch(() => {});
  await page.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  check('Salvar iniciou processamento', true);
  await page.waitForTimeout(300);
}
async function rowWithRevision(grid, marker) {
  const rows = grid.locator('tbody tr');
  const count = await rows.count();
  for (let i = 0; i < count; i++) {
    const field = rows.nth(i).locator('[data-column="DSCREVISAO"] input, [data-column="DSCREVISAO"] textarea').first();
    if (await field.count() && (await field.inputValue()).includes(marker)) return rows.nth(i);
  }
  return rows.nth(-1);
}
async function loadGrid(page, url, grid) {
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
  await grid.waitFor({ state: 'visible', timeout });
  await page.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(500);
}
async function openCase(page, cfg) {
  const url = `${baseUrl}/principal/${cfg.page}`;
  const grid = page.locator(cfg.grid);
  await loadGrid(page, url, grid);
  check(`${cfg.label}: grid carregado`, await grid.isVisible());
  check(`${cfg.label}: Código oculto inicialmente`, !(await grid.locator(`thead th[data-column="${cfg.primary}"]`).isVisible()));
  check(`${cfg.label}: colunas funcionais visíveis`, await grid.locator('thead th[data-column="DSCREVISAO"]').isVisible() && await grid.locator('thead th[data-column="DATA"]').isVisible());
  await shot(page, `${cfg.name}-01-inicial`);
  await page.locator('[data-new-cadastro]').click();
  const newRow = grid.locator('tbody tr[data-key^="novo_"]').first();
  await newRow.waitFor({ state: 'visible', timeout });
  check(`${cfg.label}: Novo cria linha`, await newRow.isVisible());
  check(`${cfg.label}: Código continua oculto após Novo`, !(await newRow.locator(`[data-column="${cfg.primary}"]`).isVisible()));
  await newRow.locator('[data-column="DSCREVISAO"] textarea, [data-column="DSCREVISAO"] input').fill(`${cfg.marker} inclusão`);
  await newRow.locator('[data-column="DATA"] input').fill('2026-08-28');
  await shot(page, `${cfg.name}-02-novo`);
  await save(page, url);
  await loadGrid(page, url, grid);
  const inserted = await rowWithRevision(grid, `${cfg.marker} inclusão`);
  await inserted.waitFor({ state: 'visible', timeout });
  check(`${cfg.label}: inclusão persistida`, (await inserted.locator('[data-column="DSCREVISAO"] input, [data-column="DSCREVISAO"] textarea').first().inputValue()).includes(`${cfg.marker} inclusão`));
  await inserted.click({ force: true });
  await inserted.locator('[data-column="DSCREVISAO"] textarea, [data-column="DSCREVISAO"] input').fill(`${cfg.marker} alteração`);
  await save(page, url);
  await loadGrid(page, url, grid);
  const updated = await rowWithRevision(grid, `${cfg.marker} alteração`);
  await updated.waitFor({ state: 'visible', timeout });
  check(`${cfg.label}: alteração persistida`, (await updated.locator('[data-column="DSCREVISAO"] input, [data-column="DSCREVISAO"] textarea').first().inputValue()).includes(`${cfg.marker} alteração`));
  await updated.click({ force: true });
  await page.locator('[data-duplicate-cadastro]').click();
  const duplicate = grid.locator('tbody tr[data-key^="novo_"]').first();
  await duplicate.waitFor({ state: 'visible', timeout });
  check(`${cfg.label}: duplicação cria nova linha`, await duplicate.isVisible());
  await duplicate.locator('[data-column="DSCREVISAO"] textarea, [data-column="DSCREVISAO"] input').fill(`${cfg.marker} duplicação`);
  await save(page, url);
  await loadGrid(page, url, grid);
  const duplicated = await rowWithRevision(grid, `${cfg.marker} duplicação`);
  await duplicated.waitFor({ state: 'visible', timeout });
  check(`${cfg.label}: duplicação persistida`, (await duplicated.locator('[data-column="DSCREVISAO"] input, [data-column="DSCREVISAO"] textarea').first().inputValue()).includes(`${cfg.marker} duplicação`));
  await duplicated.click({ force: true });
  await page.locator('[data-delete-cadastro]').click();
  const confirm = page.locator('.modal.show [data-confirmar], .modal.show [data-confirm-cadastro]').last();
  await confirm.waitFor({ state: 'visible', timeout });
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }).catch(() => {}), confirm.click()]);
  await loadGrid(page, url, grid);
  const duplicateAfterDelete = await rowWithRevision(grid, `${cfg.marker} duplicação`);
  const duplicateValueAfterDelete = await duplicateAfterDelete.locator('[data-column="DSCREVISAO"] input, [data-column="DSCREVISAO"] textarea').first().inputValue().catch(() => '');
  check(`${cfg.label}: exclusão persistida`, !duplicateValueAfterDelete.includes(`${cfg.marker} duplicação`));
  const original = await rowWithRevision(grid, `${cfg.marker} alteração`);
  const originalValue = await original.locator('[data-column="DSCREVISAO"] input, [data-column="DSCREVISAO"] textarea').first().inputValue().catch(() => '');
  if (originalValue.includes(`${cfg.marker} alteração`)) {
    await original.click({ force: true });
    await page.locator('[data-delete-cadastro]').click();
    await page.locator('.modal.show [data-confirmar], .modal.show [data-confirm-cadastro]').last().click({ force: true });
    await page.waitForTimeout(400);
  }
  await shot(page, `${cfg.name}-03-final`);
  result.casos.push({ name: cfg.name, status: 'APROVADO' });
}

async function main() {
  let browser;
  let page;
  const traffic = [];
  try {
    browser = await firefox.launch({ headless: !visual });
    page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
    page.setDefaultTimeout(timeout);
    page.on('console', message => { if (message.type() === 'error') traffic.push({ type: 'console-error', text: message.text() }); });
    page.on('request', request => { if (request.method() === 'POST') traffic.push({ type: 'request', url: request.url(), postData: (request.postData() || '').slice(0, 4000) }); });
    page.on('response', async response => { if (response.request().method() === 'POST') traffic.push({ type: 'response', url: response.url(), status: response.status(), body: (await response.text().catch(() => '')).slice(0, 2000) }); });
    await login(page);
    await openCase(page, { name: 'laia', label: 'Revisão LAIA', page: 'manutencaolegal_revisaolaia.php', grid: '#manutencaolegal_revisaolaiaBatchGrid', primary: 'CODREVISAO', marker: `${runMarker} LAIA` });
    await openCase(page, { name: 'lpro', label: 'Revisão LPRO', page: 'manutencaolegal_revisaolpro.php', grid: '#manutencaolegal_revisaolproBatchGrid', primary: 'CHAVE', marker: `${runMarker} LPRO` });
    result.status = 'APROVADO';
  } catch (error) {
    result.error = error.message;
    if (page) await shot(page, 'falha').catch(() => {});
    throw error;
  } finally {
    result.traffic = traffic;
    result.finishedAt = new Date().toISOString();
    fs.mkdirSync(path.dirname(output), { recursive: true });
    fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
    if (browser) await browser.close();
  }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

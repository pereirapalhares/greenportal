const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const args = process.argv.slice(2);
function argument(name, fallback = '') {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
}

const baseUrl = argument('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = argument('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = argument('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = argument('--senha', process.env.GREENPORTAL_SENHA || '');
const outputDir = path.resolve(argument('--saida', path.join('tests', 'evidencias_PER010_infraestrutura')));
const visible = args.includes('--visivel');
const timeout = Number(argument('--timeout', '30000'));

if (!usuario || !senha) {
  console.error('Informe --usuario e --senha ou defina GREENPORTAL_USUARIO e GREENPORTAL_SENHA.');
  process.exit(2);
}

fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), baseUrl, checks: [], screenshots: [] };

function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
  if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`);
}

async function screenshot(page, name) {
  const file = path.join(outputDir, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  result.screenshots.push(file);
  console.log(`EVIDENCIA - ${file}`);
}

async function waitForGrid(page, frame) {
  await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
  await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
  await page.waitForTimeout(1200);
  await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
}

async function popupFrame(page) {
  const popup = page.locator('[data-popup-transacao].show iframe');
  await popup.waitFor({ state: 'visible', timeout });
  return page.frameLocator('[data-popup-transacao].show iframe');
}

async function openResponsabilidades(page, gridFrame, code) {
  const row = gridFrame.locator(`table.menu-batch-grid tbody tr[data-key="${code}"]`);
  await row.waitFor({ state: 'visible', timeout });
  await row.click();
  const button = gridFrame.locator('[data-context-child][data-context-id="responsabilidades"]');
  await button.waitFor({ state: 'visible', timeout });
  check(`Botão Responsabilidades habilitado para infraestrutura ${code}`, await button.isEnabled());
  await button.click();
  return popupFrame(page);
}

async function main() {
  const browser = await firefox.launch({ headless: !visible });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(timeout);

  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    check('Tela de login carregada', await page.locator('#userid').isVisible());
    await page.locator('#empresa').selectOption({ label: empresa }).catch(async () => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario);
    await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/, { timeout });
    check('Login realizado', /\/principal\/main\.php/.test(page.url()), page.url());
    await screenshot(page, '01-main-login');

    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Infraestrutura');
    const menuItem = page.locator('button[data-open-tab][data-tab-url*="modulolegalpro_infraestrutura.php"]');
    await menuItem.waitFor({ state: 'visible', timeout });
    check('Menu LPRO - Infraestrutura disponível', await menuItem.count() === 1);
    await menuItem.click();

    const gridSelector = 'iframe[src*="modulolegalpro_infraestrutura.php"]';
    await page.locator(gridSelector).waitFor({ state: 'attached', timeout });
    const gridFrame = page.frameLocator(gridSelector);
    await waitForGrid(page, gridFrame);
    const grid = gridFrame.locator('table.menu-batch-grid');
    check('Grid LPRO - Infraestrutura carregado', await grid.count() === 1);
    check('Infraestrutura 1 disponível', await grid.locator('tbody tr[data-key="1"]').count() === 1);
    check('Botão Responsabilidades inicia desabilitado', await gridFrame.locator('[data-context-child][data-context-id="responsabilidades"]:disabled').count() >= 1);
    await screenshot(page, '02-grid-infraestrutura');

    let frame = await openResponsabilidades(page, gridFrame, 1);
    check('Consulta identifica infraestrutura 1', /Infraestrutura 1/.test(await frame.locator('body').innerText()));
    check('Título da consulta correto', /Responsabilidades da infraestrutura/i.test(await frame.locator('h4').innerText()));
    check('Consulta sem SQL exposto', !/SQLSTATE|syntax error|mysql|PDOException/i.test(await frame.locator('body').innerText()));
    await screenshot(page, '03-consulta-infraestrutura-1');

    const manter = frame.locator('a[data-testid="toggle-manter"]');
    await manter.click();
    frame = page.frameLocator('[data-popup-transacao].show iframe');
    await frame.locator('#infraestruturaResponsibilityTable').waitFor({ state: 'visible', timeout });
    check('Manutenção de infraestrutura aberta', /Manter responsabilidades da infraestrutura/i.test(await frame.locator('h4').innerText()));
    check('Destino limitado a usuário', await frame.locator('select[name*="CODUSUARIO"]').count() >= 1 && await frame.locator('select[name*="CODGRUPO"]').count() === 0);
    check('Lista de usuários ativos carregada', await frame.locator('select[name*="CODUSUARIO"] option[value]:not([value=""])').count() > 0);

    await frame.locator('#addInfraestruturaResponsibility').click();
    const newRow = frame.locator('#infraestruturaResponsibilityTable tbody tr').filter({ has: frame.locator('select[name*="CODUSUARIO"]') }).last();
    await newRow.locator('select[name*="CODUSUARIO"]').selectOption({ index: 1 });
    await newRow.locator('input[name*="PRIORIDADE"]').fill('10');
    await frame.locator('form button[type="submit"]').click();
    await frame.locator('.alert-success').waitFor({ state: 'visible', timeout });
    check('Inclusão por usuário concluída', /Responsabilidades salvas com sucesso/i.test(await frame.locator('.alert-success').innerText()));
    await screenshot(page, '04-inclusao-infraestrutura');

    const firstRow = frame.locator('#infraestruturaResponsibilityTable tbody tr[data-row-key]').first();
    await firstRow.locator('input[name*="PRIORIDADE"]').fill('99');
    await frame.locator('form button[type="submit"]').click();
    await frame.locator('.alert-success').waitFor({ state: 'visible', timeout });
    check('Edição de prioridade concluída', /Responsabilidades salvas com sucesso/i.test(await frame.locator('.alert-success').innerText()));
    await screenshot(page, '05-edicao-infraestrutura');

    const deleteRow = frame.locator('#infraestruturaResponsibilityTable tbody tr[data-row-key]').first();
    await deleteRow.locator('.js-delete-row').click();
    await frame.locator('form button[type="submit"]').click();
    await frame.locator('.alert-success').waitFor({ state: 'visible', timeout });
    check('Exclusão lógica concluída', /Responsabilidades salvas com sucesso/i.test(await frame.locator('.alert-success').innerText()));
    await screenshot(page, '06-exclusao-logica-infraestrutura');

    await page.locator('[data-popup-transacao].show button.btn-close').click();
    await page.locator('[data-popup-transacao].show').waitFor({ state: 'hidden', timeout });
    await waitForGrid(page, gridFrame);
    frame = await openResponsabilidades(page, gridFrame, 5);
    check('Troca de contexto identifica infraestrutura 5', /Infraestrutura 5/.test(await frame.locator('body').innerText()));
    check('Vínculo da infraestrutura 1 não vazou para infraestrutura 5', !/Euclid|UEditor|99/.test(await frame.locator('body').innerText()));
    await screenshot(page, '07-troca-contexto-infraestrutura-5');

    result.finishedAt = new Date().toISOString();
    result.status = 'APROVADO';
  } catch (error) {
    result.finishedAt = new Date().toISOString();
    result.status = 'REPROVADO';
    result.error = error.message;
    try { await screenshot(page, 'falha'); } catch (_) {}
    throw error;
  } finally {
    fs.writeFileSync(path.join(outputDir, 'resultado.json'), JSON.stringify(result, null, 2), 'utf8');
    await browser.close();
  }
}

main().catch(error => {
  console.error(`ERRO FATAL: ${error.stack || error.message || error}`);
  process.exit(1);
});

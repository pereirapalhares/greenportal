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
const outputDir = path.resolve(argument('--saida', path.join('tests', 'evidencias_reteste_filtro_nome_funcionarios')));
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

async function rowNames(frame) {
  return frame.locator('table.menu-batch-grid tbody tr').evaluateAll(rows => rows.map(row => {
    const cell = row.querySelector('td[data-column="NOME"]');
    const field = cell?.querySelector('input, select, textarea');
    const value = field instanceof HTMLSelectElement
      ? field.selectedOptions?.[0]?.textContent || field.value || ''
      : field?.value || cell?.innerText || '';
    return value.replace(/\s+/g, ' ').trim();
  }).filter(Boolean));
}

async function main() {
  const browser = await firefox.launch({ headless: !visible });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(timeout);

  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    check('Tela de login carregada', await page.locator('#userid').isVisible());
    await page.locator('#empresa').selectOption({ label: empresa }).catch(async () => {
      await page.locator('#empresa').selectOption({ value: empresa });
    });
    await page.locator('#userid').fill(usuario);
    await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/, { timeout });
    check('Login realizado', /\/principal\/main\.php/.test(page.url()), page.url());
    await screenshot(page, '01-main-login');

    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    const menuSearch = page.locator('#menuSearch');
    await menuSearch.fill('Gestão de Funcionários');
    const menuItem = page.locator('button[data-open-tab][data-tab-url*="homologacaofornecedor_funcionario.php"]');
    await menuItem.waitFor({ state: 'visible' });
    check('Item Gestão de Funcionários disponível', await menuItem.count() === 1);
    await screenshot(page, '02-menu-gestao-funcionarios');
    await menuItem.click();

    const frameSelector = 'iframe[src*="homologacaofornecedor_funcionario.php"]';
    await page.locator(frameSelector).waitFor({ state: 'attached' });
    const frame = page.frameLocator(frameSelector);
    await waitForGrid(page, frame);
    const grid = frame.locator('table.menu-batch-grid');
    check('Grade Gestão de Funcionários carregada', await grid.count() === 1);
    const initialRows = await grid.locator('tbody tr').count();
    check('Grade possui registros iniciais', initialRows > 0, `${initialRows} linhas`);
    check('Filtro Nome disponível', await frame.locator('[data-column-filter="NOME"]').count() === 1);
    check('Cabeçalho da grade visível', await grid.locator('thead').isVisible());
    const initialNames = await rowNames(frame);
    check('Grade possui nomes para testar', initialNames.length > 0, `${initialNames.length} nomes`);
    await screenshot(page, '03-grid-inicial');

    const sourceName = initialNames.find(name => name.length >= 3) || initialNames[0];
    const token = sourceName.trim().split(/\s+/)[0].slice(0, Math.max(3, Math.min(6, sourceName.length)));
    const nameFilter = frame.locator('[data-column-filter="NOME"]');
    await nameFilter.fill(token);
    await waitForGrid(page, frame);
    const filteredNames = await rowNames(frame);
    check('F-001 filtro Nome retorna correspondências', filteredNames.length > 0, `${filteredNames.length} linhas para "${token}"`);
    check('F-001 todas as linhas correspondem ao filtro', filteredNames.every(name => name.toLocaleLowerCase().includes(token.toLocaleLowerCase())), filteredNames.join(' | '));
    result.filterToken = token;
    result.filteredNames = filteredNames;
    await screenshot(page, '04-filtro-nome-aplicado');

    const impossible = `TESTE_NOME_INEXISTENTE_${Date.now()}`;
    await nameFilter.fill(impossible);
    await waitForGrid(page, frame);
    const emptyNames = await rowNames(frame);
    check('F-002 filtro sem correspondência não exibe nomes', emptyNames.length === 0, emptyNames.join(' | '));
    await screenshot(page, '05-filtro-sem-correspondencia');

    await nameFilter.fill('');
    await waitForGrid(page, frame);
    const restoredRows = await grid.locator('tbody tr').count();
    check('F-003 limpar filtro restaura registros', restoredRows > 0, `${restoredRows} linhas`);
    check('F-003 status da paginação concluído', !/Calculando total/i.test(await frame.locator('.grid-page-status').innerText()));
    await screenshot(page, '06-filtro-limpo');

    const search = frame.locator('.crud-search input');
    await nameFilter.fill(token);
    await waitForGrid(page, frame);
    await search.fill(token);
    await waitForGrid(page, frame);
    const combinedNames = await rowNames(frame);
    check('F-004 combinação filtro Nome e busca geral preserva resultado', combinedNames.length > 0, `${combinedNames.length} linhas`);
    check('F-004 combinação mantém correspondência pelo nome', combinedNames.every(name => name.toLocaleLowerCase().includes(token.toLocaleLowerCase())), combinedNames.join(' | '));
    await screenshot(page, '07-filtros-combinados');

    await search.fill('');
    await nameFilter.fill('');
    await waitForGrid(page, frame);
    const finalRows = await grid.locator('tbody tr').count();
    check('F-005 filtros limpos sem regressão na grade', finalRows > 0, `${finalRows} linhas`);
    check('F-005 botão contextual inicia desabilitado sem seleção', await frame.locator('[data-context-child]:disabled').count() >= 1);
    await screenshot(page, '08-estado-final');

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

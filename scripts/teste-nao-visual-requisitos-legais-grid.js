const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
function arg(name, fallback = '') {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
}

const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const timeout = Number(arg('--timeout', '30000'));
const output = arg('--saida', 'tests/resultado-nao-visual-requisitos-legais-grid.json');
const visual = args.includes('--visual');
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_requisitos_legais_grid'));

if (!usuario || !senha) {
  console.error('Informe --usuario e --senha ou use GREENPORTAL_USUARIO/GREENPORTAL_SENHA.');
  process.exit(2);
}

const result = { startedAt: new Date().toISOString(), baseUrl, visual, checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
  if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`);
}
function wait(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
async function screenshot(page, name) {
  if (!visual) return;
  fs.mkdirSync(evidenceDir, { recursive: true });
  const file = path.join(evidenceDir, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  result.screenshots.push(file);
  console.log(`EVIDÊNCIA - ${file}`);
}
async function waitForGridSettled(page, frame) {
  // The shared grid component uses a one-second debounce for filters/search.
  await wait(1100);
  // grid-loading.js removes grid-ready while the AJAX refresh is active and
  // adds it only after the rows and pagination state have been updated.
  await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
  const deadline = Date.now() + timeout;
  let stableSince = null;
  while (Date.now() < deadline) {
    const frameLoading = await frame.locator('.grid-loading-modal.show').count();
    const pageLoading = await page.locator('.grid-loading-modal.show').count();
    const gridReady = await frame.locator('.menu-grid-fragment.grid-ready').count() > 0;
    if (frameLoading === 0 && pageLoading === 0 && gridReady) {
      if (stableSince === null) stableSince = Date.now();
      if (Date.now() - stableSince >= 750) break;
    } else {
      stableSince = null;
    }
    await wait(250);
  }
  const frameLoading = await frame.locator('.grid-loading-modal.show').count();
  const pageLoading = await page.locator('.grid-loading-modal.show').count();
  check('Overlay de carregamento foi encerrado', frameLoading === 0 && pageLoading === 0, `iframe=${frameLoading}, principal=${pageLoading}`);
  await wait(350);
}

const expectedVisibleColumns = [
  'UNIDADE', 'PADRAO', 'PODER', 'ATENDIDO', 'DSCAMBITO', 'CODIGO', 'DSCTIPO',
  'NR', 'ANO', 'DSCASSUNTO', 'ARTIGOS', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'
];
const technicalColumns = ['CODLEGISLACAO', 'CODLEGISLACAXEMPRESA', 'CODUNIDADE', 'CODTIPO', 'CODAMBITO', 'CODEMPRESA'];
const allowedVisibleColumns = expectedVisibleColumns;

async function main() {
  const browser = await chromium.launch(visual ? { headless: false, channel: 'chrome' } : { headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario);
    await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/, { timeout });
    check('Login realizado', /\/principal\/main\.php/.test(page.url()), page.url());
    await screenshot(page, '01-main');

    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Requisitos legais');
    const menuItem = page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]');
    await menuItem.waitFor({ state: 'visible' });
    await menuItem.click();
    await screenshot(page, '02-menu-requisitos-legais');
    const iframe = page.locator('iframe[src*="modulolegal_requisitoslegais.php"]');
    await iframe.waitFor({ state: 'attached' });
    const frame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
    const grid = frame.locator('#requisitosLegaisBatchGrid');
    await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
    await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
    await wait(1200);

    const columns = await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => String(th.dataset.column || '')));
    const allColumns = await grid.locator('thead th').evaluateAll(ths => ths.map(th => String(th.dataset.column || '')));
    check('Colunas esperadas estão disponíveis no grid', expectedVisibleColumns.every(column => allColumns.includes(column)), allColumns.join(', '));
    check('Coluna legada linha não é exibida', !allColumns.includes('linha'), allColumns.join(', '));
    check('Colunas visíveis não estão duplicadas', new Set(columns).size === columns.length, columns.join(', '));
    check('Somente as colunas funcionais solicitadas estão visíveis', columns.every(column => allowedVisibleColumns.includes(column)), columns.join(', '));
    const hiddenTechnical = await grid.locator('thead th').evaluateAll((ths, technical) => ths.filter(th => technical.includes(th.dataset.column) && getComputedStyle(th).display !== 'none').map(th => th.dataset.column), technicalColumns);
    check('Colunas técnicas permanecem ocultas', hiddenTechnical.length === 0, hiddenTechnical.join(', '));
    check('Grid possui no máximo 25 linhas', await grid.locator('tbody tr').count() <= 25, String(await grid.locator('tbody tr').count()));
    const initialOrder = columns.join('|');
    await screenshot(page, '03-grid-inicial');

    const filterKinds = await frame.locator('[data-column-filter]').evaluateAll(filters => filters.map(el => ({ column: el.dataset.columnFilter, kind: el.tagName.toLowerCase() })));
    for (const column of ['UNIDADE', 'PODER', 'ATENDIDO', 'DSCAMBITO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE']) {
      check(`Filtro ${column} é combobox`, filterKinds.some(f => f.column === column && f.kind === 'select'), JSON.stringify(filterKinds));
    }
    for (const column of ['CODIGO', 'DSCTIPO', 'NR', 'ANO', 'DSCASSUNTO', 'ARTIGOS']) {
      check(`Filtro ${column} é textual`, filterKinds.some(f => f.column === column && f.kind === 'input'), JSON.stringify(filterKinds));
    }
    check('Filtro Lei revogada inicia como Não', await frame.locator('#requisitosLeiRevogada').inputValue() === 'NAO');
    check('Filtro Responsável está disponível', await frame.locator('#requisitosResponsavel').count() === 1);

    const checkBodyColumnAlignment = async label => {
      const alignment = await grid.locator('tbody tr').first().evaluate(row => {
        const headers = Array.from(row.closest('table').querySelectorAll('thead th')).map(th => String(th.dataset.column || ''));
        const cells = Array.from(row.querySelectorAll('td')).map(td => String(td.dataset.column || ''));
        return { headers, cells, same: headers.length === cells.length && headers.every((column, index) => column === cells[index]) };
      }).catch(() => ({ headers: [], cells: [], same: false }));
      check(`${label} mantém os valores alinhados às colunas`, alignment.same, `cabeçalho=${alignment.headers.join(',')} | células=${alignment.cells.join(',')}`);
    };
    const unidadeFilter = frame.locator('[data-column-filter="UNIDADE"]');
    if (await unidadeFilter.locator('option').count() > 1) {
      await unidadeFilter.selectOption({ index: 1 });
      await waitForGridSettled(page, frame);
      check('Filtro Unidade reinicia para página 1', /P[aá]gina\s+1/i.test(await frame.locator('.grid-page-status').innerText()));
      await checkBodyColumnAlignment('Filtro Unidade');
      await unidadeFilter.selectOption('');
      await waitForGridSettled(page, frame);
    } else {
      check('Filtro Unidade possui opções para teste', false);
    }

    const rowCodes = async () => frame.locator('#requisitosLegaisBatchGrid tbody tr').evaluateAll(rows => rows.map(row => String(row.querySelector('[data-column="CODIGO"]')?.textContent || '').trim()).filter(Boolean));
    const option = frame.locator('[data-column-filter="ATENDIDO"] option').filter({ hasText: 'Sim' }).first();
    if (await option.count()) {
      await frame.locator('[data-column-filter="ATENDIDO"]').selectOption({ label: 'Sim' });
      await waitForGridSettled(page, frame);
      const attendedStatus = await frame.locator('.grid-page-status').innerText();
      check('Filtro Atendido retorna na página 1', /P[aá]gina\s+1/i.test(attendedStatus), attendedStatus);
      check('Aplicar filtro preserva a ordem personalizada das colunas', (await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column))).join('|') === initialOrder);
      const filtered = await grid.locator('tbody tr').count();
      check('Filtro Atendido não quebra o grid', filtered >= 0 && filtered <= 25, `${filtered} linhas`);
      await screenshot(page, '04-filtro-atendido');
    } else {
      check('Existe opção Sim no filtro Atendido', false);
    }

    const textFilter = frame.locator('[data-column-filter="CODIGO"]');
    const firstCode = (await rowCodes())[0];
    check('Grid disponibiliza valor para teste textual', Boolean(firstCode), firstCode);
    await textFilter.fill(firstCode);
    await waitForGridSettled(page, frame);
    const textRows = await rowCodes();
    check('Filtro textual retorna correspondências', textRows.length > 0 && textRows.every(code => code.toLowerCase().includes(firstCode.toLowerCase())), textRows.join(', '));
    const textStatus = await frame.locator('.grid-page-status').innerText();
    check('Filtro textual reinicia para página 1', /P[aá]gina\s+1/i.test(textStatus), textStatus);
    check('Filtro textual preserva a ordem personalizada das colunas', (await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column))).join('|') === initialOrder);
    await screenshot(page, '05-filtro-codigo');

    await textFilter.fill(`VALOR_INEXISTENTE_${Date.now()}`);
    await waitForGridSettled(page, frame);
    check('Filtro sem correspondência preserva a ordem personalizada', (await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column))).join('|') === initialOrder);
    check('Filtro sem correspondência mantém paginação na página 1', (await frame.locator('.grid-page-status').innerText()).includes('Página 1'));
    await screenshot(page, '06-filtro-sem-resultado');

    await textFilter.fill('');
    await frame.locator('[data-column-filter="ATENDIDO"]').selectOption('');
    await waitForGridSettled(page, frame);
    check('Limpar filtros restaura o grid', await grid.locator('tbody tr').count() > 0);
    await screenshot(page, '07-grid-restaurado');

    const next = frame.locator('.grid-page-next');
    if (!(await next.isDisabled())) {
      const requests = [];
      const listener = request => { if (/pagina=2/.test(request.url())) requests.push(request.url()); };
      page.on('request', listener);
      await next.click();
      await next.click().catch(() => {});
      await next.click().catch(() => {});
      await waitForGridSettled(page, frame);
      page.off('request', listener);
      check('Paginação bloqueia requisições concorrentes', new Set(requests).size <= 1, `${requests.length} requisição(ões) para página 2`);
      check('Paginação preserva a ordem personalizada das colunas', (await grid.locator('thead th:visible').evaluateAll(ths => ths.map(th => th.dataset.column))).join('|') === initialOrder);
    } else {
      check('Grid possui uma segunda página para testar', false, 'A base filtrada não permite testar a concorrência da paginação');
    }

    result.status = 'APROVADO';
  } catch (error) {
    result.error = error.message;
    try { await screenshot(page, 'falha'); } catch (_) {}
    throw error;
  } finally {
    result.finishedAt = new Date().toISOString();
    const resultOutput = visual ? path.join(evidenceDir, 'resultado.json') : output;
    fs.writeFileSync(resultOutput, JSON.stringify(result, null, 2), 'utf8');
    await browser.close();
  }
}

main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

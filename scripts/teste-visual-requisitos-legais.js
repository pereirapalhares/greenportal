const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const args = process.argv.slice(2);
function argument(name, fallback = '') {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
}

const baseUrl = argument('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = argument('--empresa', 'Modelo');
const usuario = argument('--usuario', '');
const senha = argument('--senha', '');
const outputDir = path.resolve(argument('--saida', path.join('tests', 'evidencias_requisitos_legais')));
const visible = args.includes('--visivel');
const expectNoAccess = args.includes('--expect-no-access');
const timeout = Number(argument('--timeout', '30000'));

if (!usuario || !senha) {
  console.error('Informe --usuario e --senha.');
  process.exit(2);
}

fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), baseUrl, checks: [], screenshots: [] };

function normalizeText(value) {
  const text = String(value ?? '');
  if (!/[ÃÂâ�]/.test(text)) return text;
  try {
    const decoded = Buffer.from(text, 'latin1').toString('utf8');
    return decoded.includes('�') ? text : decoded;
  } catch (_) {
    return text;
  }
}

function check(name, ok, details = '') {
  const normalizedName = normalizeText(name);
  const normalizedDetails = normalizeText(details);
  result.checks.push({ name: normalizedName, ok: Boolean(ok), details: normalizedDetails });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${normalizedName}${normalizedDetails ? `: ${normalizedDetails}` : ''}`);
  if (!ok) throw new Error(`${normalizedName}${normalizedDetails ? `: ${normalizedDetails}` : ''}`);
}

async function screenshot(page, name) {
  const file = path.join(outputDir, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  result.screenshots.push(file);
  console.log(`EVIDENCIA - ${file}`);
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

    const menuSearch = page.locator('#menuSearch');
    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await menuSearch.fill('Requisitos legais');
    const menuItem = page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]');
    if (expectNoAccess) {
      await page.waitForTimeout(1000);
      check('Menu Requisitos Legais não disponível sem permissão', await menuItem.count() === 0);
      await screenshot(page, '02-menu-requisitos-legais-bloqueado');
      result.finishedAt = new Date().toISOString();
      result.status = 'APROVADO';
      return;
    }
    await menuItem.waitFor({ state: 'visible' });
    check('Item de menu Requisitos Legais disponível', await menuItem.count() === 1);
    await screenshot(page, '02-menu-requisitos-legais');
    await menuItem.click();

    const contentFrameLocator = page.locator('iframe[src*="modulolegal_requisitoslegais.php"]');
    await contentFrameLocator.waitFor({ state: 'attached' });
    const frame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
    await frame.locator('.modulo-legal-requisitos-fragment').waitFor({ state: 'visible' });
    await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
    await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
    await page.waitForTimeout(1000);
    await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
    check('Grid de Requisitos Legais carregado', true);

    const rows = await frame.locator('#requisitosLegaisBatchGrid tbody tr').count();
    check('Registros de requisitos legais renderizados', rows > 0, `${rows} linhas`);
    const columns = await frame.locator('#requisitosLegaisBatchGrid thead th').count();
    check('Colunas do grid renderizadas', columns >= 15, `${columns} colunas`);
    const status = await frame.locator('.grid-page-status').innerText();
    check('Paginação sem carregamento pendente', !/Calculando total/i.test(status), status);

    check('Filtro de responsável disponível', await frame.locator('#requisitosResponsavel').count() === 1);
    check('Filtro de lei revogada disponível', await frame.locator('#requisitosLeiRevogada').count() === 1);
    check('Lei revogada inicia como não', await frame.locator('#requisitosLeiRevogada').inputValue() === 'NAO');
    check('Filtro de responsável possui opções', await frame.locator('#requisitosResponsavel option').count() > 1);
    check('Filtros por coluna disponíveis', await frame.locator('[data-column-filter]').count() >= 5);

    check('Botão de confirmação disponível', await frame.locator('[data-confirm-requisitos]').count() === 1);
    check('Ações contextuais disponíveis', await frame.locator('[data-open-requisito-popup]').count() === 6);
    check('Ações contextuais iniciam desabilitadas', await frame.locator('[data-open-requisito-popup]:disabled').count() === 6);
    check('Botão de recarregar disponível', await frame.locator('[data-reload-grid]').count() === 1);
    check('Botão de exportação disponível', await frame.locator('[data-export-requisitos]').count() === 1);
    check('Botão de ajuda disponível', await frame.locator('[data-open-transacao-ajuda]').count() === 1);

    const firstRow = frame.locator('#requisitosLegaisBatchGrid tbody tr').first();
    await firstRow.click();
    check('Seleção de requisito habilita ações contextuais', await frame.locator('[data-open-requisito-popup]:not(:disabled)').count() === 6);
    check('Botão de leitura do padrão instalado', await frame.locator('[data-open-padrao]').count() > 0);
    check('Botão de texto da lei instalado', await frame.locator('[data-open-legislacao-pdf]').count() > 0);

    await frame.locator('[data-open-padrao]').first().click();
    const standardModal = frame.locator('#requisitosPadraoModal');
    await standardModal.waitFor({ state: 'visible' });
    check('Modal de leitura do padrão abre', true);
    await standardModal.locator('[data-bs-dismiss="modal"]').first().click();
    await standardModal.waitFor({ state: 'hidden' });
    await page.waitForTimeout(500);
    await frame.locator('.modal-backdrop').waitFor({ state: 'hidden', timeout });
    check('Modal de leitura fechado sem sobreposição residual', await frame.locator('.modal.show').count() === 0 && await frame.locator('.modal-backdrop.show').count() === 0);

    const contextScreens = [
      ['situacao', 'manutencaolegalrequisitoslegais_situacao.php'],
      ['licencas', 'manutencaolegalrequisitoslegais_licencas.php'],
      ['controles', 'manutencaolegalrequisitoslegais_controles.php'],
      ['medicaomonitoramento', 'manutencaolegalrequisitoslegais_medicaomonitoramento.php'],
      ['checklist', 'manutencaolegalrequisitoslegais_checklist.php'],
      ['alteracoes', 'manutencaolegalrequisitoslegais_alteracoes.php']
    ];
    for (const [tipo, pagina] of contextScreens) {
      await frame.locator(`[data-open-requisito-popup="${tipo}"]`).click();
      const popup = page.locator('[data-popup-transacao].show');
      await popup.waitFor({ state: 'visible' });
      const popupFrame = page.frameLocator('[data-popup-transacao].show iframe');
      await popupFrame.locator('body').waitFor({ state: 'visible' });
      const popupGrid = popupFrame.locator('.menu-grid-fragment');
      if (await popupGrid.count()) {
        await popupFrame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout }).catch(() => {});
        await popupFrame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
        await page.waitForTimeout(700);
        await popupFrame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
      }
      const popupUrl = await popupFrame.locator('body').evaluate(() => location.href);
      const popupBody = await popupFrame.locator('body').innerText();
      check(`Popup ${tipo} sem erro de aplicação`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack|Tabela ou view não autorizada)/i.test(popupBody), popupBody.slice(0, 240));
      check(`Popup ${tipo} aberto`, true, popupUrl);
      check(`Destino ${tipo} validado`, popupUrl.includes(pagina), popupUrl);
      await screenshot(page, `04-popup-${tipo}`);
      await popup.locator('.btn-close').click();
      await popup.waitFor({ state: 'hidden' });
      await page.waitForTimeout(500);
      const visibleTransactionPopups = await page.locator('[data-popup-transacao].show:visible').count();
      const visibleBackdrops = await page.locator('.modal-backdrop.show:visible').count();
      check(`Popup ${tipo} fechado sem sobreposição`, visibleTransactionPopups === 0 && visibleBackdrops === 0,
        `popups=${visibleTransactionPopups}, backdrops=${visibleBackdrops}`);
    }

    await page.waitForTimeout(500);
    check('Grid principal visível após fechar os popups', await contentFrameLocator.isVisible());
    const rowCodes = await frame.locator('#requisitosLegaisBatchGrid tbody tr').evaluateAll((tableRows) => tableRows.map((row, index) => ({
      index,
      code: String(row.querySelector('[data-column="CODLEGISLACAO"]')?.textContent || '').trim()
    })).filter((item) => item.code));
    const firstCode = rowCodes[0]?.code || '';
    const secondRequirement = rowCodes.find((item) => item.code !== firstCode);
    check('Dois requisitos distintos disponíveis para isolamento', Boolean(firstCode && secondRequirement),
      rowCodes.map((item) => item.code).slice(0, 5).join(', '));

    await frame.locator('#requisitosLegaisBatchGrid tbody tr').nth(secondRequirement.index).click();
    const secondCode = String(await frame.locator('#requisitosLegaisBatchGrid tbody tr').nth(secondRequirement.index)
      .locator('[data-column="CODLEGISLACAO"]').innerText()).trim();
    check('Segundo requisito selecionado com código distinto', secondCode !== firstCode, `${firstCode} -> ${secondCode}`);
    await frame.locator('[data-open-requisito-popup="alteracoes"]').click();
    const secondPopup = page.locator('[data-popup-transacao].show');
    await secondPopup.waitFor({ state: 'visible' });
    const secondPopupFrame = page.frameLocator('[data-popup-transacao].show iframe');
    await secondPopupFrame.locator('body').waitFor({ state: 'visible' });
    await secondPopupFrame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout }).catch(() => {});
    await secondPopupFrame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
    await page.waitForTimeout(700);
    const secondPopupUrl = await secondPopupFrame.locator('body').evaluate(() => location.href);
    const secondAlterationCodes = await secondPopupFrame.locator('tbody td[data-column="CODLEGISLACAO"]').evaluateAll((cells) => cells
      .map((cell) => String(cell.querySelector('input,select,textarea')?.value || cell.textContent || '').trim()).filter(Boolean));
    check('Alterações do segundo requisito usam o código selecionado', secondPopupUrl.includes(`CODLEGISLACAO=${encodeURIComponent(secondCode)}`), secondPopupUrl);
    check('Alterações não misturam requisitos', secondAlterationCodes.every((code) => code === secondCode),
      secondAlterationCodes.join(', ') || 'Nenhum registro');
    await screenshot(page, '05-popup-alteracoes-segundo-requisito');
    await secondPopup.locator('.btn-close').click();
    await secondPopup.waitFor({ state: 'hidden' });
    await page.waitForTimeout(500);
    check('Popup do segundo requisito fechado sem sobreposição',
      await page.locator('[data-popup-transacao].show:visible').count() === 0 && await page.locator('.modal-backdrop.show:visible').count() === 0);

    await screenshot(page, '03-grid-requisitos-legais');
    result.rows = rows;
    result.columns = columns;
    result.pagination = status;
    result.finishedAt = new Date().toISOString();
    result.status = 'APROVADO';
  } catch (error) {
    result.finishedAt = new Date().toISOString();
    result.status = 'REPROVADO';
    result.error = normalizeText(error.message);
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

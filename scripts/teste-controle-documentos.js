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
const visual = args.includes('--visual');
const mutar = args.includes('--mutar');
const saida = path.resolve(arg('--saida', visual ? 'tests/evidencias_controle_documentos' : 'tests/resultado-nao-visual-controle-documentos.json'));
const evidencias = visual ? saida : path.dirname(saida);
const relacionamentos = ['unidades', 'atividades', 'aspectos', 'impactos', 'perigos', 'riscos', 'palavras_chaves', 'aspectos_sociais', 'impactos_sociais'];
const colunasRelacionamentos = ['UNIDADES', 'ATIVIDADES', 'ASPECTOS', 'IMPACTOS', 'PERIGOS', 'RISCOS', 'PALAVRAS_CHAVES', 'ASPECTOS_SOCIAIS', 'IMPACTOS_SOCIAIS', 'RESPONSAVEIS'];

if (!usuario || !senha) {
  console.error('Informe --usuario e --senha ou use GREENPORTAL_USUARIO/GREENPORTAL_SENHA.');
  process.exit(2);
}

fs.mkdirSync(visual ? evidencias : path.dirname(saida), { recursive: true });
const resultado = { startedAt: new Date().toISOString(), baseUrl, visual, mutar, checks: [], screenshots: [], status: 'REPROVADO' };
function check(nome, ok, detalhes = '') {
  resultado.checks.push({ nome, ok: Boolean(ok), detalhes: String(detalhes) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${nome}${detalhes ? `: ${detalhes}` : ''}`);
  if (!ok) throw new Error(`${nome}${detalhes ? `: ${detalhes}` : ''}`);
}
async function screenshot(page, nome) {
  if (!visual) return;
  const arquivo = path.join(evidencias, `${nome}.png`);
  await page.screenshot({ path: arquivo, fullPage: true });
  resultado.screenshots.push(arquivo);
  console.log(`EVIDÊNCIA - ${arquivo}`);
}
async function aguardarGrid(page, frame) {
  await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
  await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {});
  await page.waitForTimeout(1000);
}

async function main() {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
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
    await page.locator('#menuSearch').fill('Controle');
    const item = page.locator('button[data-open-tab][data-tab-url*="modulolegal_controle.php"]').first();
    await item.waitFor({ state: 'visible' });
    await item.click();
    const iframe = page.locator('iframe[src*="modulolegal_controle.php"]');
    await iframe.waitFor({ state: 'attached' });
    const frame = page.frameLocator('iframe[src*="modulolegal_controle.php"]');
    await aguardarGrid(page, frame);
    const grid = frame.locator('#modulolegal_controleBatchGrid');
    check('Grid Controle carregado', await grid.count() === 1);
    check('Grid possui documentos', await grid.locator('tbody tr').count() > 0, `${await grid.locator('tbody tr').count()} linha(s)`);
    await screenshot(page, '02-grid-controle');

    const row = grid.locator('tbody tr').first();
    const documentId = Number(await row.getAttribute('data-key'));
    check('Linha possui CODDOCUMENTO válido', documentId > 0, String(documentId));
    check('Barra de ações possui os botões de relacionamento', await frame.locator('.crud-toolbar [data-control-relacionamento]').count() === 9);
    check('Botões de relacionamento não ficam dentro das linhas', await row.locator('[data-control-relacionamento]').count() === 0);
    const buttons = frame.locator('.crud-toolbar [data-control-relacionamento]');
    const buttonKeys = await buttons.evaluateAll(items => items.map(item => String(item.dataset.controlRelacionamento || '')));
    check('Grid possui os nove botões de relacionamento', JSON.stringify(buttonKeys) === JSON.stringify(relacionamentos), buttonKeys.join(', '));
    check('Barra possui o botão de Responsáveis', await frame.locator('.crud-toolbar [data-control-responsabilidades]').count() === 1);
    check('Botões de relacionamento iniciam desabilitados', await buttons.evaluateAll(items => items.every(item => item.disabled)));
    const visibleSummaryColumns = await row.locator('td[data-column]').evaluateAll(cells => cells.map(cell => String(cell.dataset.column || '')).filter(column => column && column !== 'CODDOCUMENTO'));
    check('Grid apresenta colunas de resumo dos relacionamentos', colunasRelacionamentos.every(column => visibleSummaryColumns.includes(column)), visibleSummaryColumns.join(', '));
    check('Coluna Arquivos apresenta ícone GED com quantidade', await row.locator('[data-ged-file-button][data-file-count], [data-ged-file-button] [data-file-count]').count() === 1);
    await row.click();
    check('Botões de relacionamento iniciam desabilitados e habilitam com seleção', await buttons.evaluateAll(items => items.every(item => !item.disabled)));

    const mutationTargets = {};
    let relationForMutation = relacionamentos[0];
    let optionForMutation = null;
    for (const relation of relacionamentos) {
      await frame.locator(`.crud-toolbar [data-control-relacionamento="${relation}"]`).click();
      const modal = frame.locator('#controleDocumentosRelacionamentosModal');
      await modal.waitFor({ state: 'visible' });
      const title = await modal.locator('.modal-title').innerText();
      check(`Modal ${relation} abre`, title.toLowerCase().includes(relation === 'palavras_chaves' ? 'palavras' : relation.replace('_', ' ')), title);
      check(`Modal ${relation} possui grid de associações`, await modal.locator('[data-rel-grid]').count() === 1);
      check(`Modal ${relation} possui barra de ações`, await modal.locator('[data-rel-toolbar]').count() === 1);
      check(`Modal ${relation} possui botões fora do grid`, await modal.locator('[data-rel-grid] button').count() === 0);
      check(`Modal ${relation} possui confirmação`, await modal.locator('[data-rel-save]').count() === 1);
      if (await modal.locator('[data-rel-add]').isEnabled()) {
        await modal.locator('[data-rel-add]').click();
        const probe = modal.locator('[data-rel-row]').last().locator('[data-rel-select]');
        const candidate = await probe.locator('option').evaluateAll(options => options.map(item => ({ value: String(item.value || ''), text: String(item.textContent || '') })).find(item => item.value));
        if (candidate) mutationTargets[relation] = candidate;
        await modal.locator('[data-rel-delete]').click();
      }
      await modal.locator('.btn-close').click();
      await page.waitForTimeout(150);
    }
    const respButton = frame.locator('.crud-toolbar [data-control-responsabilidades]');
    await respButton.click();
    const respModal = frame.locator('#controleDocumentosResponsabilidadesModal');
    await respModal.waitFor({ state: 'visible' });
    check('Modal de responsáveis abre', await respModal.locator('[data-resp-grid]').count() === 1);
    check('Modal de responsáveis possui barra acima do grid', await respModal.locator('.crud-toolbar').count() === 1);
    check('Modal de responsáveis não possui botões dentro do grid', await respModal.locator('[data-resp-grid] button').count() === 0);
    if (mutar) {
      await respModal.locator('[data-resp-add]').click();
      const respSelect = respModal.locator('[data-resp-destino]').last();
      const respOption = await respSelect.locator('option').evaluateAll(options => options.map(item => ({ value: String(item.value || ''), text: String(item.textContent || '') })).find(item => item.value));
      check('Nova linha de responsável possui usuário ou grupo', Boolean(respOption && respOption.value), 'Nenhum destino disponível');
      await respSelect.selectOption(respOption.value);
      await respModal.locator('[data-resp-save]').click();
      await page.waitForTimeout(500);
      check('Inclusão de responsável realizada', await respModal.locator('[data-resp-row]').count() === 1, respOption.text);
      await respModal.locator('[data-resp-row]').first().click();
      await respModal.locator('[data-resp-delete]').click();
      await respModal.locator('[data-resp-save]').click();
      await page.waitForTimeout(500);
      check('Exclusão histórica de responsável realizada', await respModal.locator('[data-resp-row]').count() === 0, respOption.text);
    }
    await respModal.locator('.btn-close').click();
    await screenshot(page, '03-modais-relacionamentos');

    optionForMutation = mutationTargets[relationForMutation];
    if (mutar) check('Todos os relacionamentos possuem item livre para CRUD', relacionamentos.every(relation => !!mutationTargets[relation]), relacionamentos.filter(relation => !mutationTargets[relation]).join(', '));
    if (mutar) {
      check('Existe item disponível para teste de inclusão', Boolean(relationForMutation), 'Nenhum item livre foi encontrado');
      const targetButton = frame.locator(`.crud-toolbar [data-control-relacionamento="${relationForMutation}"]`);
      await targetButton.click();
      const modal = frame.locator('#controleDocumentosRelacionamentosModal');
      await modal.waitFor({ state: 'visible' });
      await modal.locator('[data-rel-add]').click();
      const newRow = modal.locator('[data-rel-row]').last();
      const selectable = newRow.locator('[data-rel-select]');
      const option = await selectable.locator('option').evaluateAll(options => options.map(item => ({ value: String(item.value || ''), text: String(item.textContent || '') })).find(item => item.value));
      check(`Linha nova de ${relationForMutation} possui combobox`, Boolean(option && option.value), option ? option.text : 'Nenhuma opção');
      await selectable.selectOption(option.value);
      await modal.locator('[data-rel-save]').click();
      await page.waitForTimeout(500);
      check(`Inclusão e sincronização de ${relationForMutation} realizada`, await modal.locator(`[data-rel-row][data-rel-id="${option.value}"]`).count() === 1, option.text);
      await modal.locator(`[data-rel-row][data-rel-id="${option.value}"]`).click();
      await modal.locator('[data-rel-delete]').click();
      await modal.locator('[data-rel-save]').click();
      await page.waitForTimeout(500);
      check(`Exclusão e sincronização de ${relationForMutation} realizada`, await modal.locator(`[data-rel-row][data-rel-id="${option.value}"]`).count() === 0, option.text);
      await screenshot(page, '04-inclusao-exclusao-restaurada');
      for (const relation of relacionamentos.slice(1)) {
        const option = mutationTargets[relation];
        check(`Item livre de ${relation} disponível`, Boolean(option && option.value), 'Nenhuma opção disponível');
        const targetButton = frame.locator(`.crud-toolbar [data-control-relacionamento="${relation}"]`);
        await targetButton.click();
        const modal = frame.locator('#controleDocumentosRelacionamentosModal');
        await modal.waitFor({ state: 'visible' });
        await modal.locator('[data-rel-add]').click();
        const newRow = modal.locator('[data-rel-row]').last();
        const selectable = newRow.locator('[data-rel-select]');
        check(`Linha nova de ${relation} possui combobox`, await selectable.count() === 1, option.text);
        await selectable.selectOption(option.value);
        await modal.locator('[data-rel-save]').click();
        await page.waitForTimeout(500);
        check(`Inclusão e sincronização de ${relation} realizada`, await modal.locator(`[data-rel-row][data-rel-id="${option.value}"]`).count() === 1, option.text);
        await modal.locator(`[data-rel-row][data-rel-id="${option.value}"]`).click();
        await modal.locator('[data-rel-delete]').click();
        await modal.locator('[data-rel-save]').click();
        await page.waitForTimeout(500);
        check(`Exclusão e sincronização de ${relation} realizada`, await modal.locator(`[data-rel-row][data-rel-id="${option.value}"]`).count() === 0, option.text);
      }
    } else {
      console.log('INFO - Inclusão/exclusão não executadas. Use --mutar para executar o ciclo e restaurar o vínculo.');
    }
    resultado.status = 'APROVADO';
  } catch (error) {
    resultado.error = error.message;
    try { await screenshot(page, 'falha'); } catch (_) {}
    throw error;
  } finally {
    resultado.finishedAt = new Date().toISOString();
    const arquivoResultado = visual ? path.join(evidencias, 'resultado.json') : saida;
    fs.writeFileSync(arquivoResultado, JSON.stringify(resultado, null, 2), 'utf8');
    await browser.close();
  }
}

main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const outputDir = path.resolve(arg('--saida', 'tests/evidencias-historico-situacao'));
const timeout = Number(arg('--timeout', '30000'));
const cdp = arg('--cdp', '');

if (!usuario || !senha) {
  console.error('Informe --usuario e --senha.');
  process.exit(2);
}

fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), screenshots: [], checks: [], status: 'REPROVADO' };
const check = (name, ok, details = '') => {
  result.checks.push({ name, ok: Boolean(ok), details });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
  if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`);
};
const screenshot = async (page, name) => {
  const file = path.join(outputDir, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  result.screenshots.push(file);
};
const frameIndex = async (page, part) => {
  const sources = await page.locator('iframe').evaluateAll((frames) => frames.map((frame) => frame.getAttribute('src') || ''));
  return sources.findIndex((source) => source.includes(part));
};

async function main() {
  let browser;
  const closeBrowser = !cdp;
  if (cdp) browser = await chromium.connectOverCDP(cdp);
  else browser = await chromium.launch({ headless: !args.includes('--visivel'), channel: 'chrome' });

  const context = browser.contexts()[0] || await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = context.pages()[0] || await context.newPage();
  page.setDefaultTimeout(timeout);

  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    check('Tela de login carregada', await page.locator('#userid').isVisible());
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario);
    await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/);
    check('Login realizado', true, page.url());

    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Requisitos legais');
    await page.locator('button[data-open-tab]').filter({ hasText: 'Requisitos legais' }).first().click();
    const requirementsIndex = await frameIndex(page, 'modulolegal_requisitoslegais.php');
    check('Iframe de Requisitos Legais localizado', requirementsIndex >= 0, String(requirementsIndex));
    const requirements = page.frameLocator('iframe').nth(requirementsIndex);
    await requirements.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible' });
    await requirements.locator('.grid-loading-modal').waitFor({ state: 'hidden' });
    await requirements.locator('input').first().fill('LB0001');
    await page.waitForTimeout(1600);
    const requirementRow = requirements.locator('#requisitosLegaisBatchGrid tbody tr').first();
    check('Requisito LB0001 localizado', await requirementRow.count() === 1);
    await requirementRow.click();

    const contextualButtons = requirements.locator('[data-open-requisito-popup]');
    let situationIndex = -1;
    for (let index = 0; index < await contextualButtons.count(); index += 1) {
      if (await contextualButtons.nth(index).getAttribute('data-open-requisito-popup') === 'situacao') situationIndex = index;
    }
    check('Botão Situação localizado', situationIndex >= 0);
    await contextualButtons.nth(situationIndex).click();

    const situationPopup = page.locator('[data-popup-transacao].show');
    await situationPopup.waitFor({ state: 'visible' });
    const situation = situationPopup.locator('iframe').contentFrame();
    await situation.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible' });
    await situation.locator('.grid-loading-modal').waitFor({ state: 'hidden' });
    await situation.locator('tbody tr').first().click();
    const historyButton = situation.locator('[data-requisito-situacao-historico]');
    check('Botão Histórico disponível no popup de Situação', await historyButton.count() === 1);
    check('Botão Histórico habilitado', await historyButton.isEnabled());
    await screenshot(page, '02-botao-historico');
    await historyButton.click();

    const historyModal = page.locator('[id^="greenPortalSituacaoHistoricoModal_"]').last();
    await historyModal.waitFor({ state: 'visible' });
    const history = historyModal.locator('iframe').contentFrame();
    await history.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible' });
    await history.locator('.grid-loading-modal').waitFor({ state: 'hidden' });
    check('Modal de Histórico aberta', true);
    check('Coluna Descrição visível', await history.locator('thead th[data-column="DESCRICAO"]').isVisible());
    check('Coluna Data do Histórico visível', await history.locator('thead th[data-column="DATAHISTORICO"]').isVisible());
    check('Coluna Usuário visível', await history.locator('thead th[data-column="CODUSUARIO"]').isVisible());
    check('Registros do histórico carregados', await history.locator('tbody tr').count() > 0);
    await screenshot(page, '03-grid-historico');
    await historyModal.locator('.btn-close').click();
    await historyModal.waitFor({ state: 'hidden' });
    check('Modal de Histórico fechada sem erro', true);
    result.status = 'APROVADO';
  } catch (error) {
    result.error = error.message;
    try { await screenshot(page, 'falha'); } catch (_) { }
    throw error;
  } finally {
    result.finishedAt = new Date().toISOString();
    fs.writeFileSync(path.join(outputDir, 'resultado.json'), JSON.stringify(result, null, 2), 'utf8');
    if (closeBrowser) await browser.close();
  }
}

main().catch((error) => {
  console.error(`ERRO FATAL: ${error.stack || error.message}`);
  process.exit(1);
});

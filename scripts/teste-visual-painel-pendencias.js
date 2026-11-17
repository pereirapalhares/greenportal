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
const outputDir = path.resolve(argument('--saida', path.join('tests', 'evidencias_painel_pendencias')));
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
  console.log(`EVIDÊNCIA - ${file}`);
}

async function main() {
  const launchOptions = { headless: !visible };
  const browser = await firefox.launch(launchOptions);
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(timeout);

  try {
    const waitForStableGrid = async frame => {
      await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
      await page.waitForTimeout(1200);
      await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
    };

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

    const rail = page.frameLocator('aside.portal-right-rail-frame iframe');
    await rail.locator('[data-rail-url]').first().waitFor({ state: 'visible' });
    const railButtons = await rail.locator('[data-rail-url]').count();
    check('Painel lateral carregado', railButtons >= 5, `${railButtons} ícones encontrados`);
    check('Ícone Pendências disponível', await rail.locator('[data-rail-id^="rail-pendencias"]').count() === 1);
    await screenshot(page, '02-painel-lateral');

    await rail.locator('[data-rail-id^="rail-pendencias"]').click();
    const pendingTab = page.locator('[id^="tab-rail-pendencias"]');
    await pendingTab.waitFor({ state: 'visible' });
    const pendingFrame = page.frameLocator('[id^="pane-rail-pendencias"] iframe');
    await pendingFrame.locator('.pending-card, .pending-empty').first().waitFor({ state: 'visible' });
    const cards = await pendingFrame.locator('[data-pending-url]').count();
    check('Aba de Pendências aberta', true);
    check('Cards de pendências renderizados', cards > 0, `${cards} cards encontrados`);
    const cardData = await pendingFrame.locator('[data-pending-url]').evaluateAll(elements => elements.map(element => ({
      title: element.getAttribute('data-pending-title') || '',
      transaction: element.getAttribute('data-pending-transaction') || '',
      url: element.getAttribute('data-pending-url') || '',
      text: element.innerText.replace(/\s+/g, ' ').trim()
    })));
    result.pendingCards = cardData;
    for (const required of ['modulolegal_situacaocumprimento', 'manutencaolegal_licencas', 'homologacaofornecedor_fornecedor', 'naoconformidades_minhasocorrencias']) {
      check(`Card ${required} disponível`, cardData.some(card => card.url.includes(required)));
    }
    await screenshot(page, '03-painel-pendencias');

    for (const card of cardData.filter(item => !item.url.includes('naoconformidades_minhasocorrencias'))) {
      const cardLocator = pendingFrame.locator(`[data-pending-transaction="${card.transaction}"]`);
      await cardLocator.click();
      const tab = page.locator(`[id^="tab-${card.transaction}"]`);
      await tab.waitFor({ state: 'visible' });
      const frame = page.frameLocator(`[id^="pane-${card.transaction}"] iframe`);
      await frame.locator('body').waitFor({ state: 'visible' });
      const grid = frame.locator('.menu-grid-fragment');
      if (await grid.count()) {
        await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
        await waitForStableGrid(frame);
        const rows = await grid.locator('table tbody tr').count();
        check(`Grid do card ${card.transaction} carregado`, true, `${rows} linhas renderizadas`);
        const status = await frame.locator('.grid-page-status').innerText().catch(() => '');
        check(`Paginação do card ${card.transaction} sem carregamento pendente`, !/Calculando total/i.test(status), status);
      } else {
        check(`Tela do card ${card.transaction} carregada`, true);
      }
      const targetUrl = await frame.locator('body').evaluate(() => location.href);
      check(`Destino do card ${card.transaction} validado`, targetUrl.includes(card.url.split('?')[0].split('/').pop()), targetUrl);
      await screenshot(page, `04-card-${card.transaction.replace(/[^a-z0-9_-]/gi, '-')}`);
      await pendingTab.click();
      await pendingFrame.locator('[data-pending-url]').first().waitFor({ state: 'visible' });
    }

    const ncCard = pendingFrame.locator('[data-pending-url*="naoconformidades_minhasocorrencias"]');
    check('Card de Não Conformidades disponível', await ncCard.count() === 1);
    await ncCard.click();
    const ncTab = page.locator('[id^="tab-naoconformidades_minhasocorrencias"]');
    await ncTab.waitFor({ state: 'visible' });
    const ncFrame = page.frameLocator('[id^="pane-naoconformidades_minhasocorrencias"] iframe');
    await ncFrame.locator('body').waitFor({ state: 'visible' });
    await ncFrame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
    await waitForStableGrid(ncFrame);
    const ncRows = await ncFrame.locator('.menu-grid-fragment table tbody tr').count();
    check('Carregamento visual do grid concluído', true, `${ncRows} linhas renderizadas`);
    const paginationStatus = await ncFrame.locator('.grid-page-status').innerText();
    check('Paginação sem mensagem de carregamento pendente', !/Calculando total/i.test(paginationStatus), paginationStatus);
    const ncUrl = await ncFrame.locator('body').evaluate(() => location.href);
    check('Grid de Minhas Ocorrências aberto', /naoconformidades_minhasocorrencias\.php/.test(ncUrl), ncUrl);
    await screenshot(page, '04-grid-minhas-ocorrencias');

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

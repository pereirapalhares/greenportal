const { firefox } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const browser = await firefox.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  page.setDefaultTimeout(30000);
  const result = { status: 'REPROVADO', checks: [], evidence: [] };
  const check = (name, ok, detail) => result.checks.push({ name, ok, detail });
  try {
    await page.goto('http://localhost/greenportal/index.php', { waitUntil: 'domcontentloaded' });
    await page.locator('#empresa').selectOption({ label: 'Modelo' });
    await page.locator('#userid').fill('admin');
    await page.locator('#senha').fill(process.env.GREEN_TEST_PASSWORD || '');
    await Promise.all([page.waitForURL(/main\.php/), page.locator('form.login-form button[type="submit"]').click()]);
    check('Login admin', true, 'Sessão iniciada.');
    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Plano de Medição');
    const menu = page.locator('button[data-open-tab][data-tab-url*="medicaoemonitoramento_mm_monitoramento.php"]').first();
    await menu.waitFor();
    await menu.click();
    const grid = page.frameLocator('iframe[src*="medicaoemonitoramento_mm_monitoramento.php"]').last();
    await grid.locator('tbody tr').first().waitFor();
    const first = grid.locator('tbody tr').first();
    check('Primeira linha carregada', await first.count() === 1, await first.innerText());
    check('Coluna Cronograma visível', await grid.locator('th[data-column="cronograma"]').count() === 1, '');
    await first.locator('[data-control-relacionamento="cronograma"]').click();
    await page.locator('#greenPortalTransacaoModal.show').waitFor();
    const popup = page.frameLocator('#greenPortalTransacaoModal.show iframe');
    await popup.locator('table tbody tr').first().waitFor();
    const rows = await popup.locator('table tbody tr').count();
    check('Popup Cronograma aberto', true, 'Iframe carregado.');
    check('Evento da primeira linha exibido', rows > 0, 'Linhas exibidas: ' + rows);
    await page.screenshot({ path: 'tests/evidencias_cronograma_primeira_linha.png', fullPage: true });
    await popup.locator('table tbody tr').first().screenshot({ path: 'tests/evidencias_cronograma_evento.png' });
    result.evidence = ['tests/evidencias_cronograma_primeira_linha.png', 'tests/evidencias_cronograma_evento.png'];
    result.status = result.checks.every((item) => item.ok) ? 'APROVADO' : 'REPROVADO';
  } catch (error) {
    result.error = error.message;
  } finally {
    result.finishedAt = new Date().toISOString();
    fs.writeFileSync(path.resolve('tests/resultado-visual-cronograma-firefox-20261002.json'), JSON.stringify(result, null, 2));
    await browser.close();
  }
  process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

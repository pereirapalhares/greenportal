const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const outputDir = path.resolve(arg('--saida', 'tests/evidencias_licencas'));
const visible = args.includes('--visivel');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { const file = path.join(outputDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }
async function closePopup(page, popup) { await popup.locator('.btn-close').click(); await popup.waitFor({ state: 'hidden' }); await page.waitForTimeout(350); check('Popup fechado sem sobreposição residual', await page.locator('[data-popup-transacao].show:visible').count() === 0 && await page.locator('.modal-backdrop.show:visible').count() === 0); }

async function main() {
  const browser = await firefox.launch({ headless: !visible });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    check('Tela de login carregada', await page.locator('#userid').isVisible());
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true, page.url()); await shot(page, '01-main-login');
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Licenças');
    const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]');
    check('Item de menu Licenças disponível', await menu.count() === 1); await shot(page, '02-menu-licencas'); await menu.click();
    const frameLocator = page.frameLocator('iframe[src*="manutencaolegal_licencas.php"]');
    await frameLocator.locator('.licencas-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout });
    await frameLocator.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout });
    const grid = frameLocator.locator('#manutencaolegal_licencasBatchGrid');
    const rows = await grid.locator('tbody tr').count(), columns = await grid.locator('thead th').count();
    check('Grid de Licenças carregado', rows > 0, `${rows} linhas`); check('Colunas do grid renderizadas', columns >= 15, `${columns} colunas`);
    check('Paginação carregada', !/Calculando total/i.test(await frameLocator.locator('.grid-page-status').innerText()));
    check('Filtros por coluna disponíveis', await grid.locator('[data-column-filter]').count() >= 10);
    check('Combobox de responsável disponível', await grid.locator('[data-column-filter="CODUSUARIO"]').count() === 1);
    check('Botões de ações de licenças disponíveis', await frameLocator.locator('[data-license-condicionantes],[data-license-palavras-chave],[data-license-responsabilidades]').count() === 3);
    check('Responsabilidades inicia desabilitado', await frameLocator.locator('[data-license-responsabilidades]:disabled').count() === 1);
    const row = grid.locator('tbody tr').first(); await row.click();
    check('Seleção habilita Responsabilidades', await frameLocator.locator('[data-license-responsabilidades]:not(:disabled)').count() === 1);
    await shot(page, '03-grid-licencas');
    for (const [name, selector, expected] of [['condicionantes', '[data-license-condicionantes]', 'condicionantes'], ['palavras-chave', '[data-license-palavras-chave]', 'palavras-chave'], ['responsabilidades', '[data-license-responsabilidades]', 'responsabilidades']]) {
      await frameLocator.locator(selector).click(); const popup = page.locator('[data-popup-transacao].show'); await popup.waitFor({ state: 'visible' });
      const popupFrame = page.frameLocator('[data-popup-transacao].show iframe'); await popupFrame.locator('body').waitFor({ state: 'visible' });
      const body = await popupFrame.locator('body').innerText(); const url = await popupFrame.locator('body').evaluate(() => location.href);
      check(`Popup ${expected} sem erro`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i.test(body), body.slice(0, 180));
      check(`Popup ${expected} aberto`, url.includes('licenca_') || url.includes('condicion') || url.includes('palavra'), url);
      await shot(page, `04-popup-${name}`); await closePopup(page, popup);
    }
    result.rows = rows; result.columns = columns; result.finishedAt = new Date().toISOString(); result.status = 'APROVADO';
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); try { await shot(page, 'falha'); } catch (_) {} throw error; }
  finally { fs.writeFileSync(path.join(outputDir, 'resultado.json'), JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

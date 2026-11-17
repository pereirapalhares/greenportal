const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const outputDir = path.resolve(arg('--saida', 'tests/evidencias_licencas_condicionantes'));
const visible = args.includes('--visivel');
const timeout = Number(arg('--timeout', '30000'));

if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { const file = path.join(outputDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }

async function measureAlignment(frame) {
  return frame.locator('.menu-batch-grid').evaluate((table) => {
    const visible = (element) => !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    const headers = Array.from(table.querySelectorAll('thead th')).filter(visible);
    const row = Array.from(table.querySelectorAll('tbody tr[data-key^="novo_"]')).find(visible);
    const cells = row ? Array.from(row.children).filter(visible) : [];
    const headerRects = headers.map((element) => { const r = element.getBoundingClientRect(); return { x: r.x, width: r.width, label: element.innerText.trim() }; });
    const cellRects = cells.map((element) => { const r = element.getBoundingClientRect(); return { x: r.x, width: r.width, label: element.getAttribute('data-column') || 'row-actions' }; });
    const comparisons = headerRects.map((header, index) => {
      const cell = cellRects[index];
      return cell ? { index, header: header.label, cell: cell.label, xDelta: Math.round((cell.x - header.x) * 100) / 100, widthDelta: Math.round((cell.width - header.width) * 100) / 100 } : { index, header: header.label, missingCell: true };
    });
    return { headerCount: headers.length, cellCount: cells.length, comparisons, tableWidth: table.getBoundingClientRect().width, scrollWidth: table.scrollWidth, clientWidth: table.clientWidth };
  });
}

async function main() {
  const browser = await chromium.launch({ headless: !visible, channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true, page.url());
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Licen');
    const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]');
    check('Menu de licenças disponível', await menu.count() === 1); await menu.click();
    const licenseFrame = page.frameLocator('iframe[src*="manutencaolegal_licencas.php"]');
    await licenseFrame.locator('#manutencaolegal_licencasBatchGrid tbody tr').first().waitFor({ state: 'visible' });
    const licenseRow = licenseFrame.locator('#manutencaolegal_licencasBatchGrid tbody tr').first();
    await licenseRow.evaluate((element) => element.dispatchEvent(new MouseEvent('click', { bubbles: true })));
    const condicionantes = licenseFrame.locator('[data-license-condicionantes]');
    await condicionantes.waitFor({ state: 'visible', timeout });
    if (await condicionantes.isDisabled()) throw new Error('Botão de condicionantes permaneceu desabilitado após selecionar a licença.');
    await condicionantes.click();
    const conditionFrame = page.frameLocator('#greenPortalTransacaoModal.show iframe');
    await conditionFrame.locator('.cadastro-legal-fragment.grid-ready, .cadastro-legal-fragment').first().waitFor({ state: 'visible' });
    await conditionFrame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {});
    const grid = conditionFrame.locator('.menu-batch-grid');
    check('Grid de condicionantes carregado', await grid.count() === 1);
    await shot(page, '01-antes-novo');
    const before = await measureAlignment(conditionFrame);
    await conditionFrame.locator('[data-new-cadastro]').click();
    await conditionFrame.locator('tbody tr[data-key^="novo_"]').waitFor({ state: 'visible' });
    await page.waitForTimeout(250);
    const after = await measureAlignment(conditionFrame);
    await shot(page, '02-depois-novo');
    const deltas = after.comparisons.filter((item) => !item.missingCell).map((item) => Math.abs(item.xDelta));
    const maxDelta = deltas.length ? Math.max(...deltas) : Number.POSITIVE_INFINITY;
    const structureOk = after.headerCount === after.cellCount && !after.comparisons.some((item) => item.missingCell);
    check('Nova linha possui a mesma quantidade de células do cabeçalho', structureOk, `cabeçalho=${after.headerCount}, linha=${after.cellCount}`);
    check('Nova linha permanece alinhada ao cabeçalho', maxDelta <= 3, `maior diferença horizontal=${maxDelta}px`);
    result.before = before; result.after = after; result.maxXDelta = maxDelta; result.finishedAt = new Date().toISOString(); result.status = 'APROVADO';
  } catch (error) {
    result.error = error.message; result.finishedAt = new Date().toISOString(); try { await shot(page, 'falha'); } catch (_) {} throw error;
  } finally { fs.writeFileSync(path.join(outputDir, 'resultado.json'), JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

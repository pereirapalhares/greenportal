const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const visual = args.includes('--visual');
const timeout = Number(arg('--timeout', '30000'));
const output = path.resolve(arg('--saida', 'tests/resultado-visual-firefox-backlog-27-contagem-requisitos.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_visual_firefox_backlog_27'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }

const result = { startedAt: new Date().toISOString(), item: 27, navegador: 'Firefox', checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { if (!visual) return; fs.mkdirSync(evidenceDir, { recursive: true }); const file = path.join(evidenceDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }
async function waitGrid(frame) { await frame.locator('#requisitosLegaisBatchGrid').waitFor({ state: 'visible', timeout }); await frame.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout }); await frame.locator('.grid-page-status').waitFor({ state: 'visible', timeout }); }

async function main() {
  const browser = await firefox.launch({ headless: !visual });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded', timeout });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
    await Promise.all([page.waitForURL(/\/principal\/main\.php/, { timeout }), page.locator('form.login-form button[type="submit"]').click()]);
    check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Requisitos legais');
    await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const frame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
    await waitGrid(frame); check('Grid de Requisitos Legais carregado', true); await shot(page, '01-grid-contagem-inicial');

    const status = frame.locator('.grid-page-status');
    const initialStatus = await status.innerText();
    check('Contagem inicial exibida', /\d+\s+registros/i.test(initialStatus), initialStatus);
    check('Pagina inicial identificada', /Página\s+1/i.test(initialStatus), initialStatus);
    const next = frame.locator('.grid-page-next');
    const hasNext = await next.isEnabled();
    result.initial = { status: initialStatus, hasNext };
    if (hasNext) {
      await next.click();
      await status.waitFor({ state: 'visible', timeout });
      await page.waitForTimeout(500);
      const secondStatus = await status.innerText();
      check('Navegacao para segunda pagina atualiza o contador', /Página\s+2/i.test(secondStatus), secondStatus);
      result.secondPage = secondStatus; await shot(page, '02-segunda-pagina');
    } else {
      check('Paginacao informa corretamente que nao ha segunda pagina', true, initialStatus);
      result.secondPage = null;
    }

    const filter = frame.locator('[data-column-filter="CODIGO"]');
    check('Filtro de Codigo localizado', await filter.count() === 1);
    await filter.fill('LB0001');
    await status.filter({ hasText: /Página\s+1/i }).waitFor({ state: 'visible', timeout: timeout + 5000 });
    const filteredStatus = await status.innerText();
    check('Contagem permanece exibida apos aplicar filtro', /\d+\s+registros/i.test(filteredStatus), filteredStatus);
    check('Filtro retorna para a primeira pagina', /Página\s+1/i.test(filteredStatus), filteredStatus);
    result.filtered = filteredStatus; await shot(page, '03-contagem-apos-filtro');
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) {
    result.error = error.message; result.finishedAt = new Date().toISOString();
    try { await shot(page, 'falha'); } catch (_) {}
    throw error;
  } finally {
    fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await browser.close();
  }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

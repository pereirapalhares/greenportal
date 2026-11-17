const fs = require('fs');
const path = require('path');
const { chromium, firefox } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 ? (args[i + 1] || fallback) : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', 'admin');
const senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const visual = args.includes('--visual');
const mutar = args.includes('--mutar');
const cdp = arg('--cdp', '');
const out = path.resolve(arg('--saida', 'tests/evidencias_fluxo_ia_requisitos'));
fs.mkdirSync(out, { recursive: true });
const result = { startedAt: new Date().toISOString(), checks: [], screenshots: [] };
function check(name, ok, detail = '') { result.checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name + (detail ? `: ${detail}` : '')); }
async function shot(page, name) { if (!visual) return; const file = path.join(out, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }

async function main() {
  let browser;
  if (cdp) browser = await chromium.connectOverCDP(cdp);
  else { try { browser = await chromium.launch({ headless: !visual }); } catch (_) { browser = await firefox.launch({ headless: !visual }); } }
  const context = cdp ? (browser.contexts()[0] || await browser.newContext({ viewport: { width: 1440, height: 900 } })) : await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = cdp ? (context.pages()[0] || await context.newPage()) : await context.newPage(); page.setDefaultTimeout(30000);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click();
    await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true, page.url()); await shot(page, '01-login');
    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    const menuSearch = page.locator('#menuSearch');
    await menuSearch.waitFor({ state: 'visible' });
    await menuSearch.fill('Requisitos legais');
    const menu = page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]'); await menu.waitFor({ state: 'visible' }); await menu.click();
    const iframe = page.locator('iframe[src*="modulolegal_requisitoslegais.php"]'); await iframe.waitFor({ state: 'attached' }); const frame = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]');
    await frame.locator('.modulo-legal-requisitos-fragment').waitFor({ state: 'visible' }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden' }).catch(() => {}); await page.waitForTimeout(700);
    check('Grid de requisitos legais carregado', await frame.locator('#requisitosLegaisBatchGrid tbody tr').count() > 0);
    const requestButton = frame.locator('[data-request-ia-analysis]'); check('Botão de análise acima do grid', await requestButton.count() === 1); check('Nenhum botão de análise dentro do grid', await frame.locator('#requisitosLegaisBatchGrid [data-request-ia-analysis]').count() === 0);
    await frame.locator('#requisitosLegaisBatchGrid tbody tr').first().click(); check('Botão habilitado após selecionar requisito', await requestButton.isEnabled()); await shot(page, '02-botao-analise');
    let dialogMessage = ''; let resolveDialog; const dialogSeen = new Promise(resolve => { resolveDialog = resolve; }); page.once('dialog', async dialog => { dialogMessage = dialog.message(); await dialog.accept().catch(() => {}); resolveDialog(); }); const requestResponse = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('modulolegal_requisitoslegais.php'), { timeout: 30000 }); await requestButton.click(); const response = await requestResponse; await Promise.race([dialogSeen, new Promise(resolve => setTimeout(resolve, 3000))]); check('Mensagem de solicitação exibida', /solicita|análise|analysis/i.test(dialogMessage), dialogMessage); check('Solicitação enviada ao grid', response.status() < 500, `HTTP ${response.status()}`); await shot(page, '03-confirmacao');
    if (!mutar) { check('Teste não visual sem mutação concluído', true); }
    else {
      await page.waitForTimeout(1200);
      const alerts = await page.evaluate(async () => (await fetch('/greenportal/principal/alertas.php?_=' + Date.now(), { credentials: 'same-origin' })).json());
      const alert = (alerts.alerts || []).find(a => /IA|analysis|análisis/i.test(String(a.titulo || '')) && a.link_status);
      check('Alerta inicial de análise criado', !!alert); check('Alerta possui link de acompanhamento', !!(alert && alert.link_status));
      const statusPage = await context.newPage(); await statusPage.goto(baseUrl + alert.link_status, { waitUntil: 'domcontentloaded' }); check('Popup/status da solicitação acessível', !(await statusPage.locator('body').innerText()).match(/Fatal error|PDOException|Sessão expirada/i)); await shot(statusPage, '04-status'); await statusPage.close();
    }
    result.status = 'APROVADO';
  } finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync(path.join(out, 'resultado.json'), JSON.stringify(result, null, 2)); if (!cdp) await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error}`); process.exitCode = 1; });

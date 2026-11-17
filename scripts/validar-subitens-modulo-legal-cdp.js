const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const senha = arg('--senha', '');
const cdp = arg('--cdp', 'http://127.0.0.1:9222');
const output = path.resolve(arg('--saida', 'tests/resultado-validacao-subitens-modulo-legal-20260923.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias-validacao-subitens-modulo-legal-20260923'));
if (!senha) { console.error('Informe --senha.'); process.exit(2); }

const users = arg('--usuarios', 'admin,ueditor,urevisor,uaprovador,gestor,responsavel,visitante').split(',').map(value => value.trim()).filter(Boolean);
const routes = [
  { name: 'LAIA', path: '/principal/modulolegal_laia.php', grid: 'modulo-legal-laia', context: 4 },
  { name: 'LPRO', path: '/principal/modulolegal_lpro.php', grid: 'modulo-lpro', context: 6 },
  { name: 'LAIS', path: '/principal/modulolegal_lais.php', grid: 'modulo-lais', context: 3 },
  { name: 'Controle', path: '/principal/modulolegal_controle.php', grid: 'controle-documentos', context: 10 },
  { name: 'Meus Controles', path: '/principal/meus_controles.php', grid: 'controle-documentos', directed: true },
  { name: 'Minhas Licenças', path: '/principal/minhas_licencas.php', grid: 'modulo-legal-licencas', directed: true },
  { name: 'Minhas Infraestruturas', path: '/principal/minhas_infraestruturas.php', grid: 'modulo-legal-infraestrutura', directed: true },
];
const result = { startedAt: new Date().toISOString(), empresa, users, routes, profiles: [], status: 'REPROVADO' };
const serverError = /(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack|HTTP ERROR 5)/i;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

async function login(page, user) {
  await page.context().clearCookies();
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(user);
  await page.locator('#senha').fill(senha);
  await Promise.all([page.waitForURL(/\/principal\/main\.php/), page.locator('form.login-form button[type="submit"]').click()]);
}

async function waitGrid(page) {
  for (let i = 0; i < 50; i += 1) {
    const ready = await page.locator('.menu-grid-fragment.grid-ready, .menu-batch-grid').count().catch(() => 0);
    const loading = await page.locator('.grid-loading-modal:visible, .modal-backdrop').count().catch(() => 0);
    if (ready > 0 && loading === 0) return true;
    await sleep(250);
  }
  return false;
}

async function inspectRoute(page, user, route, index) {
  const response = await page.goto(`${baseUrl}${route.path}`, { waitUntil: 'domcontentloaded' });
  await waitGrid(page);
  await sleep(700);
  const body = await page.locator('body').innerText().catch(() => '');
  const status = response ? response.status() : 0;
  const grid = page.locator('.menu-grid-fragment, .menu-batch-grid').first();
  const rows = await page.locator('.menu-grid-fragment tbody tr[data-row], .menu-batch-grid tbody tr[data-row]').count().catch(() => 0);
  const actionNames = await page.locator('[data-crud-action]').evaluateAll(items => items.map(x => String(x.getAttribute('data-crud-action') || '')).filter(Boolean)).catch(() => []);
  const contextButtons = await page.locator('[data-context-child], [data-control-relacionamento]').count().catch(() => 0);
  const enabledContext = await page.locator('[data-context-child]:not([disabled]), [data-control-relacionamento]:not([disabled])').count().catch(() => 0);
  const title = (await page.title().catch(() => '')).slice(0, 120);
  const screenshot = path.join(evidenceDir, `${String(index).padStart(2, '0')}-${user}-${route.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.png`);
  await page.screenshot({ path: screenshot, fullPage: true });
  return {
    name: route.name, route: route.path, status, allowed: status >= 200 && status < 300,
    noServerError: !serverError.test(body), title, gridFound: await grid.count() === 1,
    rows, actions: actionNames, contextButtons, enabledContext,
    expectedContext: route.context ?? null, directed: Boolean(route.directed), screenshot,
    bodyExcerpt: body.replace(/\s+/g, ' ').slice(0, 300),
  };
}

async function main() {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const browser = await chromium.connectOverCDP(cdp);
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(30000);
  try {
    for (const user of users) {
      const profile = { user, login: false, checks: [], errors: [] };
      try {
        await login(page, user); profile.login = true;
        for (const route of routes) {
          try { profile.checks.push(await inspectRoute(page, user, route, profile.checks.length + 1)); }
          catch (error) { profile.errors.push({ route: route.name, error: error.message }); }
        }
      } catch (error) { profile.errors.push({ phase: 'login', error: error.message }); }
      result.profiles.push(profile);
      console.log(JSON.stringify({ user, login: profile.login, checks: profile.checks.length, errors: profile.errors.length }));
    }
    const checks = result.profiles.flatMap(p => p.checks);
    const routeOk = checks.filter(c => c.allowed && c.noServerError && c.gridFound);
    result.summary = {
      logins: result.profiles.filter(p => p.login).length,
      expectedLogins: users.length,
      routeChecks: checks.length,
      expectedRouteChecks: users.length * routes.length,
      allowed: checks.filter(c => c.allowed).length,
      denied: checks.filter(c => c.status === 403).length,
      serverErrors: checks.filter(c => !c.noServerError).length,
      grids: checks.filter(c => c.gridFound).length,
      rows: checks.filter(c => c.rows > 0).length,
      contextMismatch: checks.filter(c => c.allowed && c.expectedContext !== null && c.contextButtons !== c.expectedContext).length,
      directedCrudViolations: checks.filter(c => c.directed && ['new', 'duplicate', 'delete'].some(a => c.actions.includes(a))).length,
      routeOk: routeOk.length,
    };
    result.status = result.summary.logins === result.summary.expectedLogins && result.summary.serverErrors === 0 && result.summary.contextMismatch === 0 ? 'APROVADO_TECNICAMENTE' : 'REPROVADO';
  } finally {
    result.finishedAt = new Date().toISOString();
    fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
    await context.close(); await browser.close();
  }
}
main().catch(error => { console.error(error.stack || error.message); process.exit(1); });

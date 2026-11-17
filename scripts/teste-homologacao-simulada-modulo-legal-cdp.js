const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const senha = arg('--senha', '');
const cdp = arg('--cdp', 'http://127.0.0.1:9222');
const output = path.resolve(arg('--saida', 'tests/resultado-homologacao-simulada-modulo-legal-20260922.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias-homologacao-simulada-modulo-legal-20260922'));
if (!senha) { console.error('Informe --senha.'); process.exit(2); }

const users = ['admin', 'ueditor', 'urevisor', 'uaprovador', 'gestor', 'responsavel', 'visitante'];
const routes = [
  ['LAIA', '/principal/modulolegal_laia.php'],
  ['Ambiental', '/principal/modulolegal_correlacionamnetoambiental.php'],
  ['Ocupacional', '/principal/modulolegal_correlacionamentoocupacional.php'],
  ['Social', '/principal/modulolegal_correlacionamentosocial.php'],
  ['Licencas', '/principal/manutencaolegal_licencas.php'],
  ['Condicionantes', '/principal/manutencaolegal_licencas_condicionantes.php'],
];
const result = { startedAt: new Date().toISOString(), empresa, users, routes: routes.map(([name, route]) => ({ name, route })), profiles: [], status: 'REPROVADO' };
const errorPattern = /(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i;

async function login(page, user) {
  await page.context().clearCookies();
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(user); await page.locator('#senha').fill(senha);
  await Promise.all([page.waitForURL(/\/principal\/main\.php/), page.locator('form.login-form button[type="submit"]').click()]);
}

async function main() {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const browser = await chromium.connectOverCDP(cdp);
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(30000);
  try {
    for (const user of users) {
      const profile = { user, login: false, checks: [], screenshot: '' };
      try {
        await login(page, user); profile.login = true;
        const shot = path.join(evidenceDir, `${user}-main.png`); await page.screenshot({ path: shot, fullPage: true }); profile.screenshot = shot;
        for (const [name, route] of routes) {
          const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'domcontentloaded' });
          const body = await page.locator('body').innerText().catch(() => '');
          const status = response ? response.status() : 0;
          profile.checks.push({ name, route, status, allowed: status >= 200 && status < 300, noServerError: !errorPattern.test(body), title: (await page.title()).slice(0, 120) });
        }
      } catch (error) { profile.error = error.message; }
      result.profiles.push(profile); console.log(JSON.stringify(profile));
    }
    result.summary = { logins: result.profiles.filter(p => p.login).length, routeChecks: result.profiles.reduce((n, p) => n + p.checks.length, 0), serverErrors: result.profiles.reduce((n, p) => n + p.checks.filter(c => !c.noServerError).length, 0), denied: result.profiles.reduce((n, p) => n + p.checks.filter(c => c.status === 403).length, 0) };
    result.status = result.summary.logins === users.length && result.summary.serverErrors === 0 ? 'APROVADO_TECNICAMENTE' : 'REPROVADO';
  } finally {
    result.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await context.close(); await browser.close();
  }
}
main().catch(error => { console.error(error.stack || error.message); process.exit(1); });

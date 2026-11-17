const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const a = process.argv.slice(2);
const get = (n, d) => {
  const i = a.indexOf(n);
  return i >= 0 && a[i + 1] ? a[i + 1] : d;
};
const base = get('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = get('--empresa', 'Modelo');
const adminUser = get('--admin-usuario', 'admin');
const adminPass = get('--admin-senha', '');
const targetUser = get('--usuario', 'ueditor');
const targetPass = get('--senha', '');
const output = path.resolve(get('--saida', 'tests/evidencias_licencas'));
const visible = a.includes('--visivel');

if (!adminPass || !targetPass) throw new Error('Informe as senhas de admin e do usuário alvo.');
if (adminUser.toLowerCase() === targetUser.toLowerCase()) throw new Error('Admin e usuário alvo devem ser diferentes.');
fs.mkdirSync(output, { recursive: true });
const report = { startedAt: new Date().toISOString(), contexto: { empresa, adminUser, targetUser }, checks: [], screenshots: [], status: 'REPROVADO' };

function check(name, ok, details) {
  report.checks.push({ name, ok: !!ok, details: details || '' });
  console.log((ok ? 'PASS - ' : 'FAIL - ') + name + (details ? ': ' + details : ''));
  if (!ok) throw new Error(name);
}
async function login(page, user, pass, label) {
  await page.context().clearCookies();
  await page.goto(base + '/index.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(user);
  await page.locator('#senha').fill(pass);
  await page.locator('form.login-form button[type="submit"]').click();
  await page.waitForURL(/\/principal\/main\.php/);
  check('Login ' + label, true, user + ' - ' + page.url());
}
async function permissionRow(page) {
  await page.goto(base + '/principal/seguranca_permissoes.php', { waitUntil: 'networkidle' });
  await page.locator('.permission-grid-fragment').waitFor();
  for (const item of [['[data-column-filter="codperfil"]', '3'], ['[data-column-filter="codmenu"]', '15']]) {
    const field = page.locator(item[0]);
    await field.evaluate((el, value) => { el.value = value; el.dispatchEvent(new Event('change', { bubbles: true })); }, item[1]);
  }
  await page.waitForTimeout(1800);
  return page.locator('#permissionBatchGrid tbody tr[data-key="74"]');
}
async function savePermission(page, row, value) {
  const box = row.locator('[data-column="pode_visualizar"] input');
  if (await box.isChecked() === value) return;
  await box.setChecked(value, { force: true });
  await page.locator('[data-testid="save-permissions"]').click();
  const confirm = page.locator('div.modal.show button[data-confirm]').last();
  await confirm.waitFor();
  const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('seguranca_permissoes.php') && String(r.request().postData() || '').includes('ajax=1'));
  await confirm.evaluate(el => el.click());
  const result = await response;
  if (!result.ok() || !(await result.json()).ok) throw new Error('Backend não confirmou a permissão.');
  await page.waitForTimeout(500);
}
async function checkMenu(page, expected) {
  await page.goto(base + '/principal/main.php', { waitUntil: 'networkidle' });
  await page.locator('[data-sidebar-toggle]').click().catch(() => {});
  await page.locator('#menuSearch').fill('Licenças');
  await page.waitForTimeout(700);
  const count = await page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]').count();
  check(expected ? 'Licenças aparece após concessão' : 'Licenças oculto sem permissão', expected ? count === 1 : count === 0, 'encontrados=' + count);
}

async function main() {
  const browser = await firefox.launch({ headless: !visible });
  const adminContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const targetContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const admin = await adminContext.newPage();
  const target = await targetContext.newPage();
  admin.setDefaultTimeout(30000);
  target.setDefaultTimeout(30000);
  let original = false;
  let changed = false;
  try {
    await login(admin, adminUser, adminPass, 'administrador');
    let row = await permissionRow(admin);
    check('Permissão LICENCAS localizada', await row.count() === 1);
    original = await row.locator('[data-column="pode_visualizar"] input').isChecked();
    check('Permissão original registrada', true, 'pode_visualizar=' + (original ? 1 : 0));
    if (!original) {
      changed = true;
      await savePermission(admin, row, true);
      await login(target, targetUser, targetPass, 'usuário alvo');
      await checkMenu(target, true);
      row = await permissionRow(admin);
      await savePermission(admin, row, false);
    }
    await login(target, targetUser, targetPass, 'usuário alvo');
    await checkMenu(target, false);
    const response = await target.goto(base + '/principal/manutencaolegal_licencas.php', { waitUntil: 'networkidle' });
    const body = await target.locator('body').innerText();
    check('Acesso direto bloqueado', (response && response.status() === 403) || /sem permissão|acesso negado/i.test(body), 'HTTP=' + (response ? response.status() : 0));
    report.status = 'APROVADO';
  } catch (e) {
    report.error = e.message;
    try { const file = path.join(output, 'falha-permissao-licencas.png'); await target.screenshot({ path: file, fullPage: true }); report.screenshots.push(file); } catch (_) {}
    throw e;
  } finally {
    if (changed) {
      try { const row = await permissionRow(admin); await savePermission(admin, row, original); } catch (e) { report.restoreError = e.message; }
    }
    report.finishedAt = new Date().toISOString();
    fs.writeFileSync(path.join(output, 'resultado-permissoes-licencas.json'), JSON.stringify(report, null, 2), 'utf8');
    await browser.close();
  }
}
main().catch(e => { console.error(e.stack || e.message); process.exit(1); });

const fs = require('fs');
const path = require('path');
const { chromium, firefox } = require('playwright');

const args = process.argv.slice(2);
function arg(name, fallback = '') { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; }
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const adminUsuario = arg('--admin-usuario', 'admin');
const adminSenha = arg('--admin-senha', '');
const restrictedUsuario = arg('--usuario', 'ueditor');
const restrictedSenha = arg('--senha', '');
const outputDir = path.resolve(arg('--saida', path.join('tests', 'evidencias_requisitos_legais')));
const visible = args.includes('--visivel');
const cdp = arg('--cdp', '');
const timeout = Number(arg('--timeout', '30000'));
if (!adminSenha || !restrictedSenha) { console.error('Informe --admin-senha e --senha.'); process.exit(2); }
fs.mkdirSync(outputDir, { recursive: true });
const result = { startedAt: new Date().toISOString(), checks: [], screenshots: [], status: 'REPROVADO' };

function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
  if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`);
}
async function screenshot(page, name) {
  const file = path.join(outputDir, `${name}.png`);
  try {
    await page.screenshot({ path: file, fullPage: true, timeout: 10000 });
    result.screenshots.push(file);
    console.log(`EVIDENCIA - ${file}`);
  } catch (error) {
    console.warn(`AVISO - Evidência não capturada (${name}): ${error.message}`);
  }
}
async function login(page, usuario, senha) {
  let lastMessage = '';
  for (let attempt = 1; attempt <= 2; attempt++) {
    await page.goto(`${baseUrl}/index.php?tentativa=${attempt}`, { waitUntil: 'domcontentloaded' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(async () => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario);
    await page.locator('#senha').fill(senha);
    await page.locator('form.login-form button[type="submit"]').click();
    try {
      await page.waitForURL(/\/principal\/main\.php/, { timeout: Math.min(timeout, 15000) });
      check(`Login realizado: ${usuario}`, /\/principal\/main\.php/.test(page.url()), page.url());
      return;
    } catch (error) {
      lastMessage = await page.locator('.alert, .login-error, [role="alert"]').allInnerTexts().catch(() => []);
      await page.waitForTimeout(700);
    }
  }
  throw new Error(`Login não redirecionou para main.php (${usuario}). ${lastMessage.join(' ')}`);
}
async function logout(page) {
  await page.goto(`${baseUrl}/credenciais/sair.php`, { waitUntil: 'networkidle' }).catch(() => {});
}
async function openPermissionPage(page) {
  await page.goto(`${baseUrl}/principal/seguranca_permissoes.php`, { waitUntil: 'networkidle' });
  await page.locator('.permission-grid-fragment').waitFor({ state: 'visible', timeout });
}
async function findPermissionRow(page, profileCode, menuCode, menuId = '') {
  const filters = [
    { selector: '[data-column-filter="codperfil"]', value: '3', label: profileCode },
    { selector: '[data-column-filter="codmenu"]', value: menuId, label: menuCode }
  ];
  for (const filter of filters) {
    const control = page.locator(filter.selector);
    await control.waitFor({ state: 'visible', timeout });
    await control.evaluate((element, option) => {
      if (element.tagName === 'SELECT' && !Array.from(element.options).some((item) => item.value === option.value)) {
        element.add(new Option(option.label, option.value));
      }
      element.value = option.value;
      element.dispatchEvent(new Event('change', { bubbles: true }));
    }, filter);
  }
  await page.waitForTimeout(1800);
  await page.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {});
  const rows = page.locator('#permissionBatchGrid tbody tr[data-row]');
  const match = await rows.evaluateAll((items, wanted) => items.map((row) => {
    const value = (column) => {
      const cell = row.querySelector(`[data-column="${column}"]`);
      const field = cell?.querySelector('select,input,textarea');
      return String(field?.value || cell?.textContent || '').trim();
    };
    return { key: row.getAttribute('data-key'), profile: value('perfil_codigo'), menuId: value('codmenu') };
  }).find((item) => item.profile === wanted.profile && item.menuId === wanted.menuId), { profile: profileCode, menuId });
  return match ? page.locator(`#permissionBatchGrid tbody tr[data-key="${match.key}"]`) : null;
}
async function savePermission(page, row, value) {
  const current = await row.locator('[data-column="pode_visualizar"] input[type="checkbox"]').isChecked();
  if (current === value) return false;
  const checkbox = row.locator('[data-column="pode_visualizar"] input[type="checkbox"]');
  await checkbox.setChecked(value, { force: true });
  if (await checkbox.isChecked() !== value) throw new Error(`Checkbox não assumiu o valor solicitado: ${value ? 1 : 0}`);
  const save = page.locator('[data-testid="save-permissions"]');
  await save.waitFor({ state: 'visible', timeout });
  await save.click();
  const confirm = page.locator('div.modal.show button[data-confirm]').last();
  await confirm.waitFor({ state: 'visible', timeout });
  const ajaxResponse = page.waitForResponse((response) => {
    const request = response.request();
    return request.method() === 'POST' &&
      response.url().includes('seguranca_permissoes.php') &&
      request.postData()?.includes('ajax=1');
  }, { timeout });
  await confirm.evaluate((element) => element.click());
  const response = await ajaxResponse;
  if (!response.ok()) throw new Error(`Backend de permissões respondeu HTTP ${response.status()}`);
  const payload = await response.json().catch(() => null);
  if (!payload || payload.ok !== true) throw new Error(`Backend não confirmou a gravação: ${JSON.stringify(payload)}`);
  await page.waitForTimeout(250);
  await page.evaluate(() => {
    document.querySelectorAll('div.modal.show').forEach((element) => {
      const modal = window.bootstrap?.Modal?.getInstance(element);
      if (modal) modal.hide();
      else element.classList.remove('show');
    });
    document.querySelectorAll('.modal-backdrop').forEach((element) => element.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
  });
  await page.waitForFunction(() => !Array.from(document.querySelectorAll('.grid-loading-modal')).some((element) => {
    const style = window.getComputedStyle(element);
    return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
  }), null, { timeout });
  await page.locator('.grid-ajax-message').waitFor({ state: 'visible', timeout });
  await page.locator('.permission-grid-fragment').waitFor({ state: 'visible', timeout });
  const savedValue = await row.locator('[data-column="pode_visualizar"] input[type="checkbox"]').isChecked();
  if (savedValue !== value) throw new Error(`Permissão não confirmou o valor esperado: esperado=${value ? 1 : 0}, atual=${savedValue ? 1 : 0}`);
  return true;
}

async function main() {
  const browser = cdp
    ? await chromium.connectOverCDP(cdp)
    : await firefox.launch({ headless: !visible });
  const context = cdp ? (browser.contexts()[0] || await browser.newContext({ viewport: { width: 1440, height: 900 } })) : browser;
  const page = cdp ? (context.pages()[0] || await context.newPage()) : await context.newPage({ viewport: { width: 1440, height: 900 } });
  page.setDefaultTimeout(timeout);
  let originalValue = true;
  let changed = false;
  try {
    await login(page, adminUsuario, adminSenha);
    await openPermissionPage(page);
    let row = await findPermissionRow(page, 'GESTOR', 'REQUISITOS_LEGAIS', '48');
    check('Permissão REQUISITOS_LEGAIS do perfil GESTOR localizada', Boolean(row));
    originalValue = await row.locator('[data-column="pode_visualizar"] input[type="checkbox"]').isChecked();
    check('Permissão original registrada', true, `pode_visualizar=${originalValue ? 1 : 0}`);
    if (!originalValue) {
      changed = true;
      await savePermission(page, row, true);
      await screenshot(page, '06-admin-permissao-requisitos-concedida');
      check('Permissão temporária concedida pelo admin', true);
      await logout(page);
      await login(page, restrictedUsuario, restrictedSenha);
      await page.locator('[data-sidebar-toggle]').click().catch(() => {});
      await page.locator('#menuSearch').fill('Requisitos legais');
      await page.waitForTimeout(800);
      check('Menu-pai Modulo Legal aparece apos concessao', await page.locator('[data-menu-parent]:visible').filter({ hasText: 'Módulo Legal' }).count() === 1);
      check('Menu Requisitos Legais aparece após concessão', await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]:visible').count() === 1);
      await logout(page);
      await login(page, adminUsuario, adminSenha);
      await openPermissionPage(page);
      row = await findPermissionRow(page, 'GESTOR', 'REQUISITOS_LEGAIS', '48');
      await savePermission(page, row, false);
    } else {
      changed = true;
      await savePermission(page, row, false);
    }
    await screenshot(page, '06-admin-permissao-requisitos-revogada');
    check('Permissão temporária removida pelo admin', true);

    await logout(page);
    await login(page, restrictedUsuario, restrictedSenha);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Requisitos legais');
    await page.waitForTimeout(800);
    const menuItem = page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]');
    check('Menu Requisitos Legais oculto sem permissão', await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]:visible').count() === 0);
    await screenshot(page, '07-ueditor-menu-requisitos-bloqueado');
    const directResponse = await page.goto(`${baseUrl}/principal/modulolegal_requisitoslegais.php`, { waitUntil: 'networkidle' });
    const directBody = await page.locator('body').innerText().catch(() => '');
    check('Acesso direto ao requisito bloqueado', (directResponse?.status() || 0) === 403 || /sem permissão|acesso negado/i.test(directBody),
      `HTTP ${directResponse?.status() || 0}`);
    await screenshot(page, '08-ueditor-acesso-direto-bloqueado');
    result.status = 'APROVADO';
  } catch (error) {
    result.error = error.message;
    try { await screenshot(page, 'falha-permissao-requisitos'); } catch (_) {}
    throw error;
  } finally {
    if (changed) {
      try {
        await logout(page);
        await login(page, adminUsuario, adminSenha);
        await openPermissionPage(page);
        const row = await findPermissionRow(page, 'GESTOR', 'REQUISITOS_LEGAIS', '48');
        if (row) await savePermission(page, row, originalValue);
        console.log(`PASS - Permissão original restaurada: ${originalValue ? 1 : 0}`);
      } catch (restoreError) {
        result.restoreError = restoreError.message;
        result.status = 'REPROVADO';
        console.error(`ERRO - Falha ao restaurar permissão: ${restoreError.message}`);
      }
    }
    result.finishedAt = new Date().toISOString();
    fs.writeFileSync(path.join(outputDir, 'resultado-permissoes.json'), JSON.stringify(result, null, 2), 'utf8');
    if (!cdp) await browser.close();
  }
}
main().catch((error) => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

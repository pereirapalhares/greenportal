const { chromium, firefox } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 ? (args[i + 1] || fallback) : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'); const usuario = arg('--usuario', 'admin'); const senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
function check(name, ok, detail = '') { console.log((ok ? 'PASS' : 'FAIL') + ' - ' + name + (detail ? ': ' + detail : '')); if (!ok) throw new Error(name + (detail ? ': ' + detail : '')); }
async function confirm(page) { const popup = page.locator('[data-popup-mensagem].show'); await popup.waitFor({ state: 'visible' }); await popup.locator('[data-popup-mensagem-confirm]').evaluate(element => element.click()); }
async function save(page, frame) { const button = frame.locator('[data-save-ia-prompt]'); await button.evaluate(element => element.click()); const responsePromise = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('setup_iaprompt.php')); await confirm(page); return await responsePromise; }
async function setField(row, lang, value) { await row.locator('[data-lang="' + lang + '"]').evaluate((element, text) => { element.value = text; element.dispatchEvent(new Event('input', { bubbles: true })); element.dispatchEvent(new Event('change', { bubbles: true })); }, value); }
async function launchBrowser() { const attempts = [{ type: chromium, options: { headless: true } }, { type: chromium, options: { headless: true, channel: 'chrome' } }, { type: firefox, options: { headless: true } }]; let lastError; for (const attempt of attempts) { try { return await attempt.type.launch(attempt.options); } catch (error) { lastError = error; } } throw new Error('Nao foi possivel iniciar Chromium/Chrome/Firefox: ' + (lastError ? lastError.message : 'erro desconhecido')); }
async function main() {
  const browser = await launchBrowser();
  const page = await browser.newPage(); page.setDefaultTimeout(30000); let original = null; let frame;
  try {
    await page.goto(baseUrl + '/index.php', { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
    check('Login administrador realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); const search = page.locator('#menuSearch'); await search.waitFor({ state: 'visible' }); await search.fill('Prompts de IA');
    const menu = page.locator('button[data-open-tab][data-tab-url*="setup_iaprompt.php"]'); await page.waitForTimeout(500); check('Menu Prompts de IA localizado', await menu.count() === 1); await menu.evaluate(element => element.click());
    const iframe = page.locator('#workspaceTabPanes iframe').last(); await iframe.waitFor({ state: 'attached' }); frame = page.frameLocator('#workspaceTabPanes iframe').last(); await frame.locator('#iaPromptGrid').waitFor({ state: 'attached' });
    const row = frame.locator('#iaPromptGrid tbody tr').first(); await row.waitFor({ state: 'attached' }); await row.evaluate(element => element.click());
    const fields = ['pt', 'en', 'es']; original = {}; for (const lang of fields) original[lang] = await row.locator('[data-lang="' + lang + '"]').inputValue();
    const oldVersion = Number((await row.locator('[data-column="versao"]').innerText()).trim());
    check('Prompt existente selecionado', original.pt.length > 0 && original.en.length > 0 && original.es.length > 0); check('Versao inicial identificada', Number.isFinite(oldVersion));
    for (const lang of fields) await setField(row, lang, original[lang] + '\n\nTeste controlado de historico ' + Date.now() + '.');
    const validResponse = await save(page, frame); check('Alteracao valida aceita', validResponse.status() === 200);
    const newVersion = Number((await row.locator('[data-column="versao"]').innerText()).trim()); check('Versao incrementada', newVersion > oldVersion, oldVersion + ' -> ' + newVersion);
    await frame.locator('[data-view-ia-history]').click(); const history = page.locator('#greenPortalTransacaoModal.show'); await history.waitFor({ state: 'visible' });
    check('Historico aberto', await history.locator('tbody tr').count() > 0); check('Historico registra nova versao', (await history.innerText()).includes(String(newVersion))); await history.locator('.btn-close').click();
    await setField(row, 'pt', 'prompt invalido'); const invalidResponse = await save(page, frame); check('Prompt invalido rejeitado', invalidResponse.status() === 422);
    const errorPopup = page.locator('[data-popup-mensagem].show'); await errorPopup.waitFor({ state: 'visible' }); check('Mensagem de validacao exibida', /prompt|potenciais|obrigat/i.test(await errorPopup.innerText())); errorPopup.locator('[data-popup-mensagem-confirm]').click().catch(() => {});
  } finally {
    if (original && frame) {
      try { const row = frame.locator('#iaPromptGrid tbody tr').first(); for (const lang of ['pt', 'en', 'es']) await setField(row, lang, original[lang]); await save(page, frame); console.log('PASS - Prompt original restaurado'); } catch (error) { console.error('WARN - Nao foi possivel restaurar o prompt: ' + error.message); }
    }
    await browser.close();
  }
}
main().catch(error => { console.error('ERRO FATAL: ' + (error.stack || error)); process.exitCode = 1; });

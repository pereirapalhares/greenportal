const { chromium, firefox } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 ? (args[i + 1] || fallback) : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', 'admin');
const senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const timeout = Number(arg('--timeout', '30000'));
function check(name, ok, detail = '') { console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name + (detail ? `: ${detail}` : '')); }
async function main() {
  let browser; try { browser = await chromium.launch({ headless: true }); } catch (_) { browser = await firefox.launch({ headless: true }); }
  const context = await browser.newContext(); const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login administrador realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); const search = page.locator('#menuSearch'); await search.waitFor({ state: 'visible' }); await search.fill('Prompts de IA');
    const menu = page.locator('button[data-open-tab][data-tab-url*="setup_iaprompt.php"]'); await page.waitForTimeout(500); check('Menu Prompts de IA visível para administrador', await menu.count() === 1); await menu.click();
    const iframe = page.locator('#workspaceTabPanes iframe').last(); await iframe.waitFor({ state: 'attached' }); const iframeUrl = await iframe.getAttribute('src'); check('Aba de Prompts de IA criada', !!iframeUrl && iframeUrl.includes('setup_iaprompt.php'), iframeUrl || 'URL não identificada'); const frame = page.frameLocator('#workspaceTabPanes iframe').last(); await frame.locator('body').waitFor({ state: 'visible' }); const bodyText = await frame.locator('body').innerText(); check('Tela de prompts sem erro de acesso', !/Acesso permitido|Fatal error|PDOException|Sessão expirada/i.test(bodyText), bodyText.slice(0, 180)); await frame.locator('.ia-prompt-grid-fragment').waitFor({ state: 'attached' });
    check('Tela setup_iaprompt carregada', await frame.locator('#iaPromptGrid').count() === 1); check('Prompt cadastrado no grid', await frame.locator('#iaPromptGrid tbody tr').count() > 0); check('Coluna PT disponível', await frame.locator('[data-column="prompt_pt"]').count() > 0); check('Coluna EN disponível', await frame.locator('[data-column="prompt_en"]').count() > 0); check('Coluna ES disponível', await frame.locator('[data-column="prompt_es"]').count() > 0); check('Botão salvar acima do grid', await frame.locator('[data-save-ia-prompt]').count() === 1); check('Botão histórico acima do grid', await frame.locator('[data-view-ia-history]').count() === 1); check('Não existe inclusão ou exclusão', await frame.locator('[data-new],[data-delete],[data-new-ia-prompt],[data-delete-ia-prompt]').count() === 0);
  } finally { await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error}`); process.exitCode = 1; });

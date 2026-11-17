const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
function arg(name, fallback = '') { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; }
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const admUsuario = arg('--adm-usuario', 'gestor');
const gestorUsuario = arg('--gestor-usuario', 'ueditor');
const senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const timeout = Number(arg('--timeout', '30000'));
const cdp = arg('--cdp', '');
const saida = path.resolve(arg('--saida', 'tests/evidencias_permissao_controle_documentos'));
fs.mkdirSync(saida, { recursive: true });
const resultado = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(nome, ok, detalhes = '') { resultado.checks.push({ nome, ok: Boolean(ok), detalhes: String(detalhes) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${nome}${detalhes ? `: ${detalhes}` : ''}`); if (!ok) throw new Error(`${nome}${detalhes ? `: ${detalhes}` : ''}`); }
async function login(page, usuario) {
  await page.goto(`${baseUrl}/index.php?usuario=${encodeURIComponent(usuario)}`, { waitUntil: 'networkidle' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click();
  await page.waitForURL(/\/principal\/main\.php/, { timeout }); check(`Login ${usuario}`, true);
}
async function abrirControle(page) {
  await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Controle');
  const item = page.locator('button[data-open-tab][data-tab-url*="modulolegal_controle.php"]').first(); await item.waitFor({ state: 'visible' }); await item.click();
  const frame = page.frameLocator('iframe[src*="modulolegal_controle.php"]'); await frame.locator('.menu-grid-fragment.grid-ready').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await page.waitForTimeout(600); return frame;
}
async function testarAdm(page) {
  const frame = await abrirControle(page); const grid = frame.locator('#modulolegal_controleBatchGrid'); const row = grid.locator('tbody tr').first(); await row.click();
  check('ADM_DA_EMPRESA visualiza o Controle', await grid.count() === 1 && await grid.locator('tbody tr').count() > 0);
  check('ADM_DA_EMPRESA pode incluir', await frame.locator('.crud-toolbar [data-new-cadastro]').isEnabled());
  check('ADM_DA_EMPRESA pode excluir registro selecionado', await frame.locator('.crud-toolbar [data-delete-cadastro]').isEnabled());
  const rel = frame.locator('.crud-toolbar [data-control-relacionamento="aspectos_sociais"]'); await rel.click(); const modal = frame.locator('#controleDocumentosRelacionamentosModal'); await modal.waitFor({ state: 'visible' });
  check('ADM_DA_EMPRESA pode incluir associação', await modal.locator('[data-rel-add]').isEnabled()); check('ADM_DA_EMPRESA pode confirmar associação', await modal.locator('[data-rel-save]').isEnabled()); await modal.locator('.btn-close').click();
}
async function testarGestor(page) {
  const frame = await abrirControle(page); const grid = frame.locator('#modulolegal_controleBatchGrid'); const row = grid.locator('tbody tr').first(); await row.click();
  check('GESTOR visualiza o Controle', await grid.count() === 1 && await grid.locator('tbody tr').count() > 0);
  check('GESTOR não pode incluir documento', await frame.locator('.crud-toolbar [data-new-cadastro]').isDisabled()); check('GESTOR não pode excluir documento', await frame.locator('.crud-toolbar [data-delete-cadastro]').isDisabled());
  const rel = frame.locator('.crud-toolbar [data-control-relacionamento="impactos_sociais"]'); check('GESTOR não pode abrir edição da associação', await rel.isDisabled());
  const documentId = Number(await row.getAttribute('data-key'));
  const api = await page.evaluate(async ({ documentId }) => { const fd = new FormData(); fd.append('relacionamento', 'impactos_sociais'); fd.append('coddocumento', String(documentId)); fd.append('action', 'sync'); fd.append('relation_ids', '[]'); const response = await fetch('api/controle_documentos_relacionamentos.php', { method: 'POST', body: fd }); return { status: response.status, body: await response.text() }; }, { documentId });
  check('Backend bloqueia alteração do GESTOR', api.status === 403 && /permiss/i.test(api.body), `HTTP ${api.status}`);
}
async function main() { const browser = cdp ? await chromium.connectOverCDP(cdp) : await chromium.launch({ headless: true, channel: 'chrome' }); const context = cdp ? (browser.contexts()[0] || await browser.newContext({ viewport: { width: 1440, height: 900 } })) : await browser.newContext({ viewport: { width: 1440, height: 900 } }); const page = cdp ? (context.pages()[0] || await context.newPage()) : await context.newPage(); page.setDefaultTimeout(timeout); try { await login(page, admUsuario); await testarAdm(page); await page.goto(`${baseUrl}/credenciais/sair.php`).catch(() => {}); await login(page, gestorUsuario); await testarGestor(page); resultado.status = 'APROVADO'; } catch (error) { resultado.error = error.message; throw error; } finally { resultado.finishedAt = new Date().toISOString(); fs.writeFileSync(path.join(saida, 'resultado.json'), JSON.stringify(resultado, null, 2), 'utf8'); if (!cdp) await browser.close(); } }
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

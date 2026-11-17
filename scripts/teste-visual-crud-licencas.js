const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', '');
const senha = arg('--senha', '');
const saida = path.resolve(arg('--saida', 'tests/evidencias_crud_visual_licencas'));
const visivel = args.includes('--visivel');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
fs.mkdirSync(saida, { recursive: true });
const resultado = { startedAt: new Date().toISOString(), visual: true, checks: [], screenshots: [], status: 'REPROVADO' };
function check(nome, ok, detalhe = '') { resultado.checks.push({ name: nome, ok: !!ok, details: detalhe }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${nome}${detalhe ? `: ${detalhe}` : ''}`); if (!ok) throw new Error(nome + (detalhe ? `: ${detalhe}` : '')); }
async function shot(page, nome) { const arquivo = path.join(saida, `${nome}.png`); await page.screenshot({ path: arquivo, fullPage: true }); resultado.screenshots.push(arquivo); console.log(`EVIDENCIA - ${arquivo}`); }

async function login(page) {
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
  await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
}

async function main() {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const context = await browser.newContext({ viewport: { width: 1600, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  let navegacoes = 0; page.on('framenavigated', frame => { if (frame === page.mainFrame()) navegacoes += 1; });
  try {
    await login(page); check('Login realizado', /main\.php/.test(page.url()), page.url()); await shot(page, '01-login');
    await page.locator('[data-sidebar-toggle]').click().catch(() => {});
    await page.locator('#menuSearch').fill('Licenças').catch(() => page.locator('#menuSearch').fill('Licencas'));
    const menu = page.locator('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]');
    check('Item de menu Licenças disponível', await menu.count() === 1); await menu.click();
    const tela = page.frameLocator('iframe[src*="manutencaolegal_licencas.php"]');
    const fragment = tela.locator('.licencas-grid-fragment'); const grid = tela.locator('#manutencaolegal_licencasBatchGrid');
    await fragment.waitFor({ state: 'visible' });
    await tela.locator('.licencas-grid-fragment.grid-ready').waitFor({ state: 'visible' });
    const rowsAntes = await grid.locator('tbody tr').count(); check('Grid de licenças carregado', rowsAntes > 0, `${rowsAntes} linhas`);
    check('Colunas de datas visíveis', await grid.locator('thead th[data-column="DATA_VALIDADE"],thead th[data-column="DATA_REACAO"],thead th[data-column="DATA_PROTOCOLO"],thead th[data-column="PROTOCOLADO_EM"]').count() === 4);
    const row = grid.locator('tbody tr').first(); await row.click();
    const calendar = row.locator('.calendar-picker').first(); check('Ícone de calendário visível', await calendar.count() === 1); await shot(page, '02-grid-calendario');
    const dateInput = calendar.locator('xpath=..').locator('input').first();
    await calendar.click();
    check('Campo de data preparado para calendário', await dateInput.getAttribute('type') === 'date');
    await dateInput.evaluate(input => { input.blur(); });
    const arquivos = fragment.locator('[data-license-arquivos]'); check('Botão Arquivos habilitado após seleção', await arquivos.isEnabled());
    const arquivoIcone = row.locator('[data-ml-file-button]').first();
    check('Ícone Arquivos presente na coluna', await arquivoIcone.count() === 1);
    await arquivoIcone.click();
    const popup = page.locator('[data-popup-transacao].show:visible').last(); await popup.waitFor({ state: 'visible' });
    check('Popup independente de Arquivos aberto', await popup.locator('iframe').count() === 1);
    check('Popup aponta para arquivos da licença', /gestao_arquivos\.php\?tipo=licencas/i.test(await popup.locator('iframe').getAttribute('src')));
    await shot(page, '03-popup-arquivos'); await popup.locator('.btn-close').click(); await popup.waitFor({ state: 'hidden' });
    const form = tela.locator('#manutencaolegal_licencasGridForm'); const campo = row.locator('[data-column="OBSERVACAO"] textarea, [data-column="OBSERVACAO"] input').first();
    const original = await campo.inputValue(); await campo.fill(original + ' teste visual'); await campo.fill(original);
    const save = fragment.locator('[data-crud-action="save"]').first(); const navegacoesAntes = navegacoes; await save.click();
    const response = await page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('manutencaolegal_licencas.php'), { timeout });
    check('Salvamento enviado', response.ok() || response.status() === 302, `${response.status()} ${response.url()}`);
    await fragment.locator('[data-license-save-message]').waitFor({ state: 'visible', timeout });
    const confirmacao = page.locator('#cadastroConfirmModal.show:visible');
    check('Modal de confirmação fechado após salvar', await confirmacao.count() === 0);
    await fragment.waitFor({ state: 'visible', timeout });
    check('Grid permaneceu visível após salvar', await grid.isVisible()); check('Página não foi recarregada', navegacoes === navegacoesAntes);
    check('Quantidade de linhas preservada', await grid.locator('tbody tr').count() === rowsAntes); await shot(page, '04-apos-salvar');
    resultado.status = 'APROVADO';
  } catch (error) { resultado.error = error.message; try { await shot(page, 'falha'); } catch (_) {} throw error; }
  finally { resultado.finishedAt = new Date().toISOString(); fs.writeFileSync(path.join(saida, 'resultado.json'), JSON.stringify(resultado, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

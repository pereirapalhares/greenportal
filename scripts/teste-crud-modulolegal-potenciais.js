const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const adminUsuario = arg('--admin-usuario', 'admin');
const admEmpresaUsuario = arg('--adm-empresa-usuario', 'ueditor');
const gestorUsuario = arg('--gestor-usuario', 'gestor');
const timeout = Number(arg('--timeout', '30000'));
const cdp = arg('--cdp', '');
const visual = args.includes('--visual');
const mutar = args.includes('--mutar');
const prefix = arg('--fixture-prefix', '');
const saida = path.resolve(arg('--saida', visual ? 'tests/evidencias_modulolegal_potenciais' : 'tests/resultado-nao-visual-modulolegal-potenciais.json'));
const pasta = visual ? saida : path.dirname(saida);
fs.mkdirSync(pasta, { recursive: true });

const resultado = { startedAt: new Date().toISOString(), visual, mutar, checks: [], screenshots: [], status: 'REPROVADO' };
function check(nome, ok, detalhes = '') {
  resultado.checks.push({ nome, ok: Boolean(ok), detalhes });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${nome}${detalhes ? `: ${detalhes}` : ''}`);
  if (!ok) throw new Error(`${nome}${detalhes ? `: ${detalhes}` : ''}`);
}
async function screenshot(page, nome) {
  if (!visual) return;
  const arquivo = path.join(pasta, `${nome}.png`);
  await page.screenshot({ path: arquivo, fullPage: true });
  resultado.screenshots.push(arquivo);
}
async function login(page, usuario) {
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario);
  await page.locator('#senha').fill(senha);
  await page.locator('form.login-form button[type="submit"]').click();
  await page.waitForURL(/\/principal\/main\.php/, { timeout });
  check(`Login ${usuario}`, true);
}
async function logout(page) { await page.goto(`${baseUrl}/credenciais/sair.php`, { waitUntil: 'domcontentloaded' }).catch(() => {}); }
async function abrirGrid(page, usuario) {
  await page.locator('[data-sidebar-toggle]').click().catch(() => {});
  const busca = page.locator('#menuSearch');
  await busca.fill('Potenciais');
  const menu = page.locator('button[data-open-tab][data-tab-url*="modulolegal_potenciais.php"]').first();
  await menu.waitFor({ state: 'visible', timeout });
  await menu.click();
  const frame = page.locator('iframe[src*="modulolegal_potenciais.php"]:visible').last().contentFrame();
  await frame.locator('[data-testid="requests-grid"]').waitFor({ state: 'visible', timeout });
  check(`${usuario}: grid de potenciais abriu`, true);
  return frame;
}
async function validarLayout(frame, usuario) {
  const requests = frame.locator('[data-testid="requests-grid"]');
  const potentials = frame.locator('[data-testid="potentials-grid"]');
  check(`${usuario}: grid de solicitações visível`, await requests.count() === 1);
  check(`${usuario}: grid de potenciais visível`, await potentials.count() === 1);
  check(`${usuario}: nenhum botão dentro dos grids`, await requests.locator('button').count() + await potentials.locator('button').count() === 0);
  check(`${usuario}: botões de aprovação acima do grid`, await frame.locator('[data-action="approve"]').count() === 1 && await frame.locator('[data-action="reject"]').count() === 1);
  check(`${usuario}: idioma exibido na solicitação`, await requests.locator('thead').innerText().then(texto => /Idioma|Language|Idioma/i.test(texto)));
}
async function solicitar(page, frame, usuario) {
  const select = frame.locator('#codlegislacao');
  const option = await select.locator('option').evaluateAll(items => items.map(item => item.value).find(value => value));
  check(`${usuario}: requisito legal disponível para solicitação`, Boolean(option));
  await select.selectOption(option);
  const requestResponse = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('modulolegal_potenciais.php'), { timeout });
  await frame.locator('[data-testid="request-ai"]').click();
  const response = await requestResponse;
  check(`${usuario}: resposta da solicitação recebida`, response.ok(), `HTTP ${response.status()}`);
  await page.waitForTimeout(500);
  await frame.locator('[data-testid="requests-grid"] tbody tr').first().waitFor({ state: 'visible', timeout });
  const texto = await frame.locator('[data-testid="requests-grid"] tbody').innerText();
  check(`${usuario}: solicitação registrada`, /Pendente|Pending|Pendiente|Processando|Processing|Procesando|Concluída|Completed|Concluida|Erro|Error/i.test(texto));
}
async function validarPotencial(page, frame, usuario, action, wantedText) {
  const row = frame.locator('[data-testid="potentials-grid"] tbody tr').filter({ hasText: wantedText }).first();
  await row.waitFor({ state: 'visible', timeout });
  const radio = row.locator('input[name="selected_potential"]');
  await radio.setChecked(true, { force: true });
  check(`${usuario}: potencial de fixture selecionado`, await radio.isChecked());
  const button = frame.locator(`[data-action="${action}"]`);
  await button.click();
  const modal = frame.locator('#confirmModal');
  await modal.waitFor({ state: 'visible', timeout });
  check(`${usuario}: popup de confirmação para ${action}`, await modal.locator('#confirmText').innerText().then(texto => texto.length > 0));
  const validationResponse = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('modulolegal_potenciais.php'), { timeout });
  await modal.locator('#validationForm button[type="submit"]').click();
  const response = await validationResponse;
  check(`${usuario}: resposta da validação recebida`, response.ok(), `HTTP ${response.status()}`);
  await page.waitForTimeout(500);
  await frame.locator('[data-testid="potentials-grid"]').waitFor({ state: 'visible', timeout });
  const novoStatus = await frame.locator('[data-testid="potentials-grid"] tbody tr').filter({ hasText: wantedText }).first().innerText();
  const esperado = action === 'approve' ? /Aprovado|Approved|Aprobado/i : /Rejeitado|Rejected|Rechazado/i;
  check(`${usuario}: potencial ${action === 'approve' ? 'aprovado' : 'rejeitado'}`, esperado.test(novoStatus));
}
async function testarPerfil(page, cfg) {
  await login(page, cfg.usuario);
  const frame = await abrirGrid(page, cfg.usuario);
  await validarLayout(frame, cfg.usuario);
  const requestButton = frame.locator('[data-testid="request-ai"]');
  const approveButton = frame.locator('[data-action="approve"]');
  const rejectButton = frame.locator('[data-action="reject"]');
  check(`${cfg.usuario}: permissão de visualização`, true);
  check(`${cfg.usuario}: botão solicitar ${cfg.canWrite ? 'habilitado' : 'desabilitado'}`, await requestButton.isEnabled() === cfg.canWrite);
  check(`${cfg.usuario}: botão aprovar ${cfg.canWrite ? 'habilitado' : 'desabilitado'}`, await approveButton.isEnabled() === cfg.canWrite);
  check(`${cfg.usuario}: botão rejeitar ${cfg.canWrite ? 'habilitado' : 'desabilitado'}`, await rejectButton.isEnabled() === cfg.canWrite);
  if (cfg.canWrite && mutar) {
    await solicitar(page, frame, cfg.usuario);
    if (cfg.action) await validarPotencial(page, frame, cfg.usuario, cfg.action, `${prefix}-${cfg.fixture}`);
  }
  await screenshot(page, `${cfg.fixture}-${cfg.usuario}`);
  await logout(page);
}
async function main() {
  const browser = cdp ? await chromium.connectOverCDP(cdp) : await chromium.launch({ headless: !visual, channel: 'chrome' });
  const context = cdp ? browser.contexts()[0] : await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await testarPerfil(page, { usuario: adminUsuario, canWrite: true, action: 'approve', fixture: 'aprovar' });
    await testarPerfil(page, { usuario: admEmpresaUsuario, canWrite: true, action: 'reject', fixture: 'rejeitar' });
    await testarPerfil(page, { usuario: gestorUsuario, canWrite: false, fixture: 'gestor' });
    resultado.status = 'APROVADO';
  } catch (error) {
    resultado.error = error.message;
    try { await screenshot(page, 'falha'); } catch (_) {}
    throw error;
  } finally {
    resultado.finishedAt = new Date().toISOString();
    fs.writeFileSync(visual ? path.join(pasta, 'resultado.json') : saida, JSON.stringify(resultado, null, 2), 'utf8');
    if (!cdp) await browser.close();
  }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

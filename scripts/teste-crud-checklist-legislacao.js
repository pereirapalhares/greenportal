const fs = require('fs');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const output = arg('--saida', 'tests/resultado-crud-checklist-legislacao.json');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha ou use GREENPORTAL_USUARIO/GREENPORTAL_SENHA.'); process.exit(2); }
const resultado = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { resultado.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function login(page) { await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa })); await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); }
async function waitGrid(page) { await page.locator('#manutencaolegalrequisitoslegais_checklistBatchGrid').waitFor({ state: 'visible' }); await page.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await page.waitForTimeout(600); }
async function confirmSave(page) { const confirm = page.locator('#greenPortalCadastroConfirm [data-confirmar], #cadastroConfirmModal [data-confirm-cadastro]').first(); await confirm.waitFor({ state: 'visible', timeout: 5000 }); await Promise.all([page.waitForLoadState('networkidle').catch(() => {}), confirm.click()]); }
function responseControl(row) { return row.locator('[data-column="ATENDE"] select, [data-column="ATENDE"] input').first(); }
async function setResponse(row, value) { const control = responseControl(row); if ((await control.evaluate(node => node.tagName)) === 'SELECT') await control.selectOption(value); else await control.fill(value); }
async function main() {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' }); const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
  let row; let original = null; let changed = false;
  try {
    await login(page); check('Login realizado', /main\.php/.test(page.url()));
    await page.goto(`${baseUrl}/principal/manutencaolegalrequisitoslegais_checklist.php?CODLEGISLACAO=856&CODUNIDADE=1`, { waitUntil: 'networkidle' }); await waitGrid(page);
    const grid = page.locator('#manutencaolegalrequisitoslegais_checklistBatchGrid'); row = grid.locator('tbody tr').first(); check('Registro de checklist localizado', await row.count() === 1);
    const response = responseControl(row); const observation = row.locator('[data-column="obs"] textarea');
    original = { response: await response.inputValue(), observation: await observation.inputValue() };
    const testResponse = original.response === 'S' ? 'N' : 'S'; const testObservation = `Teste automatizado de persistência ${Date.now()}`;
    await setResponse(row, testResponse); await observation.fill(testObservation); changed = true;
    await page.locator('[data-crud-action="save"]').click(); await confirmSave(page); await page.waitForTimeout(900);
    check('Salvamento do checklist concluído', /salvo=1/.test(page.url()) || await page.locator('.alert-success').count() > 0);
    const checklistUrl = `${baseUrl}/principal/manutencaolegalrequisitoslegais_checklist.php?CODLEGISLACAO=856&CODUNIDADE=1`;
    await page.goto(checklistUrl, { waitUntil: 'networkidle' }); await waitGrid(page);
    row = page.locator('#manutencaolegalrequisitoslegais_checklistBatchGrid tbody tr').first();
    check('Resposta persistida após recarregar', await responseControl(row).inputValue() === testResponse);
    check('Observação persistida após recarregar', await row.locator('[data-column="obs"] textarea').inputValue() === testObservation);
    resultado.status = 'APROVADO';
  } catch (e) { resultado.error = e.message; throw e; }
  finally {
    if (changed && original && row) {
      try { await setResponse(row, original.response); await row.locator('[data-column="obs"] textarea').fill(original.observation); await page.locator('[data-crud-action="save"]').click(); await confirmSave(page); await page.waitForTimeout(700); resultado.restored = true; } catch (restoreError) { resultado.restoreError = restoreError.message; }
    }
    resultado.finishedAt = new Date().toISOString(); fs.writeFileSync(output, JSON.stringify(resultado, null, 2), 'utf8'); await browser.close();
  }
}
main().catch(e => { console.error(`ERRO FATAL: ${e.stack || e.message}`); process.exit(1); });

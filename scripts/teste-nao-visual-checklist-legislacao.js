const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', process.env.GREENPORTAL_EMPRESA || 'Modelo');
const usuario = arg('--usuario', process.env.GREENPORTAL_USUARIO || '');
const senha = arg('--senha', process.env.GREENPORTAL_SENHA || '');
const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha ou use GREENPORTAL_USUARIO/GREENPORTAL_SENHA.'); process.exit(2); }
const resultado = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { resultado.checks.push({ name, ok: !!ok, details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
async function cellValue(locator) { return locator.evaluate(node => { const control = node.querySelector('input, select, textarea'); return String(control ? (control.value || control.selectedOptions?.[0]?.textContent || '') : node.textContent || '').trim(); }); }

async function login(page) {
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
  await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
  await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/);
}

async function main() {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
  try {
    await login(page); check('Login realizado', /main\.php/.test(page.url()), page.url());
    const rel = await page.goto(`${baseUrl}/principal/manutencaolegal_legislacaoxempresa.php?filtros=${encodeURIComponent(JSON.stringify({ CODEMPRESA: 4 }))}&busca=LIC0088`, { waitUntil: 'networkidle' });
    check('Tela de relacionamento carregada', rel && rel.ok(), String(rel && rel.status()));
    const relationGrid = page.locator('#legislacaoEmpresaBatchGrid');
    const relationCodes = await relationGrid.locator('tbody [data-column="CODIGO"]').evaluateAll(cells => cells.map(cell => { const input = cell.querySelector('input'); return String(input ? input.value : cell.textContent || '').trim(); }));
    check('LIC0088 disponível no relacionamento', relationCodes.some(code => code.trim() === 'LIC0088'), relationCodes.join(','));
    await page.goto(`${baseUrl}/principal/manutencaolegal_legislacaoxempresa.php?filtros=${encodeURIComponent(JSON.stringify({ CODEMPRESA: 4 }))}&busca=LIC0099`, { waitUntil: 'networkidle' });
    const revokedGrid = page.locator('#legislacaoEmpresaBatchGrid');
    const revokedCodes = await revokedGrid.locator('tbody [data-column="CODIGO"]').evaluateAll(cells => cells.map(cell => { const input = cell.querySelector('input'); return String(input ? input.value : cell.textContent || '').trim(); }));
    check('LIC0099 não aparece na seleção de legislações', !revokedCodes.some(code => code === 'LIC0099'), revokedCodes.join(','));

    const checklist = await page.goto(`${baseUrl}/principal/manutencaolegalrequisitoslegais_checklist.php?CODLEGISLACAO=856&CODUNIDADE=1`, { waitUntil: 'networkidle' });
    check('Checklist carregado', checklist && checklist.ok(), String(checklist && checklist.status()));
    const grid = page.locator('#manutencaolegalrequisitoslegais_checklistBatchGrid');
    check('Grid do checklist encontrado', await grid.count() === 1);
    const columns = await grid.locator('thead th').evaluateAll(ths => ths.map(th => th.dataset.column));
    for (const column of ['CODIGO', 'EMPRESA', 'UNIDADE', 'PERGUNTA', 'ATENDE', 'ATENDIMENTO', 'obs']) check(`Coluna ${column} presente`, columns.includes(column), columns.join(','));
    const first = grid.locator('tbody tr').first();
    const company = await cellValue(first.locator('[data-column="EMPRESA"]')); const unit = await cellValue(first.locator('[data-column="UNIDADE"]'));
    check('Checklist contém empresa e unidade', company.includes('Modelo') && unit.includes('Modelo CA'), `${company} / ${unit}`);
    check('Checklist contém pergunta associada', (await cellValue(first.locator('[data-column="PERGUNTA"]'))).length > 0);
    check('Checklist contém atendimento', (await cellValue(first.locator('[data-column="ATENDIMENTO"]'))).length > 0);
    resultado.status = 'APROVADO';
  } catch (e) { resultado.error = e.message; throw e; }
  finally { resultado.finishedAt = new Date().toISOString(); require('fs').writeFileSync(arg('--saida', 'tests/resultado-nao-visual-checklist-legislacao.json'), JSON.stringify(resultado, null, 2)); await browser.close(); }
}
main().catch(e => { console.error(`ERRO FATAL: ${e.stack || e.message}`); process.exit(1); });

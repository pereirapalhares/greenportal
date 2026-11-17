const { chromium } = require('playwright');

const args = process.argv.slice(2);
const get = (name, fallback) => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const base = get('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = get('--empresa', 'Modelo');
const usuario = get('--usuario', 'admin');
const senha = get('--senha', '');
if (!senha) throw new Error('Informe --senha.');

function check(name, condition, detail = '') {
  console.log((condition ? 'PASS - ' : 'FAIL - ') + name + (detail ? ': ' + detail : ''));
  if (!condition) throw new Error(name);
}

async function login(page) {
  await page.goto(base + '/index.php', { waitUntil: 'domcontentloaded' });
  const company = page.locator('#empresa');
  await company.selectOption({ label: empresa }).catch(() => company.selectOption({ value: empresa }));
  await page.locator('#userid').fill(usuario);
  await page.locator('#senha').fill(senha);
  await page.locator('form.login-form button[type="submit"]').click();
  await page.waitForURL(/\/principal\/main\.php/);
  check('Login realizado', true);
}

async function inspectGrid(page, url, label) {
  const response = await page.goto(base + url, { waitUntil: 'networkidle' });
  check(label + ': resposta HTTP válida', !!response && response.status() < 400, 'HTTP=' + (response ? response.status() : 0));
  const grid = page.locator('.licencas-grid-fragment .menu-batch-grid');
  await grid.waitFor({ state: 'visible' });
  const rows = grid.locator('tbody tr[data-key]');
  const count = await rows.count();
  check(label + ': grid carregado', count >= 0, 'linhas=' + count);
  const responsible = grid.locator('tbody tr[data-key] [data-column="CODUSUARIO"] select');
  check(label + ': Responsável é combobox', count === 0 || await responsible.count() === count, 'comboboxes=' + await responsible.count());
  if (count > 0) {
    const blankOptions = await responsible.evaluateAll(selects => selects.every(select => select.options.length > 0 && select.options[0].value === ''));
    check(label + ': combobox possui opção vazia', blankOptions);
    const options = await responsible.first().locator('option').allTextContents();
    check(label + ': nomes de usuários disponíveis', options.some(text => text.trim().length > 0), 'opções=' + options.length);
  }
  return { keys: await rows.evaluateAll(items => items.map(row => row.getAttribute('data-key'))) };
}

async function main() {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  page.setDefaultTimeout(30000);
  try {
    await login(page);
    await inspectGrid(page, '/principal/manutencaolegal_licencas.php', 'Licenças');
    const mine = await inspectGrid(page, '/principal/minhas_licencas.php', 'Minhas Licenças');
    check('Minhas Licenças é uma transação própria', page.url().includes('/principal/minhas_licencas.php'));
    const scope = await page.locator('.licencas-grid-fragment').getAttribute('data-context-filters');
    const scopeData = JSON.parse(scope || '{}');
    check('Minhas Licenças aplica filtro obrigatório do usuário', Number(scopeData.CODUSUARIO || 0) > 0, 'CODUSUARIO=' + (scopeData.CODUSUARIO || ''));
    const mineResponsibleValues = await page.locator('.licencas-grid-fragment .menu-batch-grid tbody tr[data-key] [data-column="CODUSUARIO"] select').evaluateAll(selects => selects.map(select => select.value).filter(Boolean));
    check('Minhas Licenças permanece restrita ao usuário logado', new Set(mineResponsibleValues).size <= 1, 'responsáveis=' + [...new Set(mineResponsibleValues)].join(',') + ', linhas=' + mine.keys.length);
  } finally {
    await browser.close();
  }
}
main().catch(error => { console.error('ERRO FATAL:', error.stack || error.message); process.exit(1); });

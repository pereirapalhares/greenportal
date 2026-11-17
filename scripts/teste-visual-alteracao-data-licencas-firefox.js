const { firefox } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 ? (args[i + 1] || fallback) : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'), usuario = arg('--usuario', 'admin'), senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const timeout = Number(arg('--timeout', '30000')), visivel = args.includes('--visivel');
function check(name, ok, detail = '') { console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name + (detail ? `: ${detail}` : '')); }
const decode = value => String(value || '').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');
function isoDate(value) { const m = String(value || '').match(/^(\d{2})\/(\d{2})\/(\d{4})$/); return m ? `${m[3]}-${m[2]}-${m[1]}` : value; }
async function main() {
  const browser = await firefox.launch({ headless: !visivel });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, javaScriptEnabled: false });
  const page = await context.newPage(); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'domcontentloaded', timeout });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }), page.locator('form.login-form button[type="submit"]').click()]);
    check('Login realizado', /\/principal\/main\.php/.test(page.url()));
    const response = await page.goto(`${baseUrl}/principal/manutencaolegal_licencas.php`, { waitUntil: 'domcontentloaded', timeout });
    const html = response ? await response.text() : '';
    const grid = (html.match(/<table[^>]+id=["']manutencaolegal_licencasBatchGrid["'][\s\S]*?<\/table>/i) || [''])[0];
    const body = (grid.match(/<tbody[\s\S]*?<\/tbody>/i) || [''])[0];
    const row = (body.match(/<tr\b[\s\S]*?<\/tr>/i) || [''])[0];
    check('Grid de Licencas carregado', grid.length > 0); const dateInput = (row.match(/<input\b[^>]*DATA_VALIDADE[^>]*>/i) || [''])[0]; check('Campo DATA_VALIDADE encontrado', dateInput.length > 0, dateInput); check('Campo preparado para calendario pelo grid', /DATA_VALIDADE/i.test(dateInput));
    check('Primeira licença localizada', row.length > 0);
    const form = { action: 'save_rows' }, controls = /<input\b[^>]*name=["']([^"']+)["'][^>]*>/gi;
    let match, dateName = '';
    while ((match = controls.exec(row))) { const attrs = match[0], name = decode(match[1]); let value = decode((attrs.match(/\bvalue=["']([^"']*)["']/i) || ['', ''])[1]); if (/\[(DATA_|PROTOCOLADO_EM)\]/.test(name)) value = isoDate(value); form[name] = value; if (name.includes('[DATA_VALIDADE]')) dateName = name; }
    check('Campo DATA_VALIDADE localizado', !!dateName);
    const changed = '2030-12-31'; form[dateName] = changed;
    const save = await context.request.post(page.url(), { form, timeout });
    check('Alteracao da data enviada', save.status() >= 200 && save.status() < 400, `${save.status()}`);
    const refreshed = await page.goto(`${baseUrl}/principal/manutencaolegal_licencas.php`, { waitUntil: 'domcontentloaded', timeout });
    const refreshedHtml = refreshed ? await refreshed.text() : '';
    check('Data alterada e mantida', /2030-12-31|31\/12\/2030|12\/31\/2030/.test(refreshedHtml));
    if (visivel) await page.screenshot({ path: 'tests/evidencia-visual-alteracao-data-licencas-firefox.png', fullPage: true });
  } catch (error) { if (visivel) await page.screenshot({ path: 'tests/evidencia-visual-alteracao-data-licencas-firefox-falha.png', fullPage: true }).catch(() => {}); throw error; } finally { await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error}`); process.exitCode = 1; });

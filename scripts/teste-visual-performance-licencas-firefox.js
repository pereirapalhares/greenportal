const { firefox } = require('playwright');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 ? (args[i + 1] || fallback) : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'), usuario = arg('--usuario', 'admin'), senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const timeout = Number(arg('--timeout', '30000')), visivel = args.includes('--visivel'), mutar = args.includes('--mutar');
function check(name, ok, detail = '') { console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name + (detail ? `: ${detail}` : '')); }
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
    console.log('INFO - Abrindo grid de Licencas');
    const gridResponse = await page.goto(`${baseUrl}/principal/manutencaolegal_licencas.php`, { waitUntil: 'domcontentloaded', timeout });
    await page.waitForTimeout(800);
    // Valida pelo HTML recebido; nao depende do DOM ativo do Firefox nem do JS do grid.
    const html = gridResponse ? await gridResponse.text() : '';
    const gridHtml = (html.match(/<table[^>]+id=["']manutencaolegal_licencasBatchGrid["'][\s\S]*?<\/table>/i) || [''])[0];
    const bodyHtml = (gridHtml.match(/<tbody[\s\S]*?<\/tbody>/i) || [''])[0];
    const headHtml = (gridHtml.match(/<thead[\s\S]*?<\/thead>/i) || [''])[0];
    const visual = { grid: gridHtml.length > 0, rows: (bodyHtml.match(/<tr\b/gi) || []).length, columns: (headHtml.match(/<th\b/gi) || []).length, responsibleCombo: /<select\b[^>]*data-column-filter=["']CODUSUARIO["']/i.test(headHtml), unitTextFilter: /<input\b[^>]*data-column-filter=["']DSCUNIDADE["']/i.test(headHtml), listTextFilter: /<input\b[^>]*data-column-filter=["']CODLISTA["']/i.test(headHtml), error: /Fatal error|Uncaught|PDOException|SQLSTATE\[/i.test(html) };
    check('Grid de Licencas visivel', visual.grid); check('Linhas carregadas', visual.rows > 0, `${visual.rows}`); check('Colunas carregadas', visual.columns >= 15, `${visual.columns}`);
    check('Responsavel sem combo pesado', !visual.responsibleCombo); check('Unidade com filtro textual', visual.unitTextFilter); check('Lista com filtro textual', visual.listTextFilter); check('Sem erro PHP', !visual.error);
    if (mutar) {
      console.log('INFO - Alterando a primeira licenca');
      const firstRow = (bodyHtml.match(/<tr\b[\s\S]*?<\/tr>/i) || [''])[0];
      const decode = value => value.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');
      const form = { action: 'save_rows' };
      const controls = /<(input|textarea|select)\b[^>]*name=["']([^"']+)["'][^>]*>([\s\S]*?<\/select>)?/gi;
      let match; let original = '';
      while ((match = controls.exec(firstRow))) {
        const tag = match[1].toLowerCase(), attrs = match[0], name = decode(match[2]); let value = '';
        if (tag === 'textarea') value = decode((attrs.match(/>([\s\S]*?)<\/textarea>/i) || ['', ''])[1]);
        else if (tag === 'select') { const selected = (attrs.match(/<option[^>]*selected[^>]*value=["']([^"']*)["']/i) || ['', ''])[1]; value = decode(selected); }
        else value = decode((attrs.match(/\bvalue=["']([^"']*)["']/i) || ['', ''])[1]);
        if (name.includes('[DSCLICENCA]')) original = value;
        if (name) form[name] = value;
      }
      const descriptionMatch = firstRow.match(/<textarea\b[^>]*name=["']([^"']*DSCLICENCA[^"']*)["'][^>]*>([\s\S]*?)<\/textarea>/i);
      if (descriptionMatch) { original = decode(descriptionMatch[2]); form[decode(descriptionMatch[1])] = original; }
      check('Campo de descricao localizado', original !== '');
      const value = `${original} - TESTE VISUAL ${Date.now()}`;
      const descriptionName = Object.keys(form).find(name => name.includes('[DSCLICENCA]'));
      form[descriptionName] = value;
      const saveResponse = await context.request.post(page.url(), { form, timeout });
      check('Solicitacao de alteracao enviada', saveResponse.status() >= 200 && saveResponse.status() < 400, `${saveResponse.status()}`);
      const refreshed = await page.goto(`${baseUrl}/principal/manutencaolegal_licencas.php`, { waitUntil: 'domcontentloaded', timeout });
      const refreshedHtml = refreshed ? await refreshed.text() : '';
      check('Descricao alterada e mantida', refreshedHtml.includes(value));
      console.log('INFO - Alteracao mantida intencionalmente para conferencia manual');
    }
    if (visivel) await page.screenshot({ path: 'tests/evidencia-visual-performance-licencas-firefox.png', fullPage: true });
  } catch (error) { if (visivel) await page.screenshot({ path: 'tests/evidencia-visual-performance-licencas-firefox-falha.png', fullPage: true }).catch(() => {}); throw error; } finally { await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error}`); process.exitCode = 1; });

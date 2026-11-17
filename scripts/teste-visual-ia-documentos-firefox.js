const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');

const base = 'http://localhost/greenportal';
const result = { navegador: 'Firefox', inicio: new Date().toISOString(), casos: [], status: 'REPROVADO' };
const evidence = path.resolve('tests/evidencias_visual_ia_documentos');
const check = (name, ok, detail = '') => { console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(`${name}${detail ? `: ${detail}` : ''}`); };

async function main() {
  fs.mkdirSync(evidence, { recursive: true });
  const browser = await firefox.launch({ headless: true, executablePath: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(30000);
  try {
    await page.goto(`${base}/index.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('#empresa').selectOption({ label: 'Modelo' }).catch(() => page.locator('#empresa').selectOption({ value: 'Modelo' }));
    await page.locator('#userid').fill('admin'); await page.locator('#senha').fill(process.env.GREEN_TEST_PASSWORD || '');
    await Promise.all([page.waitForURL(/\/principal\/main\.php/, { timeout: 30000 }), page.locator('form.login-form button[type="submit"]').click()]);
    check('Login visual realizado', true);
    for (const tipo of ['controles', 'infraestrutura', 'treinamentos']) {
      const url = `${base}/principal/gestao_arquivos.php?tipo=${tipo}&chave=${tipo === 'controles' ? 'CODDOCUMENTO' : tipo === 'infraestrutura' ? 'CODINFRAESTRUTURA' : 'CODTREINAMENTO'}&valor=1`;
      await page.goto(url, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(700);
      const caseResult = { tipo, url, arquivo: null, analise: 'NAO_EXECUTADA' };
      check(`${tipo}: tela carregada`, await page.locator('#gedGrid').isVisible());
      await page.screenshot({ path: path.join(evidence, `${tipo}-01-grid.png`), fullPage: true });
      const row = page.locator('#gedGrid tbody tr[data-name]').first();
      if (!(await row.count())) { caseResult.analise = 'SEM_ARQUIVO_PDF'; result.casos.push(caseResult); console.log(`INFO - ${tipo}: nenhum arquivo disponível`); continue; }
      caseResult.arquivo = await row.getAttribute('data-name'); await row.click();
      const analyze = page.locator('#gedAnalyze'); check(`${tipo}: botão IA visível`, await analyze.isVisible()); check(`${tipo}: botão IA habilitado`, await analyze.isEnabled());
      const responsePromise = page.waitForResponse(r => r.url().includes('/api/') && r.request().method() === 'POST' && (r.url().includes('ia.php')), { timeout: 120000 }).catch(() => null);
      await analyze.click(); const response = await responsePromise; await page.waitForTimeout(500);
      caseResult.analise = response ? `HTTP_${response.status()}` : 'SEM_RESPOSTA'; caseResult.mensagem = (await page.locator('#gedMessage').textContent()).trim();
      await page.screenshot({ path: path.join(evidence, `${tipo}-02-analise.png`), fullPage: true });
      console.log(`INFO - ${tipo}: ${caseResult.analise} - ${caseResult.mensagem}`); result.casos.push(caseResult);
    }
    result.status = result.casos.length === 3 && result.casos.every(c => c.analise !== 'SEM_RESPOSTA') ? 'APROVADO' : 'PARCIAL';
  } catch (error) { result.erro = error.message; console.error(`ERRO: ${error.message}`); } finally { result.fim = new Date().toISOString(); fs.writeFileSync('tests/resultado-visual-ia-documentos-firefox.json', JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
  if (result.status === 'REPROVADO') process.exitCode = 1;
}
main();

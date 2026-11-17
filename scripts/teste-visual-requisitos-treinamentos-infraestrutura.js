const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'); const usuario = arg('--usuario', ''); const senha = arg('--senha', '');
const output = path.resolve(arg('--saida', 'tests/resultado-requisitos-treinamentos-infraestrutura.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_requisitos_treinamentos_infraestrutura'));
const visual = args.includes('--visual'); const timeout = Number(arg('--timeout', '30000'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
const result = { startedAt: new Date().toISOString(), checks: [], screenshots: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { if (!visual) return; fs.mkdirSync(evidenceDir, { recursive: true }); const file = path.join(evidenceDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); console.log(`EVIDENCIA - ${file}`); }
async function waitFrame(frame) { await frame.locator('body').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await frame.locator('table').first().waitFor({ state: 'visible', timeout }); }

async function main() {
  const browser = await chromium.launch(visual ? { headless: false, channel: 'chrome' } : { headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
  try {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
    await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais');
    await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const req = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await req.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout });
    const row = req.locator('#requisitosLegaisBatchGrid tbody tr').filter({ hasText: 'LB0001' }).first(); check('Requisito legal selecionável', await row.count() === 1); await row.click(); await shot(page, '01-requisito-selecionado');
    for (const type of ['treinamentos', 'infraestrutura']) {
      const button = req.locator(`[data-open-requisito-popup="${type}"]`); check(`Botão ${type} habilitado`, await button.isEnabled()); await button.click();
      const popup = page.locator('#greenPortalTransacaoModal'); await popup.waitFor({ state: 'visible', timeout });
      const frame = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitFrame(frame);
      const title = await popup.locator('[data-popup-transacao-title]').innerText(); check(`Popup ${type} aberto`, title.includes(type === 'treinamentos' ? 'Trein' : 'Infra'), title);
      const body = await frame.locator('body').innerText(); check(`Grid ${type} sem erro`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i.test(body));
      check(`Grid ${type} possui barra de ações acima`, await frame.locator('.crud-toolbar').count() >= 1);
      check(`Grid ${type} não possui botões dentro das linhas`, await frame.locator('table tbody button').count() === 0);
      const expected = type === 'treinamentos' ? 'CODTREINAMENTO' : 'CODINFRAESTRUTURA';
      check(`Grid ${type} possui coluna de associação`, await frame.locator(`thead th[data-column="${expected}"]`).count() === 1);
      await shot(page, `02-${type}`); result.associacoes = result.associacoes || []; result.associacoes.push({ type, title, rows: await frame.locator('table tbody tr').count() });
      await popup.locator('.btn-close').click(); await popup.waitFor({ state: 'hidden', timeout });
    }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); try { await shot(page, 'falha'); } catch (_) {} throw error; }
  finally { fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

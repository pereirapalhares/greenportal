const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { spawnSync } = require('child_process');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo'); const usuario = arg('--usuario', ''); const senha = arg('--senha', '');
const timeout = Number(arg('--timeout', '30000')); const visual = args.includes('--visual');
const output = path.resolve(arg('--saida', 'tests/resultado-completo-requisitos-legais-associados.json'));
const evidenceDir = path.resolve(arg('--evidencias', 'tests/evidencias_completo_requisitos_legais_associados'));
if (!usuario || !senha) { console.error('Informe --usuario e --senha.'); process.exit(2); }
const result = { startedAt: new Date().toISOString(), baseUrl, requisito: 'LB0001', visual, checks: [], screenshots: [], associados: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(`${name}${details ? `: ${details}` : ''}`); }
async function shot(page, name) { if (!visual) return; fs.mkdirSync(evidenceDir, { recursive: true }); const file = path.join(evidenceDir, `${name}.png`); await page.screenshot({ path: file, fullPage: true }); result.screenshots.push(file); }
async function waitFrame(frame) { await frame.locator('body').waitFor({ state: 'visible', timeout }); await frame.locator('.grid-loading-modal').waitFor({ state: 'hidden', timeout }).catch(() => {}); await frame.locator('table').first().waitFor({ state: 'visible', timeout }); }
async function visibleColumns(frame) { return frame.locator('table thead th').evaluateAll(items => items.filter(x => getComputedStyle(x).display !== 'none').map(x => String(x.dataset.column || '').trim())); }

async function main() {
  let browser = null;
  try {
    browser = await chromium.launch(visual ? { headless: false, channel: 'chrome' } : { headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); page.setDefaultTimeout(timeout);
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' }); await page.locator('#empresa').selectOption({ label: empresa }).catch(() => page.locator('#empresa').selectOption({ value: empresa }));
    await page.locator('#userid').fill(usuario); await page.locator('#senha').fill(senha); await page.locator('form.login-form button[type="submit"]').click(); await page.waitForURL(/\/principal\/main\.php/); check('Login realizado', true);
    await page.locator('[data-sidebar-toggle]').click().catch(() => {}); await page.locator('#menuSearch').fill('Requisitos legais'); await page.locator('button[data-open-tab][data-tab-url*="modulolegal_requisitoslegais.php"]').click();
    const req = page.frameLocator('iframe[src*="modulolegal_requisitoslegais.php"]'); await req.locator('#requisitosLegaisBatchGrid tbody tr').first().waitFor({ state: 'visible', timeout });
    const row = req.locator('#requisitosLegaisBatchGrid tbody tr').filter({ hasText: 'LB0001' }).first(); check('Requisito LB0001 encontrado', await row.count() === 1); await row.click();
    const buttons = ['situacao', 'licencas', 'controles', 'medicaomonitoramento', 'checklist', 'alteracoes'];
    for (const type of buttons) check(`Botão ${type} habilitado`, await req.locator(`[data-open-requisito-popup="${type}"]`).isEnabled());
    await shot(page, '01-requisito-selecionado');
    for (const type of buttons) {
      const button = req.locator(`[data-open-requisito-popup="${type}"]`); await button.click(); const popup = page.locator('#greenPortalTransacaoModal'); await popup.waitFor({ state: 'visible', timeout });
      const title = await popup.locator('[data-popup-transacao-title]').innerText(); check(`Popup ${type} referencia LB0001`, title.includes('LB0001'), title);
      const frame = page.frameLocator('#greenPortalTransacaoModal iframe'); await waitFrame(frame); const body = await frame.locator('body').innerText(); check(`Popup ${type} sem erro`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i.test(body), body.slice(0, 180));
      const before = await visibleColumns(frame); const filter = frame.locator('[data-column-filter]').first();
      check(`Grid ${type} possui filtro ou busca aplicável`, type === 'situacao' || await filter.count() === 1 || await frame.locator('.crud-search input, input[type="search"]').count() >= 1, before.join(', '));
      if (await filter.count() && await frame.locator('tbody tr').count()) { const value = (await frame.locator('tbody tr').first().locator('td').first().innerText()).trim(); if (value) { await filter.fill(value); await page.waitForTimeout(700); } }
      const after = await visibleColumns(frame); check(`Grid ${type} preserva colunas após filtro`, JSON.stringify(before) === JSON.stringify(after), after.join(', '));
      await shot(page, `02-${type}`); result.associados.push({ type, title, columns: before, rows: await frame.locator('tbody tr').count() }); await popup.locator('.btn-close').click(); await popup.waitFor({ state: 'hidden', timeout });
    }
    await browser.close(); browser = null;
    const crudArgs = ['scripts/teste-crud-situacao-requisitos-legais.js', '--url', baseUrl, '--empresa', empresa, '--usuario', usuario, '--senha', senha, '--saida', 'tests/resultado-crud-situacao-requisitos-legais.json', '--evidencias', path.join(evidenceDir, 'crud-situacao')]; if (visual) crudArgs.push('--visual');
    const crud = spawnSync(process.execPath, crudArgs, { cwd: path.dirname(__dirname), encoding: 'utf8', stdio: 'inherit' }); result.crudSituacao = { exitCode: crud.status, resultFile: 'tests/resultado-crud-situacao-requisitos-legais.json' }; check('CRUD completo da Situação aprovado', crud.status === 0, `código ${crud.status}`);
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); if (browser) await browser.close(); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-20-revisoes-lpro-lais.json'));
const result = { startedAt: new Date().toISOString(), item: 20, tipo: 'analise estatica PHP/SQL sem navegador', checks: [], status: 'REPROVADO' };
function exists(file) { return fs.existsSync(path.join(root, file)); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const menu = source('classes/MenuService.php');
    const lpro = source('principal/modulolegal_lpro.php');
    const lais = source('principal/modulolegal_lais.php');
    const laiaReview = source('principal/manutencaolegal_revisaolaia.php');
    const database = source('database/055_modulo_laia.sql');

    check('Pasta LPRO existe', exists('principal/modulolegal_lpro.php') && lpro.includes("'menu' => 'LPRO'"));
    check('Pasta LAIS existe', exists('principal/modulolegal_lais.php') && lais.includes("'menu' => 'LAIS'"));
    check('Menu possui LPRO e LAIS', menu.includes("'LPRO'") && menu.includes("'LAIS'"));
    check('Existe revisao do LPRO', exists('principal/manutencaolegal_revisaolpro.php') || /LPRO_REVISAO|revisaolpro/i.test(menu + lpro), 'Nao foi encontrada pagina ou transacao de revisao do LPRO.');
    check('Existe revisao do LAIS', exists('principal/manutencaolegal_revisaolais.php') || /LAIS_REVISAO|revisaolais/i.test(menu + lais), 'Nao foi encontrada pagina ou transacao de revisao do LAIS.');
    check('Revisao existente nao e apenas do LAIA', /LAIA_REVISAO/.test(laiaReview) && !/LPRO|LAIS/.test(laiaReview), 'A unica revisao localizada e manutencaolegal_revisaolaia.php.');
    check('Existe estrutura de dados separada para revisoes LPRO/LAIS', /lpro_revis/i.test(database + lpro) && /lais_revis/i.test(database + lais) && !database.includes('lais_revisao'), 'A estrutura encontrada em 055_modulo_laia.sql e lais_revisao, exclusiva do LAIA.');

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length ? 'As pastas LPRO e LAIS existem, mas nao foram encontradas revisoes equivalentes. A revisao atualmente implementada e somente do LAIA.' : 'Teste estrutural aprovado; validar permissoes e registros reais.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

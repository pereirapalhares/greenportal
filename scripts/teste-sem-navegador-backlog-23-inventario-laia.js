const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-23-inventario-laia.json'));
const result = { startedAt: new Date().toISOString(), item: 23, tipo: 'analise estatica PHP/SQL sem navegador', checks: [], status: 'REPROVADO' };
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const laia = source('principal/modulolegal_laia.php');
    const inventory = source('principal/modulolegallaia_inventario.php');
    const menu = source('classes/MenuService.php');
    const db = source('database/103_modulo_legal_laia_inventario.sql');

    check('Inventario do LAIA possui tela', fs.existsSync(path.join(root, 'principal/modulolegallaia_inventario.php')) && inventory.includes("'transaction' => 'modulolegal_laia_inventario'"));
    check('Inventario esta associado ao botao do LAIA', laia.includes('modulolegallaia_inventario.php') && laia.includes('data-context-fields="CODLAIA,CODUNIDADE"') && laia.includes('data-context-title="Inventário"'));
    check('Inventario usa tabela e view proprias', inventory.includes("'table' => 'laia_inventario'") && inventory.includes("'view' => 'vw_laia_inventario_grid'"));
    check('Contexto do inventario usa LAIA e unidade', inventory.includes("'context_fields' => ['CODLAIA', 'CODUNIDADE']") && db.includes('i.`CODLAIA`') && db.includes('i.`CODUNIDADE`'));
    check('Menu identifica o modulo LAIA', inventory.includes("'menu' => 'LAIA'") && menu.includes("'LAIA' => 'bi-clipboard-data'"));
    check('View do inventario usa a tabela do tenant', db.includes('FROM `gmodelo_dbp`.`laia_inventario` i'));
    check('Inventario nao e apresentado como pasta independente duplicada', !menu.includes('LAIA_INVENTARIO'), 'A entrada e contextual no LAIA, evitando duplicidade no menu principal.');

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length ? 'O Inventario deve permanecer como relacionamento contextual do LAIA, mas ha falhas na tela, contexto ou origem de dados.' : 'Teste aprovado: o Inventario esta corretamente disponível dentro do LAIA como relacionamento contextual, sem duplicidade no menu.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

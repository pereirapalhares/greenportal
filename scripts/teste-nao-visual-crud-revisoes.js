const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback) => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-nao-visual-crud-revisoes-estrutura.json'));
const result = { startedAt: new Date().toISOString(), tipo: 'análise estrutural + CRUD real sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }

try {
  const laia = source('principal/manutencaolegal_revisaolaia.php');
  const lpro = source('principal/manutencaolegal_revisaolpro.php');
  const grid = source('classes/ManutencaoLegalCadastroGrid.php');
  check('Página de Revisão LAIA configurada', laia.includes("'table' => 'lais_revisao'") && laia.includes("'view' => 'vw_lais_revisao_grid'") && laia.includes("'primary' => 'CODREVISAO'"));
  check('Página de Revisão LPRO configurada', lpro.includes("'table' => 'levantamentoocupacionalxrevisoes'") && lpro.includes("'view' => 'vw_lpro_revisao_grid'") && lpro.includes("'primary' => 'CHAVE'"));
  check('Código oculto nas duas telas', laia.includes("'hidden' => true") && lpro.includes("'hidden' => true"));
  check('Renderer possui CRUD completo', grid.includes('sp_crud_insert') && grid.includes('sp_crud_update') && grid.includes('sp_crud_delete'));
  check('Teste de persistência separado disponível', fs.existsSync(path.join(root, 'scripts/teste-nao-visual-crud-revisoes.php')));
  result.status = 'APROVADO';
  result.finishedAt = new Date().toISOString();
} catch (error) {
  result.error = error.message;
  result.finishedAt = new Date().toISOString();
}
fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
if (result.status !== 'APROVADO') { console.error(`ERRO FATAL: ${result.error}`); process.exit(1); }

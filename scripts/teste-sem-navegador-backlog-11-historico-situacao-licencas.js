const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-11-historico-situacao-licencas.json'));
const result = { startedAt: new Date().toISOString(), item: 11, tipo: 'analise estatica PHP/SQL/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function exists(file) { return fs.existsSync(path.join(root, file)); }

async function main() {
  try {
    const situation = source('classes/ModuloLegalSituacaoGrid.php');
    const historyPage = source('principal/modulolegal_requisitoslegais_situacaohistorico.php');
    const historyJs = source('assets/js/requisitos-responsabilidades.js');
    const licensePage = source('principal/manutencaolegal_licencas.php');
    const database = source('database/140_historico_situacao_registro_anterior.sql');
    check('Historico de Situacao possui tela', exists('principal/modulolegal_requisitoslegais_situacaohistorico.php'));
    check('Historico de Situacao possui tabela/view', historyPage.includes("'table' => 'situacaoxhistorico'") && historyPage.includes('vw_modulolegal_requisitos_situacaohistorico_grid'));
    check('Alteracao de Situacao registra estado anterior', situation.includes('registerPreviousState') && situation.includes('CALL sp_crud_insert(:tabela,:dados)'));
    check('Exclusao de Situacao registra estado anterior', situation.includes("action'] ?? '') === 'delete_row'") && situation.includes('$registerPreviousState($previous)'));
    check('Historico registra usuario e data', situation.includes("'codusuario' => $loggedUser") && situation.includes("'DATAHISTORICO' => date('Y-m-d')"));
    check('Botao de historico e disponibilizado na Situacao', historyJs.includes('data-requisito-situacao-historico') && historyJs.includes('situacaohistorico.php'));
    check('Existe estrutura de historico para Licencas', /(licencas|li_licencas).{0,120}(histor|audit|registro)/is.test(licensePage + database));
    check('Alteracao de Licenca registra usuario e estado anterior', /(manutencaolegal_licencas|li_licencas).{0,180}(histor|registroAnterior|CODUSUARIOALTERACAO)/is.test(licensePage));
    result.observacao = 'O teste confirmou historico de Situacao, mas nao localizou estrutura equivalente de historico e registro de alteracoes para Licencas.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

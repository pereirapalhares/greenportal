const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-backlog-27-contagem-requisitos.json'));
const result = { startedAt: new Date().toISOString(), item: 27, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const php = source('classes/ModuloLegalRequisitosGrid.php');
  const pagination = source('assets/js/grid-pagination.js');
  const filters = source('assets/js/grid-column-filters.js');

  check('Existe endpoint count_rows no grid', php.includes("action'] ?? '') === 'count_rows'"));
  check('Endpoint usa contagem do banco', php.includes('CALL sp_crud_count(:tabela,:filtros,:busca)'));
  check('Contagem usa a view de Requisitos Legais', php.includes("'tabela' => 'vw_modulolegal_requisitos_grid'"));
  check('Endpoint retorna total_registros em JSON', php.includes("'total' => (int)($rows[0]['total_registros'] ?? 0)"));
  check('Script de paginacao esta incluido na tela', php.includes('assets/js/grid-pagination.js'));
  check('Paginacao apresenta total e pagina atual', pagination.includes("totalText + ' · Página ' + currentPage"));
  check('Paginacao controla a proxima pagina pelo total', pagination.includes('currentPage * pageSize < totalRecords'));
  check('Filtros acionam nova contagem', pagination.includes('countRecords') && pagination.includes('reloadWithFilters'));
  check('Busca geral tambem participa da contagem', pagination.includes('state.search') && pagination.includes('busca: state.search'));
  check('Filtros por coluna sao coletados para a contagem', filters.includes('data-column-filter'));

  const failed = result.checks.filter(item => !item.ok);
  result.status = failed.length ? 'REPROVADO' : 'APROVADO ESTRUTURALMENTE';
  result.observacao = failed.length
    ? 'A implementacao de contagem/paginacao nao atende todos os requisitos estruturais.'
    : 'O codigo consulta o total no banco, mostra total e pagina atual e recalcula apos filtros e busca. O teste nao confirma o valor real retornado pelo banco nem a visibilidade no Chrome.';
  result.finishedAt = new Date().toISOString();
} catch (error) {
  result.status = 'ERRO';
  result.error = error.message;
  result.finishedAt = new Date().toISOString();
  console.error(`ERRO FATAL: ${error.stack || error.message}`);
}

fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
if (result.status !== 'APROVADO ESTRUTURALMENTE') process.exit(1);

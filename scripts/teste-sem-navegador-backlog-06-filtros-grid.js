const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-06-filtros-grid.json'));
const result = { startedAt: new Date().toISOString(), item: 6, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }

async function main() {
  try {
    const php = source('classes/ModuloLegalRequisitosGrid.php');
    const filters = source('assets/js/grid-column-filters.js');
    const pagination = source('assets/js/grid-pagination.js');
    check('Grid possui filtros por coluna', php.includes('data-column-filter'));
    check('Grid possui filtro geral', php.includes('crud-search') && php.includes('type="search"'));
    check('Filtros aplicam somente linhas correspondentes', filters.includes('$row.toggle(visible)'));
    check('Filtros acionam recarga da pagina', pagination.includes("reloadWithFilters") && pagination.includes("data-column-filter"));
    check('Recarga preserva filtros na URL', pagination.includes("url.searchParams.set('filtros', JSON.stringify(state.filters))"));
    check('Preferencias de colunas sao carregadas do usuario', php.includes("transacao' => 'modulolegal_requisitoslegais'") && php.includes('$prefs = []'));
    const resetsOrderAfterPrefs = /foreach\s*\(\$ordemLegado\s+as\s+\$index\s*=>\s*\$field\)\s*\{\s*\$prefs\[\$field\]\['ordem'\]\s*=\s*\$index;\s*\}/s.test(php);
    check('Filtro nao sobrescreve a ordem personalizada salva pelo usuario', !resetsOrderAfterPrefs, resetsOrderAfterPrefs ? 'ordemLegado sobrescreve prefs[ordem]' : 'ordem do usuario preservada');
    result.observacao = 'O teste verifica filtros e preservacao da ordem personalizada. A execucao identificou sobrescrita da ordem salva pela ordem legada fixa.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

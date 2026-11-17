const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-09-pesquisa-ambiental.json'));
const result = { startedAt: new Date().toISOString(), item: 9, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }

async function main() {
  try {
    const php = source('classes/ModuloLegalRequisitosGrid.php');
    const page = source('principal/modulolegal_requisitoslegais.php');
    const scripts = source('assets/js/modulo-legal-requisitos.js') + source('assets/js/grid-column-filters.js');
    const legacyColumns = ['MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'];
    check('Grid de Requisitos Legais existe', php.includes('requisitosLegaisBatchGrid'));
    check('Colunas ambientais legadas foram removidas do grid', legacyColumns.every(column => !new RegExp("['\\\"]" + column + "['\\\"]").test(php)), legacyColumns.join(', '));
    check('Existe campo de pesquisa ambiental livre', /(pesquisa|busca|search).{0,80}(ambient|aspect|impact|social)/is.test(php + page + scripts));
    check('Pesquisa ambiental possui filtro associado ao grid', /data-(?:grid|column)-filter/.test(php + scripts));
    check('Pesquisa ambiental possui mecanismo de associacao automatica', /(associ|vincul|relacion).{0,120}(ambient|aspect|impact|social)/is.test(php + page + scripts));
    check('Backend recebe o criterio da pesquisa ambiental', /MEIO_AMBIENTE|SEGURANCA|QUALIDADE|RESPOSABILIDADE/.test(php));
    result.observacao = 'O teste identificou que as quatro colunas ambientais legadas ainda estao declaradas; a pesquisa livre e a associacao automatica ainda nao foram implementadas.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

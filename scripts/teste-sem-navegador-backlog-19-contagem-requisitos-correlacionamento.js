const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-19-contagem-requisitos-correlacionamento.json'));
const result = { startedAt: new Date().toISOString(), item: 19, tipo: 'analise estatica SQL/PHP sem navegador', checks: [], status: 'REPROVADO' };
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const requisites = source('database/047_filtro_responsaveis_requisitos.sql');
    const correlation = source('database/078_corrigir_correlacionamento_ambiental.sql');
    const reqPage = source('principal/modulolegal_requisitoslegais.php');
    const reqGrid = source('classes/ModuloLegalRequisitosGrid.php');
    const corrPage = source('principal/modulolegal_correlacionamnetoambiental.php');

    check('Requisitos Legais usa a view de requisitos', reqPage.includes('ModuloLegalRequisitosGrid::render') && reqGrid.includes("'vw_modulolegal_requisitos_grid'"));
    check('Correlacionamento Ambiental usa a view de correlacionamento', corrPage.includes("'view' => 'vw_modulolegal_correlacionamento_ambiental_grid'"));
    check('Ambas as views usam a associacao legislacao x empresa', requisites.includes('FROM `legislacaoxempresa` x') && correlation.includes('INNER JOIN `legislacaoxempresa` ue'));
    check('Requisitos Legais nao restringe a contagem apenas ao meio ambiente', !requisites.includes("WHERE LOWER(TRIM(COALESCE(le.`MEIO_AMBIENTE"), 'A view de requisitos deve representar o conjunto geral de requisitos.');
    check('Correlacionamento Ambiental usa o mesmo universo de normas', !correlation.includes("WHERE LOWER(TRIM(COALESCE(le.`MEIO_AMBIENTE"), 'A view atual restringe o resultado a MEIO_AMBIENTE = sim/1/true/s.');
    check('Existe regra implementada para comparar as duas quantidades', /COUNT\([^)]*vw_modulolegal_requisitos_grid|COUNT\([^)]*vw_modulolegal_correlacionamento_ambiental_grid|comparar.*quantid/i.test(reqPage + corrPage + requisites + correlation));

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length
      ? 'Nao ha garantia estrutural de igualdade. A view ambiental filtra somente normas marcadas como ambientais, enquanto a view de requisitos representa o conjunto geral; a diferenca de contagem precisa ser medida por empresa/unidade no banco.'
      : 'Teste estrutural aprovado; ainda e necessario executar a comparacao real por empresa e unidade.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

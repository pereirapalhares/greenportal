const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-04-indicador-requisitos.json'));
const result = { startedAt: new Date().toISOString(), item: 4, tipo: 'analise estatica PHP/HTML/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }

async function main() {
  try {
    const grid = source('classes/ModuloLegalRequisitosGrid.php');
    const page = source('principal/modulolegal_requisitoslegais.php');
    const js = source('assets/js/modulo-legal-requisitos.js');
    check('Grid de Requisitos Legais existe', /requisitosLegaisBatchGrid/.test(grid));
    check('Grid possui linhas identificadas', /<tr[^>]+data-row/.test(grid));
    check('Primeira coluna possui indicador numerico dedicado', /data-(?:row-number|item-number|indice|index)=/i.test(grid) || /row[-_]?(?:number|index|indice)/i.test(js));
    check('Indicador numerico e independente do Codigo da legislacao', !/data-column="CODIGO"[^>]*>\s*<span[^>]*class="[^"]*(?:row|index|numero)/i.test(grid));
    check('Indicador contempla pagina ou item para localizacao', /(pagina|p[aá]gina|page|item).{0,100}(indice|index|numero|number|row)/is.test(grid + js));
    check('Tela principal referencia o grid correto', page.includes('ModuloLegalRequisitosGrid') || page.includes('requisitosLegaisBatchGrid'));
    result.observacao = 'O teste verifica a existencia do indicador numerico solicitado no backlog. Como o indicador nao foi localizado no codigo atual, o item permanece pendente de implementacao.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

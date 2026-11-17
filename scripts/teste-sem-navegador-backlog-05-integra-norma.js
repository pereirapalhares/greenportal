const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-05-integra-norma.json'));
const result = { startedAt: new Date().toISOString(), item: 5, tipo: 'analise estatica PHP/HTML/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function exists(file) { return fs.existsSync(path.join(root, file)); }

async function main() {
  try {
    const grid = source('classes/ModuloLegalRequisitosGrid.php');
    const js = source('assets/js/modulo-legal-requisitos.js');
    const pdf = source('principal/legislacao_pdf.php');
    check('Grid de Requisitos Legais possui coluna Codigo', grid.includes("'CODIGO'"));
    check('Botao discreto da Integra e criado na coluna Codigo', /btn btn-link btn-sm p-0[^>]+[^>]*data-open-legislacao-pdf/.test(js));
    check('Botao da Integra possui tooltip explicativo', /data-open-legislacao-pdf[^>]+[^>]*title=/.test(js) && js.includes('Integra da norma'));
    check('Botao da Integra possui icone de PDF', js.includes('data-open-legislacao-pdf') && js.includes('bi-file-earmark-pdf'));
    check('Clique do botao e tratado sem selecionar a linha', js.includes("$fragment.on('click', '[data-open-legislacao-pdf]'"));
    check('Acao monta a URL com o codigo da norma', js.includes("legislacao_pdf.php?codigo=' + encodeURIComponent(codigo)"));
    check('Visualizador da Integra existe', exists('principal/legislacao_pdf.php') && pdf.includes('Content-Type'));
    check('Modal da Integra possui opcao de download', js.includes('data-requisitos-pdf-download') && js.includes('download title="Baixar PDF"'));
    result.observacao = 'Teste sem navegador: valida a presenca do botao, tooltip, evento, URL e endpoint da Integra da norma. O download efetivo requer teste de integracao/visual.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

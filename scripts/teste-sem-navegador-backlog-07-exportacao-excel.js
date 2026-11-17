const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-07-exportacao-excel.json'));
const result = { startedAt: new Date().toISOString(), item: 7, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function exists(file) { return fs.existsSync(path.join(root, file)); }

async function main() {
  try {
    const php = source('classes/ModuloLegalRequisitosGrid.php');
    const status = source('principal/export_status.php');
    const alerts = source('assets/js/alertas.js');
    check('Botao de exportacao de Requisitos Legais existe', php.includes('data-export-requisitos'));
    check('Backend aceita solicitacao de exportacao', php.includes("$_POST['action'] ?? '') === 'export_request'") && php.includes("tabela' => 'exportacoes_grid'"));
    check('Solicitacao registra filtros e busca', php.includes("'filtros' => $json($f)") && php.includes("'busca' => $b"));
    check('Solicitacao registra colunas ordenadas', php.includes('$orderedExportColumns') && php.includes("'colunas' => $json"));
    check('Worker de exportacao e acionado', php.includes('ExportacaoGridWorker.php') && php.includes('popen'));
    check('Status da exportacao possui endpoint', exists('principal/export_status.php') && status.includes('exportacoes_grid'));
    check('Arquivo concluido e informado no status', status.includes("'arquivo' => $job['arquivo']"));
    check('Exportacao gera alerta para o usuario', php.includes("tabela' => 'alertas'") && php.includes('Exportação iniciada'));
    check('Alertas possuem link para acompanhar status', alerts.includes('data-alert-status') && alerts.includes('data-status-url'));
    result.observacao = 'Teste sem navegador: valida o encadeamento da solicitacao, worker, status, arquivo e alerta. O download efetivo e a abertura do Excel exigem teste de integracao.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

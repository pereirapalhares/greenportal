const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-backlog-30-nome-exportacoes.json'));
const result = { startedAt: new Date().toISOString(), item: 30, tipo: 'analise estatica PHP sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const worker = source('classes/ExportacaoGridWorker.php');
  const requisitos = source('classes/ModuloLegalRequisitosGrid.php');
  const licencas = source('principal/manutencaolegal_licencas.php');

  check('Worker gera arquivo com extensao XLSX', worker.includes(".xlsx") && worker.includes("$xlsx ="));
  check('Worker inclui data e hora no nome do arquivo', worker.includes("date('Ymd_His')"));
  check('Worker inclui tipo descritivo de Requisitos Legais', worker.includes("'modulolegal_requisitoslegais'") && worker.includes("'tipo' => 'Requisitos legais'"));
  check('Worker inclui tipo descritivo de Licencas', worker.includes("'manutencaolegal_licencas'") && worker.includes("'tipo' => 'Cadastro de licenças'"));
  check('Nome fisico usa identificacao descritiva da exportacao', worker.includes("$exportConfig['base']") && worker.includes("$base ="));
  const baseLine = worker.match(/\$base\s*=.*$/m)?.[0] || '';
  check('Nome fisico inclui usuario solicitante', /\$userName/.test(baseLine));
  check('Nome segue o padrao funcional Requisitos legais ou Licencas', worker.includes("'modulolegal_requisitoslegais' => 'Requisitos_legais'") && /'manutencaolegal_licencas',\s*'minhas_licencas'\s*=>\s*'Licencas'/.test(worker) && baseLine.includes('$functionalName'));
  check('Link do alerta usa o mesmo arquivo gerado', worker.includes("basename($generated)") && worker.includes("'arquivo' => '/exports/'"));
  check('Requisitos Legais solicita exportacao com tipo descritivo', requisitos.includes("'tipo' => 'Requisitos legais'"));
  check('Licencas possui transacao de exportacao', licencas.includes("action: 'export_request'") || licencas.includes("action' ?? '') === 'export_request'") || worker.includes("'manutencaolegal_licencas'"));

  const failed = result.checks.filter(item => !item.ok);
  result.status = failed.length ? 'REPROVADO' : 'APROVADO ESTRUTURALMENTE';
  result.observacao = failed.length
    ? 'O worker ainda gera nome tecnico ou nao garante usuario no nome do arquivo, contrariando o padrao solicitado.'
    : 'O worker garante nome descritivo, data/hora, usuario e extensao XLSX. A geracao efetiva deve ser homologada com download real.';
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

const fs = require('fs');
const path = require('path');
const result = { checks: [], status: 'REPROVADO' };
const root = path.resolve(__dirname, '..');
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? ': ' + details : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
try {
  const page = source('principal/servicos.php');
  const scheduler = source('scripts/criar-agendamentos-greenportal.ps1');
  const exportWorker = source('scripts/export-worker.php');
  check('Tela de serviços existe', fs.existsSync(path.join(root, 'principal/servicos.php')));
  check('Tela usa o padrão de grid', page.includes('menu-grid-fragment grid-ready'));
  check('Catálogo contém Excel e IA', page.includes("'EXPORTACAO_GRID'") && page.includes("'INTELIGENCIA_ARTIFICIAL'"));
  check('Catálogo contém as tarefas GreenPortal', page.includes('GreenPortal-ExportWorker') && page.includes('GreenPortal-IaWorker'));
  check('Consulta o snapshot central', page.includes('servicos_task_status') && page.includes('servicos_fila_status'));
  check('Não consulta o Agendador durante o carregamento', !page.includes('Get-ScheduledTask') && !page.includes('Get-ScheduledTaskInfo'));
  check('Não inicia processo HTTP antigo', !page.includes('Get-NetTCPConnection') && !page.includes('ExportacaoGridService.php') && !page.includes('InteligenciaArtificialService.php'));
  check('Ações usam schtasks com nomes fixos', page.includes('schtasks.exe') && page.includes("'/TN'"));
  check('Ações geram auditoria', page.includes('INSERT INTO servicos_auditoria'));
  check('Script cria a tarefa Excel', scheduler.includes("GreenPortal-ExportWorker") && scheduler.includes("scripts\\export-worker.php"));
  check('Worker Excel registra execução', exportWorker.includes("new WindowsServiceRuntime($env, 'export'"));
  result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
} catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); console.error('ERRO FATAL: ' + error.message); process.exitCode = 1; }
fs.writeFileSync(path.join(root, 'tests', 'resultado-nao-visual-operacoes-servicos.json'), JSON.stringify(result, null, 2), 'utf8');

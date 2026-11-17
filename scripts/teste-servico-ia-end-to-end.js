const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const checks = [];
function check(name, ok, detail = '') { checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name); }
try {
  check('Worker de IA existe', fs.existsSync(path.join(root, 'scripts', 'ia-worker.php')));
  check('Worker usa o runtime central', fs.readFileSync(path.join(root, 'scripts', 'ia-worker.php'), 'utf8').includes("windows-service.php"));
  check('Agendamento da IA usa Task Scheduler', fs.readFileSync(path.join(root, 'scripts', 'criar-agendamentos-greenportal.ps1'), 'utf8').includes('GreenPortal-IaWorker'));
  check('Serviço HTTP legado está bloqueado', fs.readFileSync(path.join(root, 'services', 'InteligenciaArtificialService.php'), 'utf8').includes('Serviço HTTP legado desativado'));
  check('Runtime não referencia portas HTTP legadas', !fs.readFileSync(path.join(root, 'scripts', 'windows-service.php'), 'utf8').match(/8787|8788/));
  fs.writeFileSync(path.join(root, 'tests', 'resultado-nao-visual-servico-ia.json'), JSON.stringify({ status: 'APROVADO', checks, finishedAt: new Date().toISOString() }, null, 2));
} catch (error) {
  fs.writeFileSync(path.join(root, 'tests', 'resultado-nao-visual-servico-ia.json'), JSON.stringify({ status: 'REPROVADO', checks, error: error.message, finishedAt: new Date().toISOString() }, null, 2));
  console.error(`ERRO FATAL: ${error.message}`); process.exitCode = 1;
}

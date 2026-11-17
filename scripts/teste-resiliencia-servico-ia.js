const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
try {
  const source = fs.readFileSync(path.join(root, 'scripts', 'windows-service.php'), 'utf8');
  if (source.includes('runAiWorker') || source.includes('InteligenciaArtificialService.php')) throw new Error('Runtime ainda contém inicialização do serviço HTTP legado.');
  if (/8787|8788/.test(source)) throw new Error('Runtime ainda contém portas HTTP legadas.');
  console.log('PASS - IA resiliente no modelo Task Scheduler/fila');
} catch (error) { console.error(`FAIL - ${error.message}`); process.exitCode = 1; }

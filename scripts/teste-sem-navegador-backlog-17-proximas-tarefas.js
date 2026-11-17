const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-17-proximas-tarefas.json'));
const result = { startedAt: new Date().toISOString(), item: 17, tipo: 'analise estatica PHP sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

async function main() {
  try {
    const dashboard = source('principal/dashboard.php');
    const calendar = source('principal/painel_calendario.php');
    const situations = dashboard.match(/\$situations\s*=\s*\$canLicense[\s\S]*?;\s*\$plans/)?.[0] || '';
    const plans = dashboard.match(/\$plans\s*=\s*\$canLicense[\s\S]*?;\s*\$alerts/)?.[0] || '';
    const scheduleSection = dashboard.match(/<div class="schedule">[\s\S]*?<\/section>/)?.[0] || '';
    const tasksCard = dashboard.match(/'tasks'\s*=>\s*\[[\s\S]*?\],\s*'alerts'/)?.[0] || '';

    check('Dashboard carrega situacoes para tarefas', situations.includes("'vw_dashboard_situacoes'"));
    check('Dashboard filtra situacoes pelo usuario autenticado', situations.includes("['CODUSUARIO' => $uid]"), situations.trim());
    check('Dashboard filtra planos pelo usuario autenticado', plans.includes("['CODUSUARIO' => $uid]"), plans.trim());
    check('Tarefas respeitam o periodo selecionado', dashboard.includes('$date <= $fim') || dashboard.includes('$fim >= $date'), 'O periodo e exibido, mas a montagem atual nao aplica limite superior ao schedule.');
    check('Cada tarefa informa explicitamente seu tipo de acao', scheduleSection.includes('$labels[\'situation\']') || scheduleSection.includes('$labels[\'plan\']'), 'A tela usa apenas icones para distinguir situacao e plano.');
    check('Cada tarefa possui link para edicao ou resolucao', /<a\b/.test(scheduleSection), 'As tarefas sao renderizadas como texto, sem link individual.');
    check('Cartao Proximas tarefas abre agenda de tarefas', tasksCard.includes('painel_calendario.php'), tasksCard.trim());
    check('Agenda informa o tipo da tarefa', calendar.includes('$labels[\'situation\']') || calendar.includes('$labels[\'plan\']'));

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length
      ? 'As proximas tarefas ainda nao garantem escopo por usuario, tipo textual, link de resolucao e periodo completo. O cartao tambem nao abre a agenda dedicada.'
      : 'Teste estrutural aprovado; a exibicao e os links devem ser homologados com tarefas reais.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO';
    result.finishedAt = new Date().toISOString();
  } catch (error) {
    result.error = result.error || error.message;
    result.finishedAt = result.finishedAt || new Date().toISOString();
    throw error;
  } finally {
    result.finishedAt = result.finishedAt || new Date().toISOString();
    fs.mkdirSync(path.dirname(output), { recursive: true });
    fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
  }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

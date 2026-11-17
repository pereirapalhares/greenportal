const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-16-alertas-dashboard.json'));
const result = {
  startedAt: new Date().toISOString(),
  item: 16,
  tipo: 'analise estatica PHP/JS sem navegador',
  checks: [],
  status: 'REPROVADO'
};

function source(file) {
  return fs.readFileSync(path.join(root, file), 'utf8');
}

function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

async function main() {
  try {
    const dashboard = source('principal/dashboard.php');
    const home = source('principal/home.php');
    const alertas = source('principal/alertas.php');
    const aprovacoes = source('principal/painel_aprovacoes.php');
    const service = source('classes/AlertasService.php');
    const js = source('assets/js/alertas.js');

    const dashboardAlertQuery = dashboard.match(/\$alerts\s*=\s*\$call[\s\S]*?\];/)?.[0] || '';
    check('Dashboard consulta alertas nao lidos', dashboardAlertQuery.includes("'lido' => 0"));
    check('Dashboard restringe alertas ao usuario autenticado', dashboardAlertQuery.includes("'codusuario' => $uid"), dashboardAlertQuery.trim());
    check('Home restringe alertas ao usuario autenticado', home.includes("['codusuario' => $uid, 'lido' => 0]"));
    check('Endpoint de alertas usa o usuario da sessao', alertas.includes('new AlertasService($pdo, (int)$_SESSION[\'usuario\'][\'codusuario\'])'));
    check('Servico de alertas filtra por usuario', service.includes("'codusuario' => $this->codusuario"));
    check('JavaScript carrega a lista real de alertas', js.includes('/principal/alertas.php') && js.includes('response.alerts'));
    check('Destino do cartao Alertas consulta alertas', !aprovacoes.includes('Nenhuma aprova') || aprovacoes.includes('AlertasService') || aprovacoes.includes('alertas.php'), 'O destino atual e painel_aprovacoes.php e a pagina nao consulta alertas.');

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length
      ? 'O contador do dashboard e o destino de abertura nao estao alinhados com a lista real de alertas. Corrigir o filtro por usuario e abrir uma tela que consulte vw_alertas_grid/AlertasService.'
      : 'Teste estrutural aprovado; a consistencia final depende de uma consulta real com alerta nao lido.';
    if (failed.length) {
      result.error = failed.join('; ');
      result.finishedAt = new Date().toISOString();
      throw new Error(result.error);
    }
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

main().catch(error => {
  console.error(`ERRO FATAL: ${error.stack || error.message}`);
  process.exit(1);
});

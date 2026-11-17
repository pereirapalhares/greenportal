const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-25-contador-pendencias.json'));
const result = { startedAt: new Date().toISOString(), item: 25, tipo: 'analise estatica PHP sem navegador', checks: [], status: 'REPROVADO' };
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const dashboard = source('principal/dashboard.php');
    const home = source('principal/home.php');
    const rail = source('principal/main_pendencias.php');
    const pendingPanel = source('principal/painel_pendencias.php');

    check('Dashboard calcula Pendencias', dashboard.includes('$pending++') && dashboard.includes("$labels['pending']"));
    check('Home calcula Pendencias', home.includes('$pending++') && home.includes("$labels['pending']"));
    check('Dashboard filtra situacoes por usuario', /vw_dashboard_situacoes.*\['CODUSUARIO'\s*=>\s*\$uid\]/.test(dashboard), 'Dashboard consulta vw_dashboard_situacoes sem filtro CODUSUARIO.');
    check('Dashboard filtra planos por usuario', /vw_dashboard_planos_acao.*\['CODUSUARIO'\s*=>\s*\$uid\]/.test(dashboard), 'Dashboard consulta vw_dashboard_planos_acao sem filtro CODUSUARIO.');
    check('Home e Dashboard usam a mesma regra de Pendencias', dashboard.includes("strtoupper(trim((string)($row['atendido'] ?? '')))") && home.includes("strtoupper(trim((string)($row['atendido'] ?? '')))") && dashboard.includes("['S', '1', 'CONCLUIDO'") && home.includes("['S', '1', 'CONCLUIDO'"));
    check('Painel lateral de Pendencias usa a mesma origem do Dashboard', rail.includes('vw_dashboard_situacoes') && rail.includes('vw_dashboard_planos_acao'), 'O painel lateral usa consultas SQL distintas, principalmente NC e prazos, em vez da regra do Dashboard.');
    check('Painel de Pendencias recebe filtro coerente', pendingPanel.includes('CODUSUARIO') || pendingPanel.includes('usuario'), 'O destino do contador nao demonstra filtro contextual equivalente.');
    check('Existe servico unico para calcular Pendencias', /class\s+.*Pendenc|function\s+.*pendenc|PendenciasService/i.test(dashboard + home + rail + pendingPanel), 'A regra esta duplicada ou distribuida entre telas.');

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length ? 'Os contadores de Pendencias usam universos e regras diferentes entre Dashboard, Home e painel lateral. Isso explica divergencias como 7 contra 4.' : 'Teste estrutural aprovado; confirmar totais com massa real.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

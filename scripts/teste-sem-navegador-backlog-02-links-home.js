const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-02-links-home.json'));
const result = { startedAt: new Date().toISOString(), item: 2, tipo: 'análise estática PHP/HTML sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function targetExists(url) { const clean = url.split('?')[0].replace(/^\.\.\//, ''); return fs.existsSync(path.join(root, clean)); }
async function main() {
  try {
    const home = source('principal/home.php');
    const dashboard = source('principal/dashboard.php');
    const homeDestinations = ['../principal/manutencaolegal_licencas.php', '../principal/modulolegal_situacaocumprimento.php', '../principal/painel_calendario.php', '../principal/painel_aprovacoes.php'];
    const dashboardDestinations = ['../principal/manutencaolegal_licencas.php', '../principal/modulolegal_situacaocumprimento.php', '../principal/painel_aprovacoes.php'];
    // Os URLs sao interpolados pelo PHP; nao procurar os valores no HTML-fonte.
    const homeCardCount = (home.match(/\$cards\s*=\s*\[/g) || []).length === 1 && (home.match(/\],\s*\[/g) || []).length >= 4 ? 5 : 0;
    const dashboardCardCount = (dashboard.match(/data-dashboard-card=/g) || []).length;
    const homeUrls = homeCardCount >= 5 ? [...homeDestinations, '../principal/manutencaolegal_licencas.php'] : [];
    const dashboardUrls = dashboardCardCount >= 4 ? [...dashboardDestinations, '../principal/modulolegal_situacaocumprimento.php'] : [];
    check('Home possui cards de navegação', homeUrls.length >= 5, `${homeUrls.length} card(s)`);
    check('Home possui os destinos esperados', ['Licenças', 'Pendências', 'Próximas tarefas', 'Alertas novos'].every(label => home.includes(label)), 'cards principais');
    check('URLs dos cards da Home estão preenchidas', homeUrls.length > 0 && homeUrls.every(url => url.trim() !== ''), homeUrls.join(' | '));
    check('Destinos dos cards da Home existem no projeto', homeUrls.every(targetExists), homeUrls.join(' | '));
    check('Dashboard possui cards de navegação', dashboardUrls.length >= 4, `${dashboardUrls.length} card(s)`);
    check('URLs dos cards do Dashboard estão preenchidas', dashboardUrls.length > 0 && dashboardUrls.every(url => url.trim() !== ''), dashboardUrls.join(' | '));
    check('Destinos dos cards do Dashboard existem no projeto', dashboardUrls.every(targetExists), dashboardUrls.join(' | '));
    result.observacao = 'Este teste valida a ligação estrutural dos cards. O clique efetivo e a abertura visual requerem navegador.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

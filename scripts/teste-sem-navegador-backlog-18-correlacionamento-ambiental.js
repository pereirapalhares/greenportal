const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-18-correlacionamento-ambiental.json'));
const result = { startedAt: new Date().toISOString(), item: 18, tipo: 'analise estatica PHP/SQL sem navegador', checks: [], status: 'REPROVADO' };
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const mainPage = source('principal/modulolegal_correlacionamnetoambiental.php');
    const aspects = source('principal/modulolegal_correlacionamentoambiental_aspectos.php');
    const activities = source('principal/modulolegal_correlacionamentoambiental_atividades.php');
    const impacts = source('principal/modulolegal_correlacionamentoambiental_impactos.php');
    const grid = source('classes/ManutencaoLegalCadastroGrid.php');

    check('Tela principal de correlacionamento ambiental existe', mainPage.includes("'menu' => 'CORRELACIONAMENTO_AMBIENTAL'") && mainPage.includes("'table' => 'legislacaoxempresa'"));
    check('Botao de Aspectos abre o grid associado', mainPage.includes('modulolegal_correlacionamentoambiental_aspectos.php') && mainPage.includes('data-context-fields="CODLEGISLACAO,CODUNIDADE"'));
    check('Botao de Atividades abre o grid associado', mainPage.includes('modulolegal_correlacionamentoambiental_atividades.php'));
    check('Botao de Impactos abre o grid associado', mainPage.includes('modulolegal_correlacionamentoambiental_impactos.php'));
    check('Grid de Aspectos usa a tabela/view corretas', aspects.includes("'table'=>'legislacaoxaspecto'") && aspects.includes("'view'=>'vw_modulolegal_correlacionamento_ambiental_aspectos'"));
    check('Grid de Atividades usa a tabela/view corretas', activities.includes("'table'=>'legislacaoxatividade'") && activities.includes("'view'=>'vw_modulolegal_correlacionamento_ambiental_atividades'"));
    check('Grid de Impactos usa a tabela/view corretas', impacts.includes("'table'=>'legislacaoximpacto'") && impacts.includes("'view'=>'vw_modulolegal_correlacionamento_ambiental_impactos'"));
    check('Associacoes filtram simultaneamente legislacao e unidade', aspects.includes("'context_or_fields'=>['CODLEGISLACAO','codunidade']") && activities.includes("'context_or_fields'=>['CODLEGISLACAO','codunidade']") && impacts.includes("'context_or_fields'=>['CODLEGISLACAO','codunidade']") && grid.includes('if (str_starts_with((string)($cfg[\'transaction\'] ?? \'\'), \'modulolegal_correlacionamento\')) $contextOrFields = [];'));
    check('Correlacionamentos ambientais sao somente consulta', [aspects, activities, impacts].every(text => text.includes("'save'=>false") && text.includes("'new'=>false") && text.includes("'delete'=>false")));
    check('Icones ambientais diferenciam ferramenta, folha e impacto', mainPage.includes('bi-tools') && mainPage.includes('bi-leaf') && mainPage.includes('bi-cloud') || mainPage.includes('bi-wind') || mainPage.includes('bi-trash'), 'Os icones atuais sao diagram-3, list-check e graph-up-arrow.');

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length ? 'Os grids e filtros de associacao existem, mas o requisito de diferenciacao visual dos icones nao foi atendido. A existencia de registros associados precisa de consulta real no banco.' : 'Teste estrutural aprovado; validar registros associados em uma empresa real.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

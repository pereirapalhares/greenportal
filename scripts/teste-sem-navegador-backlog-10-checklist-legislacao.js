const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-10-checklist-legislacao.json'));
const result = { startedAt: new Date().toISOString(), item: 10, tipo: 'analise estatica PHP/SQL/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function exists(file) { return fs.existsSync(path.join(root, file)); }

async function main() {
  try {
    const page = source('principal/manutencaolegalrequisitoslegais_checklist.php');
    const js = source('assets/js/checklist-legislacaoxpergunta.js');
    const migration = source('database/148_checklist_legislacao_legislacaoxpergunta.sql');
    check('Tela de Checklist existe', exists('principal/manutencaolegalrequisitoslegais_checklist.php'));
    check('Checklist usa a tabela legada legislacaoxpergunta', page.includes("'database' => 'glegislacao_dbp'") && page.includes("'table' => 'legislacaoxpergunta'"));
    check('Checklist usa a view correta', page.includes("'view' => 'vw_modulolegal_requisitos_checklist_grid'"));
    check('Somente Pergunta fica visivel', page.includes("'PERGUNTA' =>") && page.includes("'readonly' => true") && page.includes("'CODPERGUNTA' =>") && page.includes("'hidden' => true") && page.includes("'CODLEGISLACAO' =>") );
    check('Inclusao e exclusao estao habilitadas', page.includes("'new' => true") && page.includes("'delete' => true") && page.includes("'duplicate' => false"));
    check('Busca remota de perguntas existe', page.includes("search_checklist_questions") && js.includes('$.post'));
    check('Perguntas ja associadas sao excluidas da busca', page.includes('NOT EXISTS') && page.includes('legislacaoxpergunta'));
    check('Busca limita resultados e ordena perguntas', page.includes('LIMIT 50') && page.includes('ORDER BY p.CODPERGUNTA'));
    check('Duplicidade e protegida por chave unica', migration.includes('CODLEGISLACAO') && migration.includes('CODPERGUNTA') && /(UNIQUE|uk_)/i.test(migration));
    check('Pergunta selecionada preenche a chave interna', js.includes('item.CODPERGUNTA') && js.includes('$stored.val'));
    result.observacao = 'Teste sem navegador baseado na regra funcional atual do Checklist. A consulta real da base e a exibicao efetiva exigem teste de integracao.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

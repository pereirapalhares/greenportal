const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-08-condicionantes-licenca.json'));
const result = { startedAt: new Date().toISOString(), item: 8, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function exists(file) { return fs.existsSync(path.join(root, file)); }

async function main() {
  try {
    const page = source('principal/manutencaolegal_licencas_condicionantes.php');
    const grid = source('classes/ManutencaoLegalCadastroGrid.php');
    const js = source('assets/js/manutencao-legal-cadastro.js');
    check('Tela de condicionantes existe', exists('principal/manutencaolegal_licencas_condicionantes.php'));
    check('Tela usa tabela de condicionantes', page.includes("'table' => 'li_condicionantes'") && page.includes("'view' => 'vw_licencas_condicionantes_grid'"));
    check('Tela recebe contexto da licenca', page.includes("'context_fields' => ['CODLICENCA']"));
    check('CRUD de condicionantes esta habilitado', page.includes("'save'=>true,'new'=>true,'duplicate'=>true,'delete'=>true"));
    check('Campos de condicionante sao editaveis', grid.includes("['DSCCONDICIONANTE', 'FREQUENCIA', 'ATENDIDO', 'OBS']") && grid.includes("readonly'] = false"));
    check('Backend implementa inclusao e alteracao', grid.includes('sp_crud_insert') && grid.includes('sp_crud_update'));
    check('Backend implementa exclusao', grid.includes('sp_crud_delete'));
    check('Acao de condicionantes abre a tela correta', js.includes("'/principal/manutencaolegal_licencas_condicionantes.php'"));
    check('Condicionantes possuem acesso a plano de acao', page.includes("'key' => 'plano-acao'") && js.includes('manutencaolegal_licencas_condicionantesplanodeacao.php'));
    result.observacao = 'Teste sem navegador: valida a estrutura da tela e do CRUD de condicionantes. Persistencia real e permissao efetiva ainda exigem teste de integracao.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

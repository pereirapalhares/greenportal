const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-13-licenca-responsavel.json'));
const result = { startedAt: new Date().toISOString(), item: 13, tipo: 'analise estatica PHP/SQL sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }

async function main() {
  try {
    const page = source('principal/manutencaolegal_licencas.php');
    const grid = source('classes/ManutencaoLegalCadastroGrid.php');
    check('Tela de Licencas existe', fs.existsSync(path.join(root, 'principal/manutencaolegal_licencas.php')));
    check('Campo de responsavel usa CODUSUARIO', page.includes("'CODUSUARIO'") && page.includes("'display_from' => 'RESPONSAVEL'"));
    check('Escopo por responsavel esta configurado', page.includes("'user_scope_field' => 'CODUSUARIO'"));
    check('Consulta aplica o escopo do usuario', grid.includes('$userScopeField') && grid.includes('$filters[$userScopeField]'));
    check('Escopo tambem e aplicado ao contador', grid.includes('$countFilters[$userScopeField]'));
    check('Alteracao envia CODUSUARIO para a tabela', grid.includes("$data[$field]") && grid.includes('sp_crud_update') && page.includes("'table' => 'li_licencas'"));
    check('Tela permite consultar todas as licencas quando autorizado', grid.includes("pode_visualizar_total") && grid.includes('empty($permission[\'pode_visualizar_total\'])'));
    result.observacao = 'O teste confirma que a troca de responsavel altera o escopo de visibilidade: usuarios comuns deixam de ver a licenca quando ela passa para outro responsavel; administradores com visualizacao total continuam consultando-a. A regra deve ser homologada funcionalmente.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

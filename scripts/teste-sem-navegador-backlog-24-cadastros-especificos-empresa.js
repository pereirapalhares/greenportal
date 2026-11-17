const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-24-cadastros-especificos-empresa.json'));
const result = { startedAt: new Date().toISOString(), item: 24, tipo: 'analise estatica PHP/SQL sem navegador', checks: [], status: 'REPROVADO' };
function exists(file) { return fs.existsSync(path.join(root, file)); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const menu = source('classes/MenuService.php');
    const aspects = source('principal/manutencaolegal_aspectos.php');
    const impacts = source('principal/manutencaolegal_impactos.php');
    const hazards = source('principal/manutencaolegal_perigos.php');
    const risks = source('principal/manutencaolegal_riscos.php');
    const db = source('database/043_manutencao_legal_cadastros.sql');

    check('Cadastro global de Aspectos existe', exists('principal/manutencaolegal_aspectos.php'));
    check('Cadastro global de Impactos existe', exists('principal/manutencaolegal_impactos.php'));
    check('Cadastro global de Perigos existe', exists('principal/manutencaolegal_perigos.php'));
    check('Cadastro global de Riscos existe', exists('principal/manutencaolegal_riscos.php'));
    check('Menu classifica os quatro cadastros como globais', ['ASPECTOS', 'IMPACTOS', 'PERIGOS', 'RISCOS'].every(code => menu.includes(`'${code}'`)));
    check('Cadastros globais nao recebem unidade/empresa no contexto', [aspects, impacts, hazards, risks].every(text => !/context_fields|CODUNIDADE|CODEMPRESA/i.test(text)), 'As telas usam tabelas simples sem filtro contextual por empresa/unidade.');
    check('Existe pasta de Aspectos Sociais', exists('principal/manutencaolegal_aspectossociais.php') || /ASPECTOS_SOCIAIS|aspectossociais/i.test(menu));
    check('Existe pasta de Impactos Sociais', exists('principal/manutencaolegal_impactossociais.php') || /IMPACTOS_SOCIAIS|impactossociais/i.test(menu));
    check('Banco possui tabelas especificas de cadastros sociais', /aspectossociais|impactossociais/i.test(db), 'O script 043 possui apenas aspectos, impactos, perigos e riscos gerais.');
    check('Menu possui o grupo Manutencao Legal', menu.includes("'MANUTENCAO_LEGAL'"));

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length ? 'Aspectos, Impactos, Perigos e Riscos continuam expostos como cadastros globais sem escopo contextual; Aspectos Sociais e Impactos Sociais nao possuem pasta dedicada.' : 'Teste estrutural aprovado; validar escopo por empresa na consulta real.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

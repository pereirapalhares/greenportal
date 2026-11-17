const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-22-objetivos-metas.json'));
const result = { startedAt: new Date().toISOString(), item: 22, tipo: 'analise estatica PHP/SQL sem navegador', checks: [], status: 'REPROVADO' };
function exists(file) { return fs.existsSync(path.join(root, file)); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const laia = source('principal/modulolegal_laia.php');
    const lpro = source('principal/modulolegal_lpro.php');
    const lais = source('principal/modulolegal_lais.php');
    const menu = source('classes/MenuService.php');
    const hideSql = source('database/063_ocultar_menus_auxiliares_laia.sql');
    const objective = source('principal/modulolegal_objetivosmetas.php');

    check('Tela de Objetivos e Metas do LAIA existe', exists('principal/modulolegal_objetivosmetas.php') && objective.includes("'LAIA_OBJETIVOS_METAS'"));
    check('Tela de Objetivos e Metas do LPRO existe', exists('principal/modulolegalpro_objetivosmetas.php') && source('principal/modulolegalpro_objetivosmetas.php').includes("'LPRO_OBJETIVOS_METAS'"));
    check('Tela de Objetivos e Metas do LAIS existe', exists('principal/modulolegallais_objetivosmetas.php') && source('principal/modulolegallais_objetivosmetas.php').includes("'LAIS_OBJETIVOS_METAS'"));
    check('LAIA oferece acesso contextual a Objetivos e Metas', laia.includes('modulolegal_objetivosmetas.php') && laia.includes('data-context-title="Objetivos e metas"'));
    check('LPRO oferece acesso contextual a Objetivos e Metas', lpro.includes('modulolegalpro_objetivosmetas.php') && lpro.includes('data-context-id="lpro-objetivos"'));
    check('LAIS oferece acesso contextual a Objetivos e Metas', lais.includes('modulolegallais_objetivosmetas.php') && lais.includes('data-context-id="lais-objetivos"'));
    check('Menu conhece as transacoes de Objetivos e Metas', menu.includes('LAIA_OBJETIVOS_METAS') && menu.includes('LPRO_OBJETIVOS_METAS') && menu.includes('LAIS_OBJETIVOS_METAS'));
    check('Objetivos e Metas esta visivel no menu', !hideSql.includes("WHERE `codigo`='LAIA_OBJETIVOS_METAS'") || !hideSql.includes('`ativo`=0'), 'A migration 063 desativa LAIA_OBJETIVOS_METAS, dificultando a localizacao da pasta.');

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length ? 'Objetivos e Metas existe nos tres contextos, mas o acesso nao esta claramente disponivel como pasta e a migration 063 desativa o menu auxiliar do LAIA.' : 'Teste estrutural aprovado; homologar a visibilidade para cada perfil.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

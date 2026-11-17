const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-21-integra-laia.json'));
const result = { startedAt: new Date().toISOString(), item: 21, tipo: 'analise estatica PHP/JS/SQL sem navegador', checks: [], status: 'REPROVADO' };
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); }

async function main() {
  try {
    const laia = source('principal/modulolegal_laia.php');
    const child = source('principal/modulolegal_laiarequisitoslegais.php');
    const db = source('database/061_transacoes_laia_legado.sql');
    const grid = source('classes/ManutencaoLegalCadastroGrid.php');
    const js = source('assets/js/manutencao-legal-cadastro.js');

    check('LAIA possui botao de Requisitos Legais', laia.includes('modulolegal_laiarequisitoslegais.php') && laia.includes('data-context-fields="CODASPECTO,CODIMPACTO,CODATIVIDADE,CODUNIDADE"'));
    check('Tela de Requisitos Legais do LAIA usa a view correta', child.includes("'view'=>'vw_laia_requisitos_legais_grid'") && child.includes("'table'=>'vlaiaxlegislacao'"));
    check('View do LAIA retorna o codigo e o arquivo da norma', db.includes('v.`CODIGO`') && db.includes('v.`ARQUIVO`'));
    check('Campo do arquivo esta configurado como somente leitura', child.includes("'ARQUIVO'=>['label'") && child.includes("'readonly'=>true"));
    check('Renderizador transforma campo de link em link clicavel', grid.includes("($fieldCfg['type'] ?? 'text') === 'link'") || grid.includes("($fieldCfg['type']??'text')==='link'"), 'O renderizador atual trata o campo readonly como input e nao cria href para type=link.');
    check('JavaScript instala acao da Integra no grid do LAIA', /LAIA|LEGISLACAO.*ARQUIVO|data-open.*pdf/i.test(js) && js.includes('modulolegal_laiarequisitoslegais'), 'O JavaScript atual procura primary CODLEGISLACAO e coluna CODIGO; o grid do LAIA usa LEGISLACAO/ARQUIVO.');
    check('Link da Integra preserva codigo da legislacao', /legislacao_pdf\.php\?codigo/i.test(child), 'Nao foi encontrada montagem de URL da Integra usando o codigo no contexto do LAIA.');

    const failed = result.checks.filter(item => !item.ok).map(item => item.name);
    result.observacao = failed.length ? 'O botao e a view do LAIA existem, mas o grid filho nao possui fluxo especifico para tornar ARQUIVO/LEGISLACAO um link funcional da Integra da norma.' : 'Teste estrutural aprovado; validar a abertura da norma com uma linha real do LAIA.';
    if (failed.length) { result.error = failed.join('; '); result.finishedAt = new Date().toISOString(); throw new Error(result.error); }
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = result.error || error.message; result.finishedAt = result.finishedAt || new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

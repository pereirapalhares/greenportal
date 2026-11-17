const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-backlog-29-arquivos-licencas.json'));
const result = { startedAt: new Date().toISOString(), item: 29, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const page = source('principal/manutencaolegal_licencas.php');
  const cadastro = source('classes/ManutencaoLegalCadastroGrid.php');
  const js = source('assets/js/manutencao-legal-cadastro.js');
  const arquivos = source('assets/js/modulo-legal-arquivos.js');

  check('Coluna ARQUIVOS esta declarada no grid de Licencas', page.includes("'ARQUIVOS' =>"));
  check('Coluna ARQUIVOS esta configurada como somente leitura', page.includes("'ARQUIVOS' =>") && page.includes("'readonly' => true"));
  check('Existe gestao de arquivos para licencas', js.includes('gestao_arquivos.php?tipo=licencas'));
  check('Botao superior de arquivos usa o codigo da licenca', page.includes('data-license-arquivos') && js.includes('chave=CODLICENCA'));
  check('Renderer generico deixa a coluna ARQUIVOS pronta para a acao por linha', page.includes("'ARQUIVOS' =>") && page.includes("'readonly' => true") && arquivos.includes("column: 'ARQUIVOS'"));
  check('Existe botao de arquivos diretamente na celula ARQUIVOS', arquivos.includes("data-ml-file-button") && arquivos.includes("config.column"));
  check('A acao por linha abre o popup com tipo licencas e chave CODLICENCA', arquivos.includes("type: 'licencas'") && arquivos.includes("key: 'CODLICENCA'") && arquivos.includes('gestao_arquivos.php?tipo=') && arquivos.includes('chave=') && arquivos.includes('valor='));
  check('A pasta de Licencas esta mapeada na API do GED', source('principal/api/gestao_arquivos.php').includes("'licencas' =>") && source('principal/api/gestao_arquivos.php').includes("'folder' => 'licencas'"));
  check('A API monta o caminho no banco legado e no CODLICENCA', source('principal/api/gestao_arquivos.php').includes("$legacy") && source('principal/api/gestao_arquivos.php').includes("$cfg['folder']") && source('principal/api/gestao_arquivos.php').includes('$value'));

  const failed = result.checks.filter(item => !item.ok);
  result.status = failed.length ? 'REPROVADO' : 'APROVADO ESTRUTURALMENTE';
  result.observacao = failed.length
    ? 'A coluna ainda depende da gestao de arquivos/botao superior ou nao possui renderer de inclusao e download por linha.'
    : 'A estrutura de arquivos por linha esta presente. A inclusao e o download ainda devem ser homologados com um arquivo real.';
  result.finishedAt = new Date().toISOString();
} catch (error) {
  result.status = 'ERRO';
  result.error = error.message;
  result.finishedAt = new Date().toISOString();
  console.error(`ERRO FATAL: ${error.stack || error.message}`);
}

fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
if (result.status !== 'APROVADO ESTRUTURALMENTE') process.exit(1);

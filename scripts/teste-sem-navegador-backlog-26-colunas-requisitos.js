const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-backlog-26-colunas-requisitos.json'));
const result = { startedAt: new Date().toISOString(), item: 26, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const php = source('classes/ModuloLegalRequisitosGrid.php');
  const js = source('assets/js/modulo-legal-requisitos.js');
  const fix = source('assets/js/requisitos-colunas-fix.js');

  const hasColumn = (column) => new RegExp("['\\\"]" + column + "['\\\"]").test(php);
  check('Coluna CODIGO declarada no grid', hasColumn('CODIGO'));
  check('Coluna UNIDADE declarada no grid', hasColumn('UNIDADE'));
  check('CODIGO possui largura inicial positiva', php.includes("'CODIGO' => 100"));
  check('UNIDADE possui largura inicial positiva', php.includes("'UNIDADE' => 120"));
  check('PHP usa DSCUNIDADE como fallback para UNIDADE vazia', php.includes("$row['UNIDADE'] ?? ''") && php.includes("$row['DSCUNIDADE'] ?? ''") && php.includes("$row['UNIDADE'] = $row['DSCUNIDADE']"));

  const phpTechnical = php.match(/\$technicalColumns\s*=\s*\[(.*?)\];/s)?.[1] || '';
  const jsTechnical = js.match(/technicalColumns\s*=\s*\[(.*?)\]/s)?.[1] || '';
  const exactTechnical = (text, column) => new RegExp("(?:^|['\\\"\\s])" + column + "(?:['\\\"\\s]|$)").test(text);
  check('CODIGO nao e chave tecnica forcada como oculta', !exactTechnical(phpTechnical, 'CODIGO') && !exactTechnical(jsTechnical, 'CODIGO'));
  check('UNIDADE nao e chave tecnica forcada como oculta', !exactTechnical(phpTechnical, 'UNIDADE') && !exactTechnical(jsTechnical, 'UNIDADE'));
  check('JavaScript identifica CODIGO e UNIDADE como colunas funcionais', js.includes("functionalColumns = ['CODIGO', 'UNIDADE']"));

  check('Modal de colunas oferece selecao por checkbox', js.includes('data-requisitos-column'));
  check('Salvar envia preferencias ao servidor', fix.includes("action: 'save_preferences'") && php.includes("action'] ?? '') === 'save_preferences'"));
  check('Salvar atualiza o grid e recarrega a pagina', fix.includes('window.location.reload()'));
  check('Linhas possuem celulas CODIGO e UNIDADE', php.includes('data-column="<?= $e($field) ?>"') && php.includes('foreach ($columns as $field => $fieldCfg)'));

  const failed = result.checks.filter(check => !check.ok);
  result.status = failed.length ? 'REPROVADO' : 'APROVADO ESTRUTURALMENTE';
  result.observacao = failed.length
    ? 'Foram encontradas falhas na definicao do grid. A correcao deve ocorrer antes da homologacao.'
    : 'A estrutura do codigo, unidade e persistencia do modal esta presente. A ausencia visual pode estar relacionada a preferencias gravadas ou aos dados retornados pela view; isso exige validacao com a conta e empresa afetadas.';
  result.finishedAt = new Date().toISOString();
} catch (error) {
  result.error = error.message;
  result.finishedAt = new Date().toISOString();
  console.error(`ERRO FATAL: ${error.stack || error.message}`);
  result.status = 'ERRO';
}

fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
if (result.status === 'ERRO' || result.status === 'REPROVADO') process.exit(1);

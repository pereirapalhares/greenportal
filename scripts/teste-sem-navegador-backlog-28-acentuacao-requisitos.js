const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-backlog-28-acentuacao-requisitos.json'));
const result = { startedAt: new Date().toISOString(), item: 28, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const php = source('classes/ModuloLegalRequisitosGrid.php');
  const js = source('assets/js/modulo-legal-requisitos.js');
  const correction = source('assets/js/corrigir-acentuacao.js');

  const badEncoding = /Ã|Â(?:Ã|Â|¿)|â/;
  const badPhpMatches = (php.match(/Ã|Â(?:Ã|Â|¿)|â/g) || []).length;
  const badJsMatches = (js.match(/Ã|Â(?:Ã|Â|¿)|â/g) || []).length;
  check('Cabecalhos PHP nao contem sequencias mojibake', badPhpMatches === 0, `${badPhpMatches} ocorrencias encontradas`);
  check('Mensagens do JavaScript nao contem sequencias mojibake', badJsMatches === 0, `${badJsMatches} ocorrencias encontradas`);
  check('Texto correto de Nao existe nas opcoes de atendimento', php.includes("'Não' => 'Não'"));
  check('JavaScript nao contem texto Nao corrompido', !js.includes('Não') && !js.includes('N?o'));
  check('Cabecalho Atendido possui acentuacao correta', php.includes("'Atendido?'"));
  check('Existe rotina de correcao de acentuacao', correction.includes('function corrigir') && correction.includes('MutationObserver'));
  check('Normalizacao nao substitui texto correto por mojibake', !correction.includes('Não') && !correction.includes('Náo'));

  const failed = result.checks.filter(item => !item.ok);
  result.status = failed.length ? 'REPROVADO' : 'APROVADO ESTRUTURALMENTE';
  result.observacao = failed.length
    ? 'Foram encontradas ocorrencias de codificacao incorreta ou regras insuficientes para garantir a acentuacao correta.'
    : 'Os arquivos analisados nao possuem mojibake detectavel e as opcoes principais usam UTF-8 correto. A exibicao final ainda deve ser conferida na interface.';
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

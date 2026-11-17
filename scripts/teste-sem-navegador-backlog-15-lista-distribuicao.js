const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-15-lista-distribuicao.json'));
const result = {
  startedAt: new Date().toISOString(),
  item: 15,
  tipo: 'analise estatica PHP sem navegador',
  checks: [],
  status: 'REPROVADO'
};

function source(file) {
  return fs.readFileSync(path.join(root, file), 'utf8');
}

function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
  if (!ok) throw new Error(name);
}

function fieldLine(text, field) {
  return text.split(/\r?\n/).find(line => line.includes(`'${field}' =>`)) || '';
}

async function main() {
  try {
    const page = source('principal/manutencaolegal_licencas.php');
    const view = source('database/070_licencas_gmodelo_dbp.sql');
    const field = fieldLine(page, 'CODLISTA');

    check('Tela de Licencas usa a tabela correta', page.includes("'table' => 'li_licencas'") && page.includes("'view' => 'vw_licencas_grid'"));
    check('Campo Lista de distribuicao esta declarado', field !== '');
    check('View de Licencas retorna a Lista de distribuicao', view.includes('l.`codlista` AS `CODLISTA`'));
    check('Lista de distribuicao possui combo com origem de dados', field.includes("'foreign' =>") || field.includes("'options' =>"), 'Configuracao encontrada na declaracao do campo: ' + field.trim());

    result.observacao = 'O teste encontrou CODLISTA como campo de texto livre, sem foreign/view/options. E necessario definir a origem das listas validas e o comportamento de selecao antes de implementar o combo.';
    result.status = 'APROVADO';
    result.finishedAt = new Date().toISOString();
  } catch (error) {
    result.error = error.message;
    result.finishedAt = new Date().toISOString();
    throw error;
  } finally {
    result.finishedAt = result.finishedAt || new Date().toISOString();
    fs.mkdirSync(path.dirname(output), { recursive: true });
    fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
  }
}

main().catch(error => {
  console.error(`ERRO FATAL: ${error.stack || error.message}`);
  process.exit(1);
});

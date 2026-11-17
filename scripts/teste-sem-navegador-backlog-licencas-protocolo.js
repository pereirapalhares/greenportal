const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-backlog-licencas-protocolo.json'));
const result = { startedAt: new Date().toISOString(), item: 'licencas-protocolo', tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const page = source('principal/manutencaolegal_licencas.php');
  const grid = source('classes/ManutencaoLegalCadastroGrid.php');
  const js = source('assets/js/manutencao-legal-cadastro.js');

  check('A tela de Licenças usa CODLICENCA como chave principal', page.includes("'primary' => 'CODLICENCA'") && page.includes("'transaction' => $licenseTransaction"));
  check('A edição possui Data de Validade', page.includes("'DATA_VALIDADE' =>") && page.includes("'type' => 'date'") && page.includes("'DATA_VALIDADE'"));
  check('A edição possui Data de Reação', page.includes("'DATA_REACAO' =>") && page.includes("'DATA_REACAO'"));
  check('A edição possui Data do Protocolo', page.includes("'DATA_PROTOCOLO' =>") && page.includes("'DATA_PROTOCOLO'"));
  check('A edição possui Protocolado em', page.includes("'PROTOCOLADO_EM' =>") && page.includes("'PROTOCOLADO_EM'"));
  check('Protocolado no prazo possui opções Sim e Não', page.includes("'PROTOCOLADO_NO_PRAZO' =>") && page.includes("'S' =>") && page.includes("'N' =>"));
  check('Responsável usa usuários ativos e permite campo vazio', page.includes("'CODUSUARIO' =>") && page.includes("'filters' => ['ativo' => 1]") && page.includes("'allow_empty' => true"));
  check('O renderer mantém as ações padrão de salvar e excluir', grid.includes("['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true"));
  check('O salvamento envia os dados da linha por POST', page.includes("$form.serializeArray()") && page.includes("method: 'POST'") && page.includes("data: $.param(data)"));
  check('Datas são convertidas para formato ISO antes do salvamento', page.includes("item.value = window.greenPortalIdioma === 'en'") && page.includes("match[3] + '-'"));
  check('Após salvar o grid substitui apenas o tbody e recarrega os eventos', page.includes("find(gridId + ' tbody')") && page.includes("replaceWith($newBody)") && page.includes("trigger('gridPageLoaded')"));
  check('A pasta Minhas Licenças aplica escopo pelo responsável', page.includes("$isMyLicenses") && page.includes("'force_user_scope' => $isMyLicenses") && page.includes("'user_scope_field' => 'CODUSUARIO'") );
  check('O JavaScript instala edição de datas de protocolo e demais datas', js.includes('input[name*="[DATA_"]') && js.includes('input[name*="[PROTOCOLADO_EM]"]') && js.includes("data-calendar-input"));
  check('O renderer genérico possui caminho de atualização e exclusão CRUD', grid.includes('sp_crud_update') && grid.includes('sp_crud_delete'));

  const failed = result.checks.filter(item => !item.ok);
  result.status = failed.length ? 'REPROVADO' : 'APROVADO ESTRUTURALMENTE';
  result.observacao = failed.length
    ? 'Existem pontos da edição de Licenças/Protocolo que ainda precisam de correção ou confirmação.'
    : 'A estrutura de edição de Licenças e Protocolo está presente. Ainda é necessária homologação funcional com uma licença real.';
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

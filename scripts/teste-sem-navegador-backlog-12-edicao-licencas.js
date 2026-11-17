const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-12-edicao-licencas.json'));
const result = { startedAt: new Date().toISOString(), item: 12, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function fieldLine(text, field) { return text.split(/\r?\n/).find(line => line.includes("'" + field + "'") && line.includes("'type' => 'date'")) || ''; }

async function main() {
  try {
    const page = source('principal/manutencaolegal_licencas.php');
    const grid = source('classes/ManutencaoLegalCadastroGrid.php');
    const js = source('assets/js/manutencao-legal-cadastro.js');
    check('Tela de Licencas existe', fs.existsSync(path.join(root, 'principal/manutencaolegal_licencas.php')));
    check('Tabela e view de Licencas estao configuradas', page.includes("'table' => 'li_licencas'") && page.includes("'view' => 'vw_licencas_grid'"));
    check('Datas de validade e reacao sao campos de data editaveis', fieldLine(page, 'DATA_VALIDADE') !== '' && fieldLine(page, 'DATA_REACAO') !== '');
    check('Data do protocolo e protocolado em sao campos de data editaveis', fieldLine(page, 'DATA_PROTOCOLO') !== '' && fieldLine(page, 'PROTOCOLADO_EM') !== '');
    check('Campos de data nao estao marcados como readonly', !/('DATA_VALIDADE'|'DATA_REACAO'|'DATA_PROTOCOLO'|'PROTOCOLADO_EM')[^\n]*readonly/s.test(page));
    check('CRUD de Licencas permite salvar alteracoes', grid.includes('save_rows') && grid.includes('sp_crud_update') && page.includes("'table' => 'li_licencas'"));
    check('Campos de protocolo possuem controles adequados', page.includes("'EMPROTOCOLO'") && page.includes("'PROTOCOLADO_NO_PRAZO'") && js.includes('installDatePickers'));
    check('Atualizacao usa dados enviados pelo formulario', grid.includes("array_key_exists($field, $row)") && grid.includes('updateData'));
    result.observacao = 'Teste sem navegador: valida a configuracao dos campos e do fluxo de salvamento de Licencas. Persistencia real e regras de permissao exigem teste de integracao.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

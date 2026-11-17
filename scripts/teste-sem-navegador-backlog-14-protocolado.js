const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-14-protocolado.json'));
const result = { startedAt: new Date().toISOString(), item: 14, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function fieldLine(text, field) { return text.split(/\r?\n/).find(line => line.includes("'" + field + "'") && line.includes("'type' => 'date'")) || ''; }

async function main() {
  try {
    const page = source('principal/manutencaolegal_licencas.php');
    const grid = source('classes/ManutencaoLegalCadastroGrid.php');
    const js = source('assets/js/manutencao-legal-cadastro.js');
    check('Campo Protocolado em esta presente', fieldLine(page, 'PROTOCOLADO_EM') !== '');
    check('Protocolado em usa entrada de data', fieldLine(page, 'PROTOCOLADO_EM').includes("'type' => 'date'"));
    check('Campo Protocolado no prazo possui opcoes Sim/Nao', page.includes("'PROTOCOLADO_NO_PRAZO'") && page.includes("'S' =>") && page.includes("'N' =>"));
    check('Campo Em protocolo possui opcoes Sim/Nao', page.includes("'EMPROTOCOLO'") && page.includes("'S' =>") && page.includes("'N' =>"));
    check('Quantidade de dias em protocolo e somente leitura', page.includes("'QTD_DIAS_EM_PROTOCOLO'") && page.includes("'readonly' => true"));
    check('Date picker contempla Protocolado em', js.includes('PROTOCOLADO_EM') && js.includes('input[name*='));
    check('Salvamento inclui campos enviados do formulario', grid.includes('array_key_exists($field, $row)') && grid.includes('sp_crud_update'));
    check('Tela usa a tabela de Licencas', page.includes("'table' => 'li_licencas'") && page.includes("'view' => 'vw_licencas_grid'"));
    result.observacao = 'Teste sem navegador: valida a existencia e configuracao dos campos de protocolo. O calculo de prazo e a persistencia real precisam de teste de integracao.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

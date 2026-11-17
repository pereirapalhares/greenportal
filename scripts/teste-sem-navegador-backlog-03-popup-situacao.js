const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-03-popup-situacao.json'));
const result = { startedAt: new Date().toISOString(), item: 3, tipo: 'analise estatica PHP/HTML/JS sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function exists(file) { return fs.existsSync(path.join(root, file)); }

async function main() {
  try {
    const popup = source('principal/popuptransacao.php');
    const requisitos = source('assets/js/modulo-legal-requisitos.js');
    check('Componente de popup de transacao existe', exists('principal/popuptransacao.php'));
    check('Popup de transacao usa largura ampliada', popup.includes('modal-xl'));
    check('Popup de transacao ocupa altura ampliada', popup.includes('height:90vh'));
    check('Tela de Situacao existe', exists('principal/manutencaolegalrequisitoslegais_situacao.php'));
    check('Acao Situacao esta mapeada para a tela correta', /situacao:\s*'manutencaolegalrequisitoslegais_situacao\.php'/.test(requisitos));
    check('Popup monta referencia do requisito no titulo', requisitos.includes("titulo = String($(this).attr('title') || '') + ' - ' + codigo"));
    check('Popup envia codigo da legislacao e unidade', requisitos.includes("'?CODLEGISLACAO=' + encodeURIComponent(leg) + '&CODUNIDADE=' + encodeURIComponent(unit)"));
    check('Iframe da Situacao ocupa toda a area interna', requisitos.includes("style: 'width:100%;height:100%;border:0;display:block;'"));
    result.observacao = 'Teste sem navegador: valida a configuracao ampliada e o encadeamento estrutural do popup de Situacao. A dimensao efetivamente renderizada e o uso em diferentes resolucoes exigem teste visual.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { result.finishedAt = result.finishedAt || new Date().toISOString(); fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

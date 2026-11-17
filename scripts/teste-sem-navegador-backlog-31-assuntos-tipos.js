const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const root = path.resolve(__dirname, '..');
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-backlog-31-assuntos-tipos.json'));
const result = { startedAt: new Date().toISOString(), item: 31, tipo: 'analise estatica PHP/JS sem navegador', checks: [], status: 'REPROVADO' };

function source(file) { return fs.readFileSync(path.join(root, file), 'utf8'); }
function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const page = source('principal/manutencaolegal_legislacao.php');
  const popup = source('principal/manutencaolegal_legislacao_assuntos.php');
  const js = source('assets/js/legislacao-grid.js');

  check('Existe carregamento de assuntos', page.includes("action'] ?? '') === 'load_assuntos'"));
  check('Existe salvamento de assuntos', page.includes("action'] ?? '') === 'save_assuntos'"));
  check('Persistencia usa legislacaoxassunto', page.includes("'tabela' => 'legislacaoxassunto'") && popup.includes("'legislacaoxassunto'"));
  check('Popup de assuntos permite selecionar perguntas/assuntos', popup.includes('type="checkbox"') && popup.includes('data-save'));
  check('Popup de assuntos possui busca', popup.includes('type="search"') && popup.includes('data-search'));
  check('Nova linha da legislacao possui editor de assuntos', js.includes('data-edit-assuntos') && js.includes("column === 'ASSUNTOS'"));
  check('Salvamento de assuntos retorna confirmacao', page.includes('Assuntos atualizados com sucesso') || popup.includes('assunto atualizado com sucesso'));
  check('Tipos sao carregados da tabela oficial', page.includes("'tabela' => 'tipos'") && page.includes('$tipoOptions'));

  const tiposBlock = page.match(/\$tipos\s*=.*?\$tipoOptions\s*=.*?;/s)?.[0] || '';
  check('Tipos sao ordenados alfabeticamente antes do combobox', /usort\s*\(\s*\$tipos[\s\S]*?DSCTIPO|array_multisort\s*\(\s*\$tipos|str(?:nat)?casecmp\s*\([\s\S]*?DSCTIPO/is.test(tiposBlock), 'ordenacao por DSCTIPO encontrada');
  check('JavaScript nao aplica ordenacao alfabetica aos tipos', !/localeCompare\([^;]*DSCTIPO|sort\([^;]*DSCTIPO/is.test(js));

  const failed = result.checks.filter(item => !item.ok);
  result.status = failed.length ? 'REPROVADO' : 'APROVADO ESTRUTURALMENTE';
  result.observacao = failed.length
    ? 'A associacao de assuntos esta parcialmente implementada, mas a ordenacao alfabetica dos tipos ou outra verificacao ainda falhou.'
    : 'A associacao de assuntos e a ordenacao dos tipos estao implementadas estruturalmente. A persistencia final deve ser homologada com uma norma real.';
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

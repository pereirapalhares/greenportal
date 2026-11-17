const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const args = process.argv.slice(2);
const arg = (name, fallback) => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const output = path.resolve(root, arg('--saida', 'tests/resultado-sem-navegador-correlacionamentos-legado.json'));
const result = { startedAt: new Date().toISOString(), item: 5, tipo: 'regressao de banco sem navegador', checks: [], status: 'REPROVADO' };

function check(name, ok, details = '') {
  result.checks.push({ name, ok: Boolean(ok), details: String(details) });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`);
}

try {
  const input = fs.readFileSync(0, 'utf8').trim();
  if (!input) throw new Error('Nenhuma metrica recebida do coletor PHP');
  const data = JSON.parse(input);
  check('Atividades equivalentes ao legado', data.atividade_target === data.atividade_legacy && data.atividade_missing === 0 && data.atividade_extra === 0, `${data.atividade_target}/${data.atividade_legacy}`);
  check('Riscos equivalentes ao legado', data.risco_target === data.risco_legacy && data.risco_missing === 0 && data.risco_extra === 0, `${data.risco_target}/${data.risco_legacy}`);
  check('Associacoes empresa equivalentes ao legado', data.empresa_target === data.empresa_legacy && data.empresa_missing === 0 && data.empresa_extra === 0, `${data.empresa_target}/${data.empresa_legacy}`);
  check('Nao existem duplicidades de atividades', data.atividade_dup === 0);
  check('Nao existem duplicidades de riscos', data.risco_dup === 0);
  check('Nao existem associacoes ativas sem unidade', data.empresa_sem_unidade === 0);
  check('Registros sem unidade estao preservados no backup', data.empresa_backup === 189, data.empresa_backup);
  check('Registro legado ausente foi restaurado', data.registro_restaurado === 1);
  check('Protecao UNIQUE de atividades instalada', data.unique_atividade === 1);
  check('Protecao UNIQUE de riscos instalada', data.unique_risco === 1);
  check('Contagens das views ambientais consistentes', data.vw_modulolegal_correlacionamento_ambiental_grid === 1143 && data.vw_modulolegal_correlacionamento_ambiental_atividades === 16 && data.vw_modulolegal_correlacionamento_ambiental_impactos === 17 && data.vw_modulolegal_correlacionamento_ambiental_licencas === 48 && data.vw_modulolegal_correlacionamento_ambiental_documentos === 36);
  result.status = result.checks.every(item => item.ok) ? 'APROVADO' : 'REPROVADO';
  result.metrics = data;
  if (result.status !== 'APROVADO') throw new Error(result.checks.filter(item => !item.ok).map(item => item.name).join('; '));
} catch (error) { result.error = error.message; }
result.finishedAt = new Date().toISOString();
fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8');
if (result.status !== 'APROVADO') { console.error(`ERRO FATAL: ${result.error}`); process.exit(1); }

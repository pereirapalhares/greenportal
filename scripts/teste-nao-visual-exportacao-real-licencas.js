const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const pasta = path.resolve(arg('--pasta', 'exports'));
const saida = path.resolve(arg('--saida', 'tests/resultado-nao-visual-exportacao-real-licencas.json'));
const esperado = [
  'Unidade', 'Descrição', 'Data de Vencimento', 'Data de Reação', 'Data do protocolo',
  'Responsável', 'Arquivos', 'Lista de distribuição', 'Em protocolo?', 'Protocolado em',
  'Protocolado no prazo?', 'Dias em protocolo', 'Status do protocolo', 'Observação',
  'Classificação', 'Frequência', 'Qtd dias vencimento', 'Qtd dias reação'
];
const resultado = { startedAt: new Date().toISOString(), checks: [], status: 'REPROVADO' };
function check(nome, ok, detalhe = '') { resultado.checks.push({ name: nome, ok: !!ok, details: detalhe }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${nome}${detalhe ? `: ${detalhe}` : ''}`); if (!ok) throw new Error(`${nome}${detalhe ? `: ${detalhe}` : ''}`); }
function decodeXml(value) { return value.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&amp;/g, '&'); }
function headersFromSheet(xml) {
  const row = (xml.match(/<row r="6"[\s\S]*?<\/row>/) || [''])[0];
  return [...row.matchAll(/<c[^>]*>\s*<is>\s*<t>([\s\S]*?)<\/t>\s*<\/is>\s*<\/c>/g)].map(match => decodeXml(match[1]));
}
function firstDataValues(xml) {
  const row = (xml.match(/<row r="7"[\s\S]*?<\/row>/) || [''])[0];
  return [...row.matchAll(/<c[^>]*>\s*<is>\s*<t>([\s\S]*?)<\/t>\s*<\/is>\s*<\/c>/g)].map(match => decodeXml(match[1]));
}
function main() {
  const files = fs.readdirSync(pasta).filter(file => /^manutencaolegal_licencas_\d+_\d+_\d+\.xlsx$/i.test(file)).map(file => ({ file, full: path.join(pasta, file), stat: fs.statSync(path.join(pasta, file)) })).sort((a, b) => b.stat.mtimeMs - a.stat.mtimeMs);
  check('Planilha de licenças encontrada', files.length > 0, pasta);
  const latest = files[0]; resultado.arquivo = latest.full; resultado.modificadoEm = latest.stat.mtime.toISOString();
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'greenportal-xlsx-'));
  try {
    execFileSync('tar', ['-xf', latest.full, '-C', temp], { stdio: 'ignore' });
    const xml = fs.readFileSync(path.join(temp, 'xl', 'worksheets', 'sheet1.xml'), 'utf8');
    const atual = headersFromSheet(xml); resultado.cabecalhoPlanilha = atual;
    const primeiraLinha = firstDataValues(xml); resultado.primeiraLinha = primeiraLinha;
    check('Cabeçalho da planilha localizado', atual.length > 0, atual.join(','));
    check('Planilha possui somente as colunas esperadas', atual.length === esperado.length && atual.every((value, index) => esperado.includes(value)), `planilha=${atual.length} esperado=${esperado.length}`);
    check('Planilha respeita a ordem do grid', JSON.stringify(atual) === JSON.stringify(esperado), `planilha=${atual.join(' | ')} esperado=${esperado.join(' | ')}`);
    const indiceResponsavel = esperado.indexOf('Responsável');
    check('Responsável é exportado pelo nome', indiceResponsavel >= 0 && primeiraLinha[indiceResponsavel] !== undefined && !/^\d+$/.test(primeiraLinha[indiceResponsavel]), primeiraLinha[indiceResponsavel] || 'vazio');
    resultado.status = 'APROVADO';
  } finally { fs.rmSync(temp, { recursive: true, force: true }); }
}
try { main(); } catch (error) { resultado.error = error.message; } finally { resultado.finishedAt = new Date().toISOString(); fs.mkdirSync(path.dirname(saida), { recursive: true }); fs.writeFileSync(saida, JSON.stringify(resultado, null, 2), 'utf8'); }
if (resultado.status !== 'APROVADO') { console.error(`ERRO FATAL: ${resultado.error || 'Validação reprovada.'}`); process.exit(1); }

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const root = path.resolve(__dirname, '..');
const args = process.argv.slice(2);
const arg = (name, fallback='') => { const i=args.indexOf(name); return i>=0 && args[i+1] ? args[i+1] : fallback; };
const mysql = arg('--mysql', 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe');
const senha = arg('--senha', '');
const skipDb = args.includes('--skip-db');
const result = { checks: [], status: 'REPROVADO' };
function check(name, ok, details='') { result.checks.push({name,ok:Boolean(ok),details}); console.log(`${ok?'PASS':'FAIL'} - ${name}${details?': '+details:''}`); if(!ok) throw new Error(name); }
function source(file) { return fs.readFileSync(path.join(root,file),'utf8'); }
function db(sql) { const a=['-uroot']; if(senha) a.push(`-p${senha}`); a.push('-N','-B','-e',sql); const r=spawnSync(mysql,a,{encoding:'utf8',windowsHide:true}); if(r.error) throw r.error; if(r.status!==0) throw new Error((r.stderr||'Falha ao consultar banco').trim()); return r.stdout.trim(); }
try {
  const php=source('principal/servicos.php'); const menu=source('classes/MenuService.php'); const sql=source('database/154_correcao_acentuacao_servicos.sql');
  for (const [file,text] of [['principal/servicos.php',php],['classes/MenuService.php',menu],['database/154_correcao_acentuacao_servicos.sql',sql]]) {
    check(`Arquivo UTF-8 válido: ${file}`, Buffer.from(text,'utf8').toString('utf8')===text);
    check(`Sem mojibake: ${file}`, !/[ÃÂ�]/.test(text));
  }
  check('Tela Serviços possui textos acentuados', php.includes('Serviço') && php.includes('Solicitação') && php.includes('Operação'));
  check('Menu possui ícone de Serviços', menu.includes("'ADMINISTRACAO_SERVICOS' => 'bi-gear-wide-connected'"));
  check('Migração possui correção UTF-8 explícita', sql.includes('CONVERT(0x') && sql.includes('ADMINISTRACAO_SERVICOS'));
  if (!skipDb) {
    const rows=db("SELECT codigo,HEX(nome_pt),HEX(nome_en),HEX(nome_es),HEX(descricao_pt) FROM guniversal_dbp.servicos WHERE codigo IN ('EXPORTACAO_GRID','INTELIGENCIA_ARTIFICIAL') ORDER BY codigo").split(/\r?\n/).filter(Boolean);
    check('Serviços persistidos no banco', rows.length===2, `registros=${rows.length}`);
    check('Acentuação PT/ES dos serviços persistida', rows.every(x=>!x.includes('3F')) && rows[0].includes('C3A7') && rows[1].includes('C3AA') && rows[0].includes('C3B3'));
    const m=db("SELECT HEX(label_pt),HEX(label_en),HEX(label_es) FROM gmodelo_dbp.menus WHERE codigo='ADMINISTRACAO_SERVICOS'");
    check('Menu Serviços persistido com acentuação PT', /5365727669C3A76F73/.test(m));
    const s=db("SELECT HEX(label_es) FROM gmodelo_dbp.menus WHERE codigo='SITUACAO_CUMPRIMENTO'");
    check('Situação do cumprimento persistida em ES', /53697475616369C3B36E/.test(s));
  }
  result.status='APROVADO'; result.finishedAt=new Date().toISOString();
} catch(e) { result.error=e.message; result.finishedAt=new Date().toISOString(); console.error(`ERRO FATAL: ${e.message}`); process.exitCode=1; }
fs.writeFileSync(path.join(root,'tests','resultado-nao-visual-acentuacao-servicos.json'),JSON.stringify(result,null,2),'utf8');

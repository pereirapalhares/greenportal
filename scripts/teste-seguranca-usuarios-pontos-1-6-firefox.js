const fs=require('fs');
const base='http://127.0.0.1:4444',app='http://187.191.99.180/greenportal';
async function call(path,method='GET',body){const r=await fetch(base+path,{method,headers:{'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});const j=await r.json();if(j.value&&j.value.error)throw Error(j.value.message||j.value.error);return j.value;}
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{let sid;const checks=[];const check=(name,ok,detail='')=>{checks.push({name,ok,detail});console.log((ok?'PASS':'FAIL')+' - '+name+(detail?': '+detail:''));};try{
sid=(await call('/session','POST',{capabilities:{alwaysMatch:{browserName:'firefox','moz:firefoxOptions':{binary:'C:\\Program Files\\Mozilla Firefox\\firefox.exe',args:['-headless']}}}})).sessionId;
const a=p=>'/session/'+sid+p;await call(a('/url'),'POST',{url:app+'/index.php'});await wait(800);
await call(a('/execute/sync'),'POST',{script:"var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),p=document.querySelector('#senha'),f=document.querySelector('form.login-form');if(e.tagName==='SELECT'){var o=Array.from(e.options).find(x=>/atlas/i.test(x.textContent)||/atlas/i.test(x.value));if(o)e.value=o.value;}else{e.value='ATLAS';}u.value='admin';p.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();",args:[]});await wait(2200);
await call(a('/url'),'POST',{url:app+'/principal/seguranca_usuarios.php'});await wait(1800);
const state=await call(a('/execute/sync'),'POST',{script:`return (function(){var t=document.querySelector('#userBatchGrid');if(!t)return {problema:'grid ausente',url:location.href,title:document.title,body:document.body.innerText.slice(0,800)};return {headers:Array.from(t.querySelectorAll('thead th')).map(x=>x.dataset.column),rows:t.querySelectorAll('tbody tr[data-key]').length,passwords:t.querySelectorAll('tbody td[data-column="senha"]').length,superiors:t.querySelectorAll('tbody td[data-column="codusuario_superior"]').length,pagination:(document.querySelector('.grid-page-status')||{}).textContent||'',body:document.body.innerText.slice(0,500)};})()`,args:[]});
console.log('ESTADO_FIREFOX',JSON.stringify(state));
check('Tela de usuários carregada',!state.problema,state.problema||'');
if(state.problema) throw Error(JSON.stringify(state));
const expected=['codperfil','codgrupo','userid','codusuario_superior','senha','nome','email','ultimo_acesso','ativo'];
check('Ordem das colunas 1-6',expected.every((x,i)=>state.headers.filter(h=>h!=='codusuario')[i]===x),JSON.stringify(state.headers));
check('Coluna Usuário superior presente',state.superiors>0,String(state.superiors));
check('Coluna Senha protegida',state.passwords>0,'células='+state.passwords);
const eye=await call(a('/execute/sync'),'POST',{script:"var b=document.querySelector('[data-toggle-user-password]');if(!b)return false;b.click();return !!document.querySelector('[data-password-value]:not([hidden])');",args:[]});check('Ícone de olho revela a senha',eye===true);
const newRow=await call(a('/execute/sync'),'POST',{script:"var b=document.querySelector('[data-new-user]');if(!b)return false;b.click();return new Promise(r=>setTimeout(()=>r(!!document.querySelector('tbody tr[data-key^=novo_] td[data-column=codusuario_superior] select')),150));",args:[]});check('Novo usuário recebe Usuário superior',newRow===true);
const count=await call(a('/execute/sync'),'POST',{script:"return fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:'action=count_rows&filtros=%7B%7D&busca='}).then(r=>r.json()).then(j=>j.ok&&Number(j.total)>=0);",args:[]});check('Contagem da paginação responde',count===true);
fs.mkdirSync('docs',{recursive:true});const shot=await call(a('/screenshot'));fs.writeFileSync('docs/teste-seguranca-usuarios-pontos-1-6-firefox.png',Buffer.from(shot,'base64'));console.log(JSON.stringify({checks,headers:state.headers},null,2));if(checks.some(x=>!x.ok))process.exitCode=1;
}catch(e){console.error('ERRO - '+e.message);process.exitCode=1;}finally{if(sid)await call('/session/'+sid,'DELETE').catch(()=>{});}})();

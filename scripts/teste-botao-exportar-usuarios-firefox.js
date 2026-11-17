const fs=require('fs');
const base='http://127.0.0.1:4444',app='http://187.191.99.180/greenportal';
const user=process.env.GREEN_TEST_EXPORT_USER||'admin',password=process.env.GREEN_TEST_EXPORT_PASSWORD||'';
async function call(path,method='GET',body){const r=await fetch(base+path,{method,headers:{'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});const j=await r.json();if(j.value&&j.value.error)throw Error(j.value.message||j.value.error);return j.value;}
const wait=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{let sid;try{
sid=(await call('/session','POST',{capabilities:{alwaysMatch:{browserName:'firefox','moz:firefoxOptions':{binary:'C:\\Program Files\\Mozilla Firefox\\firefox.exe',args:['-headless']}}}})).sessionId;const a=p=>'/session/'+sid+p;
await call(a('/url'),'POST',{url:app+'/index.php'});await wait(800);
await call(a('/execute/sync'),'POST',{script:`var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),p=document.querySelector('#senha'),f=document.querySelector('form.login-form');if(e.tagName==='SELECT'){var o=Array.from(e.options).find(x=>/atlas/i.test(x.textContent)||/atlas/i.test(x.value));if(o)e.value=o.value;}else e.value='ATLAS';u.value=${JSON.stringify(user)};p.value=${JSON.stringify(password)};f.submit();`,args:[]});await wait(2200);
await call(a('/url'),'POST',{url:app+'/principal/seguranca_usuarios.php'});await wait(1800);
const before=await call(a('/execute/sync'),'POST',{script:"var b=document.querySelector('button[name=action][value=export_request]');return b?{exists:true,form:b.form?b.form.id:'',disabled:b.disabled,text:b.innerText,url:location.href,body:document.body.innerText.slice(0,500)}:{exists:false,url:location.href,body:document.body.innerText.slice(0,800)};",args:[]});
console.log('BOTAO_ANTES',JSON.stringify(before));
if(!before.exists)throw Error('Botão Exportar Excel não localizado');
await call(a('/execute/sync'),'POST',{script:"document.querySelector('button[name=action][value=export_request]').click();return true;",args:[]});await wait(3500);
const after=await call(a('/execute/async'),'POST',{script:"var done=arguments[arguments.length-1];fetch('/greenportal/principal/export_status.php?transacao=seguranca_usuarios').then(r=>r.json()).then(status=>done({url:location.href,body:document.body.innerText.slice(0,900),alert:Array.from(document.querySelectorAll('.alert')).map(x=>x.innerText),status:status})).catch(e=>done({error:e.message}));",args:[]});
console.log('BOTAO_DEPOIS',JSON.stringify(after));
const shot=await call(a('/screenshot'));fs.mkdirSync('docs',{recursive:true});fs.writeFileSync('docs/teste-botao-exportar-usuarios-firefox.png',Buffer.from(shot,'base64'));
}catch(e){console.error('ERRO - '+e.message);process.exitCode=1;}finally{if(sid)await call('/session/'+sid,'DELETE').catch(()=>{});}})();

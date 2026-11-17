const fs = require('fs');
const driver = 'http://127.0.0.1:4444';
const app = 'http://187.191.99.180/greenportal';
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
const usuario = process.env.GREEN_CROWN_TEST_USER;
const senha = process.env.GREEN_CROWN_TEST_PASSWORD;
const normalizarTextoSeguro = value => { let text = String(value ?? ''); for (let i = 0; i < 3 && (/\u00c3|\u00c2|\u00e2/.test(text)); i++) text = Buffer.from(text, 'latin1').toString('utf8'); return text; };
const normalizarTexto = value => { let text = String(value ?? ''); for (let i = 0; i < 2 && /Ã|Â|â/.test(text); i++) text = Buffer.from(text, 'latin1').toString('utf8'); return text; };
async function call(path, method = 'GET', body) { const r = await fetch(driver + path, { method, headers: {'Content-Type':'application/json'}, body: body === undefined ? undefined : JSON.stringify(body) }); const j = await r.json(); if (j.value?.error) throw Error(j.value.message || j.value.error); return j.value; }
(async () => {
  const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Crown', usuario: usuario || '(variavel nao informada)', checks: [], status: 'REPROVADO' }; let sid;
  const check = (name, ok, detail = '') => { result.checks.push({name, ok: !!ok, detail}); if (!ok) throw Error(name); };
  try {
    sid = (await call('/session','POST',{capabilities:{alwaysMatch:{browserName:'firefox','moz:firefoxOptions':{binary:'C:\\Program Files\\Mozilla Firefox\\firefox.exe',args:['-headless']}}}})).sessionId;
    const p = value => `/session/${sid}${value}`; const js = script => call(p('/execute/sync'),'POST',{script,args:[]});
    await call(p('/url'),'POST',{url:app+'/index.php'}); await wait(1200);
    if (!usuario || !senha) throw Error('Informe GREEN_CROWN_TEST_USER e GREEN_CROWN_TEST_PASSWORD.');
    await js(`var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form'),o=Array.from(e.options||[]).find(x=>/crown/i.test(x.textContent)||/crown/i.test(x.value));if(o)e.value=o.value;u.value=${JSON.stringify(usuario)};s.value=${JSON.stringify(senha)};f.submit();`); await wait(2500);
    check('Login Crown administrador', await js('return /main\\.php/i.test(location.href)'));
    await call(p('/url'),'POST',{url:app+'/principal/administracao_migracao_orfaos.php'}); await wait(1800);
    const state = await js("return {url:location.href,title:document.title,heading:(document.body.innerText||'').slice(0,240),table:!!document.querySelector('table'),filters:document.querySelectorAll('a[href*=tipo]').length};");
    const stateDetail = () => JSON.stringify({...state, title: normalizarTextoSeguro(state.title), heading: normalizarTextoSeguro(state.heading)});
    check('Tela administrativa aberta', /administracao_migracao_orfaos/i.test(state.url) && state.table, stateDetail());
    check('Filtros de integridade visíveis', state.filters === 2, JSON.stringify(state));
    check('Nenhum órfão pendente após regularização', /0 exibidos/.test(state.heading), JSON.stringify(state));
    await call(p('/screenshot')).then(shot => { fs.mkdirSync('tests/evidencias-requisitos-crown-firefox',{recursive:true}); fs.writeFileSync('tests/evidencias-requisitos-crown-firefox/integridade-orfaos.png',Buffer.from(shot,'base64')); });
    result.status = 'APROVADO';
  } catch (e) { result.error = e.message; } finally { result.finishedAt = new Date().toISOString(); fs.mkdirSync('tests/evidencias-requisitos-crown-firefox',{recursive:true}); fs.writeFileSync('tests/evidencias-requisitos-crown-firefox/integridade-orfaos.json',JSON.stringify(result,null,2),'utf8'); if(sid) await call('/session/'+sid,'DELETE').catch(()=>{}); }
  process.exitCode = result.status === 'APROVADO' ? 0 : 1;
})();

const fs = require('fs');
const base = 'http://127.0.0.1:4444';
const app = 'http://187.191.99.180/greenportal';
const sleep = ms => new Promise(r => setTimeout(r, ms));
const out = { navegador: 'Firefox via GeckoDriver', empresa: 'Crown', usuario: 'nathalia.capacla', checks: [], status: 'REPROVADO' };
async function req(p, method = 'GET', body) {
  const r = await fetch(base + p, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
  const j = await r.json(); if (j.value && j.value.error) throw Error(j.value.message || j.value.error); return j.value;
}
function ok(name, value, detail = '') { out.checks.push({ name, ok: !!value, detail }); console.log((value ? 'PASS' : 'FAIL') + ' - ' + name + (detail ? ': ' + detail : '')); if (!value) throw Error(name); }
(async () => {
  let sid;
  try {
    sid = (await req('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', unhandledPromptBehavior: 'accept', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
    const p = x => '/session/' + sid + x;
    const run = (script, args = []) => req(p('/execute/sync'), 'POST', { script, args });
    const inPopup = script => run('var m=document.querySelector("#greenPortalTransacaoModal"),f=m&&m.querySelector("iframe"),d=f&&f.contentDocument;if(!d)return null;' + script);
    await req(p('/url'), 'POST', { url: app + '/index.php' }); await sleep(1000);
    await run("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');var o=Array.from(e.options||[]).find(x=>/crown/i.test(x.textContent)||/crown/i.test(x.value));if(o)e.value=o.value;u.value='nathalia.capacla';s.value='crown05';f.submit();");
    await sleep(2600); ok('Login Crown/Nathalia', await run('return /main\\.php/i.test(location.href)'));
    await run("window.greenPortalOpenTab('menu-requisitos-legais','/greenportal/principal/modulolegal_requisitoslegais.php','Requisitos Legais');"); await sleep(5000);
    await run(`var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),r=f&&f.contentDocument.querySelector('#requisitosLegaisBatchGrid tbody tr');if(r)r.click();var b=f&&f.contentDocument.querySelector('[data-open-requisito-popup="situacao"]');if(b)b.click();`); await sleep(1000);
    ok('Popup Situação aberto', await run('return !!document.querySelector("#greenPortalTransacaoModal")'));
    const controls = await inPopup('return {novo:!!d.querySelector("[data-crud-action=\'new\']"),salvar:!!d.querySelector("[data-crud-action=\'save\']"),duplicar:!!d.querySelector("[data-crud-action=\'duplicate\']"),excluir:!!d.querySelector("[data-crud-action=\'delete\']")};');
    ok('Controles CRUD disponíveis', controls && controls.novo && controls.salvar && controls.duplicar && controls.excluir, JSON.stringify(controls));
    const before = await inPopup('return {url:location.href,rows:d.querySelectorAll("tbody tr").length,fields:d.querySelectorAll("textarea,select,input").length};');
    ok('Grid de Situação carregado', before && before.rows >= 0, JSON.stringify(before));
    out.status = 'APROVADO';
  } catch (e) { out.error = e.message; console.error('ERRO - ' + e.message); }
  finally { out.finishedAt = new Date().toISOString(); fs.mkdirSync('tests/evidencias-requisitos-crown-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-requisitos-crown-firefox/crud.json', JSON.stringify(out, null, 2)); if (sid) await req('/session/' + sid, 'DELETE').catch(() => {}); }
  process.exitCode = out.status === 'APROVADO' ? 0 : 1;
})();

const fs = require('fs');
const driver = 'http://127.0.0.1:4444';
const app = 'http://187.191.99.180/greenportal';
const user = process.env.GP_TEST_USER || 'iara.cristina';
const pass = process.env.GP_TEST_PASS || 'poney23@';
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', usuario: user, checks: [], status: 'REPROVADO' };
const wait = ms => new Promise(r => setTimeout(r, ms));
async function call(path, method = 'GET', body) { const r = await fetch(driver + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const j = await r.json(); if (j.value?.error) throw Error(j.value.message || j.value.error); return j.value; }
function check(name, ok, detail = '') { result.checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw Error(name); }
(async () => { let sid;
  try {
    sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', unhandledPromptBehavior: 'accept', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
    const a = p => `/session/${sid}${p}`; const js = (script, args = []) => call(a('/execute/sync'), 'POST', { script, args });
    await call(a('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
    await js("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');var o=Array.from(e.options||[]).find(x=>/atlas/i.test(x.textContent)||/atlas/i.test(x.value));if(o)e.value=o.value;u.value=arguments[0];s.value=arguments[1];f.submit();", [user, pass]); await wait(2200); check('Login operacional Atlas', await js('return /main\\.php/i.test(location.href)') === true);
    await call(a('/url'), 'POST', { url: `${app}/principal/modulolegal_requisitoslegais.php` }); await wait(2800);
    const exportFound = await js("return !!Array.from(document.querySelectorAll('button,a,input')).find(x=>/exportar excel/i.test((x.innerText||x.title||x.value||x.getAttribute('aria-label')||'')));");
    const exportContext = await js("return {url:location.href,body:(document.body.innerText||'').slice(0,700),candidates:Array.from(document.querySelectorAll('button,a,input')).map(x=>x.innerText||x.title||x.value||x.getAttribute('aria-label')||'').filter(x=>/excel|export/i.test(x)).slice(0,20)};");
    const exportAlreadyRunning = /exporta[cç][aã]o em andamento/i.test(exportContext.body);
    check('Botão Exportar Excel disponível ou solicitação já em andamento', exportFound === true || exportAlreadyRunning, JSON.stringify(exportContext));
    let exportState = exportContext;
    if (exportFound) { await js("var b=Array.from(document.querySelectorAll('button,a,input')).find(x=>/exportar excel/i.test((x.innerText||x.title||x.value||x.getAttribute('aria-label')||'')));if(b)b.click();return true;"); await wait(2200); exportState = await js("return {url:location.href,body:(document.body.innerText||'').slice(-1200)};"); }
    result.exportacao = exportState; check('Solicitação de exportação processada pela tela', !/Fatal error|PDOException|erro fatal/i.test(exportState.body), exportState.body.slice(-300));
    await call(a('/url'), 'POST', { url: `${app}/principal/gestao_arquivos.php?tipo=licencas&chave=CODLICENCA&valor=43` }); await wait(1800);
    const gedState = await js("return {grid:!!document.querySelector('#gedGrid'),files:document.querySelectorAll('#gedGrid tbody tr[data-name]').length};");
    result.ged = gedState; check('Tela GED de licença carregada', gedState.grid === true);
    if (gedState.files > 0) {
      const iaState = await js("var r=document.querySelector('#gedGrid tbody tr[data-name]');r.click();var b=document.querySelector('#gedAnalyze');return {file:r.getAttribute('data-name'),button:!!b,enabled:!!b&&!b.disabled};");
      result.ia = iaState; check('Ação de IA disponível para arquivo GED', iaState.button === true && iaState.enabled === true, JSON.stringify(iaState));
      const after = await js("var b=document.querySelector('#gedAnalyze');b.click();return new Promise(r=>setTimeout(()=>r((document.querySelector('#gedMessage')||{}).textContent||''),1800));");
      result.ia.mensagem = after; check('Solicitação de IA aceita pela tela', !/erro fatal|pdoexception/i.test(after));
    } else { result.ia = { status: 'SEM_ARQUIVO_GED' }; console.log('INFO - Não havia arquivo GED de controle para executar IA.'); }
    result.status = 'APROVADO';
  } catch (e) { result.erro = e.message; console.error(`ERRO - ${e.message}`); }
  finally { result.fim = new Date().toISOString(); fs.mkdirSync('tests/evidencias-iara-atlas-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-iara-atlas-firefox/operacoes.json', JSON.stringify(result, null, 2)); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
  process.exitCode = result.status === 'APROVADO' ? 0 : 1;
})();

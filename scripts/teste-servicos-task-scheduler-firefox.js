const fs = require('fs');
const driver = 'http://127.0.0.1:4444';
const app = 'http://187.191.99.180/greenportal';
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', usuario: 'admin', checks: [], status: 'REPROVADO' };
const wait = ms => new Promise(r => setTimeout(r, ms));
async function call(path, method = 'GET', body) { const r = await fetch(driver + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const j = await r.json(); if (j.value?.error) throw Error(j.value.message || j.value.error); return j.value; }
function check(name, ok, detail = '') { result.checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw Error(name); }
(async () => { let sid;
  try {
    sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', unhandledPromptBehavior: 'accept', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
    const a = p => `/session/${sid}${p}`; const js = (script, args = []) => call(a('/execute/sync'), 'POST', { script, args });
    await call(a('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
    await js("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');var o=Array.from(e.options||[]).find(x=>/atlas/i.test(x.textContent)||/atlas/i.test(x.value));if(o)e.value=o.value;u.value='admin';s.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();"); await wait(2200); check('Login ADMIN Atlas', await js('return /main\\.php/i.test(location.href)') === true);
    await call(a('/url'), 'POST', { url: `${app}/principal/servicos.php` }); await wait(1800);
    const state = await js("return {rows:Array.from(document.querySelectorAll('#servicosBatchGrid tbody tr[data-service-code]')).map(r=>({code:r.dataset.serviceCode,text:r.innerText,state:(r.querySelector('[data-column=estado]')||{}).innerText||'',task:(r.querySelector('[data-column=tarefa]')||{}).innerText||''})),body:(document.body.innerText||'').slice(0,900)};");
    const expected = ['TASK_WORKER','AGENDA_WORKER','RESPONSIBILITY_WORKER','WORKFLOW_WORKER','CONTENT_WORKER','GED_WORKER','INTELIGENCIA_ARTIFICIAL','OCR_WORKER','NOTIFICATION_WORKER','EXPORTACAO_GRID','SERVICE_MONITOR','BUSCA_WORKER'];
    result.state = state; const greenRows = state.rows.filter(r => expected.includes(r.code)); check('Tela de serviços carregada', greenRows.length === 12, `GreenPortal=${greenRows.length}; catálogo total=${state.rows.length}`); check('Todos os 12 códigos GreenPortal presentes', expected.every(x => state.rows.some(r => r.code === x)), expected.filter(x => !state.rows.some(r => r.code === x)).join(',')); check('Tarefas GreenPortal visíveis', greenRows.every(r => /GreenPortal-/i.test(r.task)), greenRows.map(r=>r.task).join(' | ')); check('Nenhum serviço GreenPortal reporta ERRO', greenRows.every(r => !/ERRO|NAO_INSTALADO/i.test(r.state)), greenRows.filter(r=>/ERRO|NAO_INSTALADO/i.test(r.state)).map(r=>`${r.code}:${r.state}`).join(','));
    const shot = await call(a('/screenshot')); fs.mkdirSync('tests/evidencias-servicos-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-servicos-firefox/servicos.png', Buffer.from(shot, 'base64')); result.status = 'APROVADO';
  } catch (e) { result.erro = e.message; console.error(`ERRO - ${e.message}`); }
  finally { result.fim = new Date().toISOString(); fs.mkdirSync('tests/evidencias-servicos-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-servicos-firefox/resultado.json', JSON.stringify(result, null, 2)); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
  process.exitCode = result.status === 'APROVADO' ? 0 : 1;
})();

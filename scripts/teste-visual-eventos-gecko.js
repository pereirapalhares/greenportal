const fs = require('fs');
const base = 'http://127.0.0.1:4444';
const appUrl = process.env.GREENPORTAL_URL || 'http://127.0.0.1:8080';
const result = { navegador: 'Firefox', status: 'REPROVADO', checks: [] };
const check = (name, ok, detail = '') => { result.checks.push({ name, ok, detail }); console.log((ok ? 'PASS' : 'FAIL') + ' - ' + name + (detail ? ': ' + detail : '')); };
async function call(path, method = 'GET', body) { const response = await fetch(base + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const json = await response.json(); if (json.value && json.value.error) throw new Error(json.value.message || json.value.error); return json.value; }
(async () => {
  let session;
  try {
    const created = await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } });
    session = created.sessionId || created; const api = p => '/session/' + session + p;
    await call(api('/url'), 'POST', { url: appUrl + '/index.php' }); await new Promise(r => setTimeout(r, 1000));
    await call(api('/execute/sync'), 'POST', { script: "var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');if(!e||!u||!s||!f)throw new Error('Login nao carregado');e.value='MODELO';u.value='admin';s.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();", args: [] }); await new Promise(r => setTimeout(r, 1800));
    check('Login admin', (await call(api('/execute/sync'), 'POST', { script: 'return location.href.indexOf("main.php") >= 0;', args: [] })) === true);
    await call(api('/url'), 'POST', { url: appUrl + '/principal/medicaoemonitoramento_mm_monitoramento.php' }); await new Promise(r => setTimeout(r, 1800));
    const key = await call(api('/execute/sync'), 'POST', { script: "var r=document.querySelector('tbody tr[data-row]');return r?r.getAttribute('data-key'):'';", args: [] });
    check('Monitoramento localizado', !!key, key);
    if (!key) throw new Error('Nenhum monitoramento disponivel');
    await call(api('/url'), 'POST', { url: appUrl + '/principal/eventos.php?codmonitoramento=' + encodeURIComponent(key) }); await new Promise(r => setTimeout(r, 1400));
    check('Grid de eventos', await call(api('/execute/sync'), 'POST', { script: 'return !!document.querySelector(".event-grid-fragment .menu-batch-grid");', args: [] }));
    check('Toolbar e busca', await call(api('/execute/sync'), 'POST', { script: 'return !!document.querySelector(".event-grid-fragment .crud-toolbar") && !!document.querySelector(".event-grid-fragment .crud-search");', args: [] }));
    check('Filtros nas colunas', await call(api('/execute/sync'), 'POST', { script: 'return document.querySelectorAll(".event-grid-fragment .column-filter").length >= 4;', args: [] }));
    check('Paginação junto ao toolbar', await call(api('/execute/sync'), 'POST', { script: 'var t=document.querySelector(".event-grid-fragment .crud-toolbar"),p=document.querySelector(".event-grid-fragment .grid-pagination");return !!t&&!!p&&t.contains(p);', args: [] }));
    const rows = await call(api('/elements'), 'POST', { using: 'css selector', value: '.event-grid-fragment tbody tr.event-row' }); check('Linhas de eventos', rows.length > 0, String(rows.length));
    const gridShot = await call(api('/screenshot')); fs.writeFileSync('tests/evidencias_eventos_grid_firefox.png', Buffer.from(gridShot, 'base64'));
    if (rows.length) { await call(api('/execute/sync'), 'POST', { script: 'document.querySelector("tbody tr.event-row").click();', args: [] }); check('Clique abre aba de edição', await call(api('/execute/sync'), 'POST', { script: 'return document.querySelector("[data-event-pane=\\"form\\"]").classList.contains("active") && document.querySelector("[data-form-tab-label]").textContent.indexOf("Editar Evento (")===0;', args: [] })); }
    const shot = await call(api('/screenshot')); fs.writeFileSync('tests/evidencias_eventos_firefox.png', Buffer.from(shot, 'base64')); result.evidence = 'tests/evidencias_eventos_firefox.png'; result.status = result.checks.every(x => x.ok) ? 'APROVADO' : 'REPROVADO';
  } catch (error) { result.error = error.message; console.log('ERRO - ' + error.message); }
  finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync('tests/resultado-visual-eventos-firefox-20261006.json', JSON.stringify(result, null, 2)); if (session) await call('/session/' + session, 'DELETE').catch(() => {}); }
  process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

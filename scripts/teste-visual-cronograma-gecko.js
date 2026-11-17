const fs = require('fs');
const base = 'http://127.0.0.1:4444';
const appUrl = process.env.GREENPORTAL_URL || 'http://localhost/greenportal';
const result = { navegador: 'Firefox', status: 'REPROVADO', checks: [] };
const check = (name, ok, detail = '') => { result.checks.push({ name, ok, detail }); console.log((ok ? 'PASS' : 'FAIL') + ' - ' + name + (detail ? ': ' + detail : '')); };
async function call(path, method = 'GET', body) { const response = await fetch(base + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const json = await response.json(); if (json.value && json.value.error) throw new Error(json.value.message || json.value.error); return json.value; }
(async () => {
  let session;
  try {
    const created = await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } });
    session = created.sessionId || created;
    const api = p => '/session/' + session + p;
    await call(api('/url'), 'POST', { url: appUrl + '/index.php' });
    await new Promise(r => setTimeout(r, 1200));
    await call(api('/execute/sync'), 'POST', { script: "var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');if(!e||!u||!s||!f)throw new Error('Login não carregado');e.value='MODELO';u.value='admin';s.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();", args: [] });
    await new Promise(r => setTimeout(r, 2200));
    check('Login admin', (await call(api('/execute/sync'), 'POST', { script: 'return location.href.indexOf("main.php") >= 0;', args: [] })) === true);
    await call(api('/url'), 'POST', { url: appUrl + '/principal/medicaoemonitoramento_mm_monitoramento.php' });
    await new Promise(r => setTimeout(r, 2200));
    const grid = await call(api('/element'), 'POST', { using: 'css selector', value: '.menu-batch-grid' });
    check('Grid carregado', !!grid);
    const headers = await call(api('/execute/sync'), 'POST', { script: 'return Array.from(document.querySelectorAll("thead th")).map(x=>x.dataset.column||x.innerText.trim());', args: [] });
    check('Colunas Área, Aspecto, Perigo e Cronograma', headers.includes('CODAREA') && headers.includes('CODASPECTO') && headers.includes('CODPERIGO') && headers.includes('cronograma'), JSON.stringify(headers));
    const rows = await call(api('/elements'), 'POST', { using: 'css selector', value: 'tbody tr' });
    check('Linhas carregadas', rows.length > 0, 'Linhas: ' + rows.length);
    if (rows.length > 0) {
      await call(api('/execute/sync'), 'POST', { script: 'document.querySelector("tbody tr:first-child").click();', args: [] });
      check('Seleção por clique', await call(api('/execute/sync'), 'POST', { script: 'return document.querySelector("tbody tr:first-child").classList.contains("selected");', args: [] }));
      const cron = await call(api('/element'), 'POST', { using: 'css selector', value: 'tbody tr:first-child [data-control-relacionamento="cronograma"]' });
      check('Botão Cronograma presente', !!cron);
    }
    const shot = await call(api('/screenshot')); fs.writeFileSync('tests/evidencias_cronograma_evento_firefox.png', Buffer.from(shot, 'base64')); result.evidence = 'tests/evidencias_cronograma_evento_firefox.png'; result.status = result.checks.every(x => x.ok) ? 'APROVADO' : 'REPROVADO';
  } catch (error) { result.error = error.message; console.log('ERRO - ' + error.message); }
  finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync('tests/resultado-visual-cronograma-firefox-20261002.json', JSON.stringify(result, null, 2)); if (session) await call('/session/' + session, 'DELETE').catch(() => {}); }
  process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

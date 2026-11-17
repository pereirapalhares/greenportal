const fs = require('fs');
const driver = process.env.GP_GECKO_URL || 'http://127.0.0.1:4444';
const app = process.env.GREENPORTAL_URL || 'http://187.191.99.180/greenportal';
const user = process.env.GP_TEST_USER || 'iara.cristina';
const pass = process.env.GP_TEST_PASS || 'poney23@';
const out = 'tests/evidencias-iara-atlas-firefox';
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', usuario: user, inicio: new Date().toISOString(), telas: [], checks: [], status: 'REPROVADO' };
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
async function call(path, method = 'GET', body) { const response = await fetch(driver + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const data = await response.json(); if (data.value && data.value.error) throw new Error(data.value.message || data.value.error); return data.value; }
function check(name, ok, detail = '') { result.checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name + (detail ? `: ${detail}` : '')); }
(async () => {
  let sid;
  try {
    fs.mkdirSync(out, { recursive: true });
    sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
    const a = p => `/session/${sid}${p}`;
    const js = async (script, args = []) => call(a('/execute/sync'), 'POST', { script, args });
    await call(a('/url'), 'POST', { url: `${app}/index.php` }); await wait(1000);
    await js("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');if(!e||!u||!s||!f)throw Error('formulario de login ausente');var o=Array.from(e.options||[]).find(x=>/atlas/i.test(x.textContent)||/atlas/i.test(x.value));if(o)e.value=o.value;u.value=arguments[0];s.value=arguments[1];f.submit();", [user, pass]);
    await wait(2500);
    const login = await js('return /main\\.php/i.test(location.href) && !/login/i.test(document.body.innerText.slice(0,200));');
    check('Login Atlas realizado', login === true);
    const menu = await js("return Array.from(document.querySelectorAll('button[data-open-tab][data-tab-url]')).map(x=>({url:x.dataset.tabUrl,text:(x.innerText||x.getAttribute('aria-label')||'').trim()})).filter(x=>x.url&&!/login|logout/i.test(x.url));");
    check('Menus autorizados carregados', Array.isArray(menu) && menu.length > 0, String(menu?.length));
    result.menuCount = menu.length;
    const seen = new Set(); let exportTested = false;
    for (let i = 0; i < menu.length && i < 80; i++) {
      const item = menu[i]; if (seen.has(item.url)) continue; seen.add(item.url);
      const origin = new URL(app).origin;
      const url = item.url.startsWith('http') ? item.url : item.url.startsWith('/') ? `${origin}${item.url}` : `${app}/${item.url.replace(/^\.\//, '')}`;
      await call(a('/url'), 'POST', { url }); await wait(650);
      const state = await js("return {url:location.href,title:document.title,body:(document.body.innerText||'').slice(0,1200),grids:document.querySelectorAll('table').length,forms:document.querySelectorAll('form').length,crud:Array.from(document.querySelectorAll('button,a')).map(x=>(x.innerText||x.title||x.getAttribute('aria-label')||'').trim()).filter(x=>/novo|salvar|editar|excluir|duplicar|exportar|recarregar/i.test(x)).slice(0,30),exportar:!!Array.from(document.querySelectorAll('button,a')).find(x=>/exportar excel/i.test((x.innerText||x.title||''))),ia:!!document.querySelector('[data-request-ia-analysis],#gedAnalyze,[data-ged-analyze]')};");
      const fatal = /Fatal error|Parse error|PDOException|Call Stack|500 Internal Server Error|Access denied/i.test(state.body);
      if (fatal) check(`Tela sem erro fatal: ${item.text || item.url}`, false, state.body.slice(0,300));
      result.telas.push({ menu: item.text, url: item.url, ...state, fatal });
      if (state.exportar && !exportTested) {
        const shot = await call(a('/screenshot')); fs.writeFileSync(`${out}/exportacao-${i}.png`, Buffer.from(shot, 'base64')); exportTested = true; console.log(`INFO - Exportacao localizada em ${item.text || item.url}`);
      }
    }
    check('Nenhuma tela autorizada apresentou erro fatal', result.telas.every(x => !x.fatal), `${result.telas.filter(x=>x.fatal).length} falha(s)`);
    check('Pelo menos uma tela com estrutura de grid foi carregada', result.telas.some(x => x.grids > 0));
    check('Pelo menos uma tela com controles CRUD foi carregada', result.telas.some(x => x.crud.length > 0));
    result.exportTested = exportTested;
    result.status = 'APROVADO';
  } catch (error) { result.erro = error.message; console.error(`ERRO - ${error.message}`); }
  finally { result.fim = new Date().toISOString(); fs.writeFileSync(`${out}/resultado.json`, JSON.stringify(result, null, 2), 'utf8'); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
  process.exitCode = result.status === 'APROVADO' ? 0 : 1;
})();

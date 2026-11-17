const fs = require('fs');
const driver = 'http://127.0.0.1:4444';
const app = 'http://187.191.99.180/greenportal';
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Crown', usuario: 'nathalia.capacla', checks: [], status: 'REPROVADO' };
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
async function call(path, method = 'GET', body) { const response = await fetch(driver + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const json = await response.json(); if (json.value?.error) throw Error(json.value.message || json.value.error); return json.value; }
function check(name, ok, detail = '') { result.checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw Error(name); }
function elementId(element) { return element && (element['element-6066-11e4-a52e-4f735466cecf'] || element.ELEMENT); }
(async () => { let sid;
  try {
    sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', unhandledPromptBehavior: 'accept', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
    const a = path => `/session/${sid}${path}`;
    const js = (script, args = []) => call(a('/execute/sync'), 'POST', { script, args });
    await call(a('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
    await js("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');var o=Array.from(e.options||[]).find(x=>/crown/i.test(x.textContent)||/crown/i.test(x.value));if(o)e.value=o.value;u.value='nathalia.capacla';s.value='crown05';f.submit();"); await wait(2600);
    check('Login Crown/Nathalia', await js('return /main\\.php/i.test(location.href)') === true);
    await js("window.greenPortalOpenTab('menu-requisitos-legais','/greenportal/principal/modulolegal_requisitoslegais.php','Requisitos Legais');"); await wait(5000);
    await js("var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument&&x.contentDocument.querySelector('.modulo-legal-requisitos-fragment'));if(!f)throw Error('iframe requisitos nao carregado');f.contentDocument.querySelector('[data-open-guide-requisitos]').click();"); await wait(2800);
    check('Ajuda Requisitos Legais aberta', await js('return !!document.querySelector("#greenPortalGuideDrawer iframe[src*=\\"guia_interativo_requisitos\\"]")') === true);
    await js("var f=document.querySelector('#greenPortalGuideDrawer iframe'),b=f&&f.contentDocument.querySelector('[data-help-start]');if(!b)throw Error('botao Comecar nao encontrado');b.click();"); await wait(900);
    const initial = await js("var topCard=document.querySelector('[data-green-guide-card]'),rf=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),frameCard=rf&&rf.contentDocument.querySelector('[data-green-guide-card]'),c=topCard||frameCard;return {card:!!c,topCard:!!topCard,frameCard:!!frameCard,label:c?.querySelector('.guide-card-label')?.textContent||'',next:!!c?.querySelector('[data-card-next]'),target:!!(rf&&rf.contentDocument.querySelector('[data-green-tour-target]'))};");
    check('Comecar mostra Passo 1 sem travar', initial.card && /Passo 1 de 4/i.test(initial.label) && initial.next && initial.target, JSON.stringify(initial));
    const next = await call(a('/element'), 'POST', { using: 'css selector', value: '[data-green-guide-card] [data-card-next]' });
    const nextId = elementId(next); check('Botao Proximo localizado na tela principal', !!nextId);
    const nextBox = await js("var b=document.querySelector('[data-green-guide-card] [data-card-next]'),r=b&&b.getBoundingClientRect(),hit=r&&document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return {rect:r?{x:r.x,y:r.y,w:r.width,h:r.height}:null,disabled:!!b?.disabled,pointer:b?getComputedStyle(b).pointerEvents:'',hit:hit?hit.outerHTML.slice(0,120):''};");
    console.log('INFO - Botao Proximo', JSON.stringify(nextBox));
    await call(a('/actions'), 'POST', { actions: [{ type: 'pointer', id: 'mouse', parameters: { pointerType: 'mouse' }, actions: [{ type: 'pointerMove', origin: { 'element-6066-11e4-a52e-4f735466cecf': nextId }, x: 0, y: 0 }, { type: 'pointerDown', button: 0 }, { type: 'pointerUp', button: 0 }] }] }); await call(a('/actions'), 'DELETE', {}); await wait(500);
    const second = await js("var c=document.querySelector('[data-green-guide-card]');return {label:c?.querySelector('.guide-card-label')?.textContent||'',text:c?.querySelector('.guide-card-text')?.textContent||''};");
    check('Clique real em Proximo avanca para o Passo 2', /Passo 2 de 4/i.test(second.label), JSON.stringify(second));
    const prev = await call(a('/element'), 'POST', { using: 'css selector', value: '[data-green-guide-card] [data-card-prev]' });
    const prevId = elementId(prev); check('Botao Voltar localizado na tela principal', !!prevId);
    await call(a('/actions'), 'POST', { actions: [{ type: 'pointer', id: 'mouse', parameters: { pointerType: 'mouse' }, actions: [{ type: 'pointerMove', origin: { 'element-6066-11e4-a52e-4f735466cecf': prevId }, x: 0, y: 0 }, { type: 'pointerDown', button: 0 }, { type: 'pointerUp', button: 0 }] }] }); await call(a('/actions'), 'DELETE', {}); await wait(350);
    check('Clique real em Voltar retorna ao Passo 1', await js("var c=document.querySelector('[data-green-guide-card]');return !!(c&&/Passo 1 de 4/i.test(c.querySelector('.guide-card-label')?.textContent||''))") === true);
    await call(a('/screenshot')).then(shot => { fs.mkdirSync('tests/evidencias-guia-crown-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-guia-crown-firefox/guia-passo1.png', Buffer.from(shot, 'base64')); });
    result.status = 'APROVADO';
  } catch (error) { result.erro = error.message; console.error(`ERRO - ${error.message}`); }
  finally { result.fim = new Date().toISOString(); fs.mkdirSync('tests/evidencias-guia-crown-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-guia-crown-firefox/resultado.json', JSON.stringify(result, null, 2)); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
  process.exitCode = result.status === 'APROVADO' ? 0 : 1;
})();

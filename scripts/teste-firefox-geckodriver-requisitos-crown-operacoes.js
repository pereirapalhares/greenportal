const fs = require('fs');
const driver = 'http://127.0.0.1:4444';
const app = 'http://187.191.99.180/greenportal';
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Crown', usuario: 'nathalia.capacla', checks: [], status: 'REPROVADO' };
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
async function call(path, method = 'GET', body) { const response = await fetch(driver + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const json = await response.json(); if (json.value?.error) throw Error(json.value.message || json.value.error); return json.value; }
function check(name, ok, detail = '') { result.checks.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw Error(name); }
(async () => { let sid;
  try {
    sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', unhandledPromptBehavior: 'accept', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
    const a = p => `/session/${sid}${p}`; const js = (script, args = []) => call(a('/execute/sync'), 'POST', { script, args });
    await call(a('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
    await js("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form');var o=Array.from(e.options||[]).find(x=>/crown/i.test(x.textContent)||/crown/i.test(x.value));if(o)e.value=o.value;u.value='nathalia.capacla';s.value='crown05';f.submit();"); await wait(2600); check('Login Crown/Nathalia', await js('return /main\\.php/i.test(location.href)') === true);
    await js("window.greenPortalOpenTab('menu-requisitos-legais','/greenportal/principal/modulolegal_requisitoslegais.php','Requisitos Legais');"); await wait(5000);
    const initial = await js("var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument);return {frame:!!f,grid:!!(f&&f.contentDocument.querySelector('#requisitosLegaisBatchGrid')),rows:f?f.contentDocument.querySelectorAll('#requisitosLegaisBatchGrid tbody tr').length:0};"); check('Grid carregado', initial.frame && initial.grid && initial.rows > 0, JSON.stringify(initial));
    const selected = await js("var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),r=f&&f.contentDocument.querySelector('#requisitosLegaisBatchGrid tbody tr');if(r)r.click();return {selected:!!(r&&r.classList.contains('selected')),buttons:f?Array.from(f.contentDocument.querySelectorAll('[data-open-requisito-popup]')).map(b=>({type:b.dataset.openRequisitoPopup,enabled:!b.disabled})):[]};"); check('Seleção do requisito habilita operações', selected.selected, JSON.stringify(selected.buttons));
    const popups = ['licencas','medicaomonitoramento','treinamentos','infraestrutura','controles','homologacao','situacao','checklist','alteracoes'];
    for (const type of popups) {
      const opened = await js("var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),b=f&&f.contentDocument.querySelector('[data-open-requisito-popup=" + JSON.stringify(type) + "]');if(!b||b.disabled)return {present:!!b,enabled:false};b.click();return {present:true,enabled:true};");
      if (!opened.enabled) { result.checks.push({ name: `Popup ${type} disponível`, ok: false, detail: opened.present ? 'bloqueado pela permissão' : 'botão ausente' }); continue; }
      await wait(900);
      const modal = await js("var m=document.querySelector('#greenPortalTransacaoModal.show,#greenPortalTransacaoModal'),f=m&&m.querySelector('iframe'),d=f&&f.contentDocument,b=d?d.body:null;return {modal:!!m,frame:!!f,error:!!(b&&/(Fatal error|Uncaught|PDOException|SQLSTATE\\[|Call Stack)/i.test(b.innerText)),text:b?(b.innerText||'').slice(0,150):''};");
      check(`Popup ${type} sem erro`, modal.modal && !modal.error, JSON.stringify(modal));
      await js("var m=document.querySelector('#greenPortalTransacaoModal'),b=m&&m.querySelector('.btn-close,[data-bs-dismiss=" + JSON.stringify('modal') + "]');if(b)b.click();"); await wait(350);
    }
    const actions = [['Questionario','[data-open-questionnaire]'],['Analise IA','[data-request-ia-analysis]'],['Relatorio','[data-relatorio-atendimento]'],['Exportacao Excel','[data-export-requisitos]']];
    for (const [name, selector] of actions) {
      const state = await js("var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),b=f&&f.contentDocument.querySelector(" + JSON.stringify(selector) + ");return {present:!!b,enabled:!!(b&&!b.disabled)};");
      if (!state.enabled) { result.checks.push({ name: `${name} disponível`, ok: false, detail: state.present ? 'bloqueado pela permissão' : 'botão ausente' }); continue; }
      await js("var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),b=f&&f.contentDocument.querySelector(" + JSON.stringify(selector) + ");if(b)b.click();"); await wait(name === 'Exportacao Excel' ? 1600 : 900);
      const stateAfter = await js("return {url:location.href,body:(document.body.innerText||'').slice(-350),modal:!!document.querySelector('#greenPortalTransacaoModal.show')};"); check(`${name} executada sem erro fatal`, !/(Fatal error|Uncaught|PDOException|SQLSTATE\[|Call Stack)/i.test(stateAfter.body), JSON.stringify(stateAfter));
      await js("var m=document.querySelector('#greenPortalTransacaoModal'),b=m&&m.querySelector('.btn-close,[data-bs-dismiss=" + JSON.stringify('modal') + "]');if(b)b.click();");
    }
    await wait(2500);
    const filters = await js("var f=Array.from(document.querySelectorAll('iframe')).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),d=f&&f.contentDocument;return {frame:!!f,responsavel:!!(d&&d.querySelector('#requisitosResponsavel')),revogada:!!(d&&d.querySelector('#requisitosLeiRevogada')),evidencia:!!(d&&d.querySelector('#requisitosSemEvidencia')),busca:!!(d&&d.querySelector('.crud-search input,input[type=search]'))};"); check('Filtros e busca disponíveis', filters && filters.frame && filters.responsavel && filters.revogada && filters.evidencia && filters.busca, JSON.stringify(filters));
    const handles = await call(a('/window/handles')); check('Fluxo permaneceu em uma janela', handles.length === 1, `janelas=${handles.length}`);
    await call(a('/screenshot')).then(shot => { fs.mkdirSync('tests/evidencias-requisitos-crown-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-requisitos-crown-firefox/operacoes.png', Buffer.from(shot, 'base64')); });
    result.status = 'APROVADO';
  } catch (error) { result.error = error.message; console.error(`ERRO - ${error.message}`); }
  finally { result.finishedAt = new Date().toISOString(); fs.mkdirSync('tests/evidencias-requisitos-crown-firefox', { recursive: true }); fs.writeFileSync('tests/evidencias-requisitos-crown-firefox/operacoes.json', JSON.stringify(result, null, 2)); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
  process.exitCode = result.status === 'APROVADO' ? 0 : 1;
})();

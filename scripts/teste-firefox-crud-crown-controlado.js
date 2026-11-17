const fs = require('fs');
const base = 'http://127.0.0.1:4444';
const app = 'http://187.191.99.180/greenportal';
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Crown', usuario: 'nathalia.capacla', checks: [], status: 'REPROVADO' };
async function call(path, method = 'GET', body) {
  const response = await fetch(base + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
  const json = await response.json();
  if (json.value && json.value.error) throw Error(json.value.message || json.value.error);
  return json.value;
}
function check(name, ok, detail = '') {
  const normalized = typeof detail === 'string' && /[ÃÂ]/.test(detail)
    ? Buffer.from(Buffer.from(detail, 'utf8').toString('latin1'), 'latin1').toString('utf8')
    : detail;
  result.checks.push({ name, ok: !!ok, detail: normalized });
  console.log((ok ? 'PASS' : 'FAIL') + ' - ' + name + (normalized ? ': ' + normalized : ''));
  if (!ok) throw Error(name);
}
(async () => {
  let sid;
  const marker = 'TESTE CRUD CROWN ' + Date.now();
  try {
    sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', unhandledPromptBehavior: 'accept', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
    const path = value => '/session/' + sid + value;
    const execute = (script, args = []) => call(path('/execute/sync'), 'POST', { script, args });
    const popup = script => execute('var modal=document.querySelector("#greenPortalTransacaoModal"),frame=modal&&modal.querySelector("iframe"),doc=frame&&frame.contentDocument;if(!doc)return null;' + script);
    const confirmPopup = async () => { await wait(300); await popup('var button=doc.querySelector("#cadastroConfirmModal [data-confirm-cadastro]");if(button)button.click();return !!button;'); await wait(1200); };

    await call(path('/url'), 'POST', { url: app + '/index.php' });
    await wait(1800);
    await execute("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),s=document.querySelector('#senha'),f=document.querySelector('form.login-form'),o=Array.from(e.options||[]).find(x=>/crown/i.test(x.textContent)||/crown/i.test(x.value));if(o)e.value=o.value;u.value='nathalia.capacla';s.value='crown05';f.submit();");
    await wait(2600);
    check('Login Crown/Nathalia', await execute('return /main\\.php/i.test(location.href)'));
    await execute("window.greenPortalOpenTab('menu-requisitos-legais','/greenportal/principal/modulolegal_requisitoslegais.php','Requisitos Legais');");
    await wait(4500);
    await execute('var f=Array.from(document.querySelectorAll("iframe")).find(x=>/modulolegal_requisitoslegais/i.test(x.src)&&x.contentDocument),r=f&&f.contentDocument.querySelector("#requisitosLegaisBatchGrid tbody tr");if(r)r.click();var b=f&&f.contentDocument.querySelector("[data-open-requisito-popup=\\"situacao\\"]");if(b)b.click();');
    await wait(900);
    check('Popup Situação aberto', await execute('return !!document.querySelector("#greenPortalTransacaoModal")'));
    const buttons = await popup('return {novo:!!doc.querySelector("[data-crud-action=\\"new\\"], [data-new-cadastro]"),salvar:!!doc.querySelector("[data-crud-action=\\"save\\"]"),duplicar:!!doc.querySelector("[data-crud-action=\\"duplicate\\"], [data-duplicate-cadastro]"),excluir:!!doc.querySelector("[data-crud-action=\\"delete\\"], [data-delete-cadastro]")};');
    check('Controles CRUD disponíveis', buttons && buttons.novo && buttons.salvar && buttons.duplicar && buttons.excluir, JSON.stringify(buttons));

    await popup('var b=doc.querySelector("[data-crud-action=\\"new\\"], [data-new-cadastro]");if(b)b.click();return !!b;');
    await wait(300);
    const filled = await popup('var tr=doc.querySelector("tbody tr[data-key^=\\"novo_\\"]"),ta=tr&&tr.querySelector("textarea"),sel=tr&&tr.querySelector("select");if(ta){ta.value=' + JSON.stringify(marker) + ';ta.dispatchEvent(new Event("input",{bubbles:true}));ta.dispatchEvent(new Event("change",{bubbles:true}));}if(sel){sel.value="S";sel.dispatchEvent(new Event("change",{bubbles:true}));}return {row:!!tr,text:ta&&ta.value};');
    check('Novo registro preenchido', filled && filled.row && filled.text === marker, JSON.stringify(filled));
    await popup('var b=doc.querySelector("[data-crud-action=\\"save\\"]");if(b)b.click();return !!b;');
    await confirmPopup();
    let rows = await popup('return Array.from(doc.querySelectorAll("tbody tr")).map(r=>({key:r.dataset.key||"",text:r.querySelector("textarea")?.value||""}));');
    let original = rows && rows.find(row => row.text === marker);
    check('Inclusão persistida', !!original && /^\d+$/.test(original.key), JSON.stringify(rows));

    const edited = marker + ' ALTERADA';
    await popup('var r=Array.from(doc.querySelectorAll("tbody tr")).find(r=>r.dataset.key===' + JSON.stringify(original.key) + '),ta=r&&r.querySelector("textarea");if(ta){ta.value=' + JSON.stringify(edited) + ';ta.dispatchEvent(new Event("input",{bubbles:true}));ta.dispatchEvent(new Event("change",{bubbles:true}));}return !!ta;');
    await popup('var b=doc.querySelector("[data-crud-action=\\"save\\"]");if(b)b.click();return !!b;');
    await confirmPopup();
    rows = await popup('return Array.from(doc.querySelectorAll("tbody tr")).map(r=>({key:r.dataset.key||"",text:r.querySelector("textarea")?.value||""}));');
    check('Edição persistida', rows.some(row => row.key === original.key && row.text === edited), JSON.stringify(rows));

    await popup('var r=Array.from(doc.querySelectorAll("tbody tr")).find(r=>r.dataset.key===' + JSON.stringify(original.key) + ');if(r)r.click();var b=doc.querySelector("[data-crud-action=\\"duplicate\\"], [data-duplicate-cadastro]");if(b)b.click();return !!b;');
    await wait(300);
    await popup('var b=doc.querySelector("[data-crud-action=\\"save\\"]");if(b)b.click();return !!b;');
    await confirmPopup();
    rows = await popup('return Array.from(doc.querySelectorAll("tbody tr")).map(r=>({key:r.dataset.key||"",text:r.querySelector("textarea")?.value||""}));');
    const duplicate = rows && rows.find(row => row.key !== original.key && row.text === edited);
    check('Duplicação persistida', !!duplicate && /^\d+$/.test(duplicate.key), JSON.stringify(rows));

    for (const key of [duplicate.key, original.key]) {
      await popup('var r=Array.from(doc.querySelectorAll("tbody tr")).find(r=>r.dataset.key===' + JSON.stringify(key) + ');if(r)r.click();var b=doc.querySelector("[data-delete-cadastro], [data-crud-action=\\"delete\\"]");if(b)b.click();return !!b;');
      await confirmPopup();
    }
    rows = await popup('return Array.from(doc.querySelectorAll("tbody tr")).map(r=>r.querySelector("textarea")?.value||"");');
    check('Limpeza dos registros de teste', !rows.includes(marker) && !rows.includes(edited), JSON.stringify(rows));
    result.status = 'APROVADO';
  } catch (error) {
    result.error = error.message;
    console.error('ERRO - ' + error.message);
  } finally {
    result.finishedAt = new Date().toISOString();
    fs.mkdirSync('tests/evidencias-requisitos-crown-firefox', { recursive: true });
    fs.writeFileSync('tests/evidencias-requisitos-crown-firefox/crud-controlado.json', JSON.stringify(result, null, 2), 'utf8');
    if (sid) await call('/session/' + sid, 'DELETE').catch(() => {});
  }
  process.exitCode = result.status === 'APROVADO' ? 0 : 1;
})();

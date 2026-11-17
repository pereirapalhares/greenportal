const fs = require('fs');
const path = require('path');
const driver = 'http://127.0.0.1:4444';
const app = (process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const sourceFile = 'E:\\projetoGreen\\GED\\green_atlas\\licencas\\43\\Cadastro Tecnico Federal_IBAMA_Atlas Schindler.pdf';
const evidenceDir = path.resolve('tests/evidencias-e2e-versionamento-licenca-firefox');
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', licenca: '02.01 IBAMA_Cadastro Tecnico Federal_Elevadores Atlas Schindler', checks: [], status: 'REPROVADO' };
const check = (name, ok, detail = '') => { result.checks.push({ name, ok: Boolean(ok), detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name); };
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
async function call(pathname, method = 'GET', body) { const response = await fetch(driver + pathname, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }); const json = await response.json(); if (json.value && json.value.error) throw new Error(json.value.message || json.value.error); return json.value; }

(async () => {
    let sid;
    try {
        fs.mkdirSync(evidenceDir, { recursive: true });
        sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
        const api = suffix => `/session/${sid}${suffix}`;
        const execute = async (script, args = []) => call(api('/execute/sync'), 'POST', { script, args });
        const find = async selector => { const value = await call(api('/element'), 'POST', { using: 'css selector', value: selector }); return value['element-6066-11e4-a52e-4f735466cecf'] || value.ELEMENT; };
        const switchFrame = async id => call(api('/frame'), 'POST', { id: id === null ? null : { 'element-6066-11e4-a52e-4f735466cecf': id } });

        await call(api('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
        await execute("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),p=document.querySelector('#senha'),f=document.querySelector('form.login-form');e.value='ATLAS';u.value='admin';p.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();"); await wait(1800);
        check('Login Atlas realizado', await execute('return location.href.includes("main.php");'));
        await execute(`var t=document.querySelector('[data-sidebar-toggle]');if(t)t.click();var s=document.querySelector('#menuSearch');if(s){s.value='Licencas';s.dispatchEvent(new Event('input',{bubbles:true}));}var b=document.querySelector('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]');if(!b)throw new Error('Menu de licencas nao localizado');b.click();`); await wait(1800);
        const mainFrame = await find('iframe[src*="manutencaolegal_licencas.php"]'); await switchFrame(mainFrame); await wait(2600);
        const target = await execute("var rows=Array.from(document.querySelectorAll('.licencas-grid-fragment .menu-batch-grid tbody tr[data-key]'));var r=rows.find(x=>/02\\.01 IBAMA.*Atlas Schindler/i.test(x.innerText+' '+Array.from(x.querySelectorAll('input,textarea')).map(y=>y.value||'').join(' ')));if(!r)return null;r.click();var b=r.querySelector('[data-ml-file-button]');if(!b)throw new Error('Botao de arquivos nao localizado');b.click();return r.getAttribute('data-key');");
        check('Licenca Atlas localizada', Boolean(target), `CODLICENCA=${target}`);
        await switchFrame(null); await wait(700);
        const gedFrame = await find('[data-popup-transacao-independent="1"].show iframe[src*="gestao_arquivos.php"]'); await switchFrame(gedFrame); await wait(1400);
        check('Popup de arquivos aberto', await execute('return !!document.querySelector("#gedGrid");'));
        const input = await find('#gedInput'); await call(api(`/element/${input}/value`), 'POST', { text: sourceFile }); await execute('document.querySelector("#gedInput").dispatchEvent(new Event("change",{bubbles:true}));'); await wait(2800);
        const upload = await execute('return { message: (document.querySelector("#gedMessage")||{}).textContent||"", file: Array.from(document.querySelectorAll("#gedGrid tbody tr[data-name]")).map(r=>r.getAttribute("data-name")).find(n=>/Cadastro_Tecnico|Cadastro Tecnico/i.test(n||""))||"" };'); result.uploadMessage = upload.message; result.arquivo = upload.file; check('Nova versao criada com PDF real', /Nova vers[aã]o\s+1\.2/i.test(upload.message) && Boolean(upload.file), upload.message);
        const analyze = await execute('return { exists: !!document.querySelector("#gedAnalyze"), enabled: !!document.querySelector("#gedAnalyze")&&!document.querySelector("#gedAnalyze").disabled };'); check('Acao de extracao/IA disponivel', analyze.exists && analyze.enabled);
        await execute('document.querySelector("#gedAnalyze").click();');
        let suggestion = null; let lastMessage = '';
        for (let attempt = 0; attempt < 60; attempt += 1) { await wait(1000); const state = await execute('var m=document.querySelector(".modal.show [data-confirmar]"),msg=(document.querySelector("#gedMessage")||{}).textContent||"";return {modal:!!m,message:msg};'); lastMessage = state.message; if (state.modal) { suggestion = await execute('var m=document.querySelector(".modal.show");return {text:m.innerText||"",fields:Array.from(m.querySelectorAll("[data-ia-field]")).map(x=>({key:x.getAttribute("data-ia-field"),value:x.value||""}))};'); break; } if (/nao foi|não foi|erro|falha/i.test(state.message)) break; }
        result.suggestion = suggestion; check('Extracao/analise gerou sugestao para aprovacao', Boolean(suggestion), lastMessage);
        check('Sugestao possui campos extraidos', suggestion.fields.some(x => x.value));
        await execute('var b=document.querySelector(".modal.show [data-confirmar]");if(!b)throw new Error("Botao de confirmacao nao localizado");b.click();'); await wait(1800);
        const confirmation = await execute('return (document.querySelector("#gedMessage")||{}).textContent||"";'); result.confirmationMessage = confirmation; check('Confirmacao da extracao concluida', /confirmados e gravados/i.test(confirmation));
        await wait(900);
        const history = await execute('var rows=Array.from(document.querySelectorAll("#gedVersions tbody tr"));return {rows:rows.length,text:document.querySelector("#gedVersions")?.innerText||""};'); result.history = history; check('Historico atualizado apos confirmacao', history.rows >= 2); check('Versao confirmada ficou ativa e concluida', /1\.2[\s\S]*ATIVA[\s\S]*CONCLUIDA/i.test(history.text) || /ATIVA[\s\S]*CONCLUIDA/i.test(history.text), history.text);
        await switchFrame(null); await call(api('/screenshot')).then(image => fs.writeFileSync(path.join(evidenceDir, '02-versionamento-extracao-confirmacao.png'), Buffer.from(image, 'base64'))); result.screenshot = path.join(evidenceDir, '02-versionamento-extracao-confirmacao.png'); result.codlicencaBase = target; result.status = 'APROVADO';
    } catch (error) { result.error = error.message; console.log(`ERRO - ${error.message}`); try { if (sid) { await call(`/session/${sid}/frame`, 'POST', { id: null }); await call(`/session/${sid}/screenshot`).then(image => fs.writeFileSync(path.join(evidenceDir, 'falha-extracao.png'), Buffer.from(image, 'base64'))); } } catch (_) {} }
    finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync('tests/resultado-e2e-versionamento-extracao-firefox.json', JSON.stringify(result, null, 2), 'utf8'); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
    process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

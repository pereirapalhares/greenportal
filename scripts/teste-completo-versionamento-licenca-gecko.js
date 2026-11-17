const fs = require('fs');
const path = require('path');

const driver = 'http://127.0.0.1:4444';
const app = (process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const sourceFile = 'E:\\projetoGreen\\GED\\green_atlas\\licencas\\43\\Cadastro Tecnico Federal_IBAMA_Atlas Schindler.pdf';
const evidenceDir = path.resolve('tests/evidencias-e2e-versionamento-licenca-firefox');
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', checks: [], status: 'REPROVADO' };
const check = (name, ok, detail = '') => { result.checks.push({ name, ok: Boolean(ok), detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name); };
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
async function call(pathname, method = 'GET', body) {
    const response = await fetch(driver + pathname, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
    const json = await response.json();
    if (json.value && json.value.error) throw new Error(json.value.message || json.value.error);
    return json.value;
}

(async () => {
    let sid;
    try {
        fs.mkdirSync(evidenceDir, { recursive: true });
        check('PDF real do Atlas localizado', fs.existsSync(sourceFile), sourceFile);
        sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
        const api = suffix => `/session/${sid}${suffix}`;
        const execute = async (script, args = []) => call(api('/execute/sync'), 'POST', { script, args });
        const find = async selector => { const value = await call(api('/element'), 'POST', { using: 'css selector', value: selector }); return value['element-6066-11e4-a52e-4f735466cecf'] || value.ELEMENT; };
        const switchFrame = async id => call(api('/frame'), 'POST', { id: id === null ? null : { 'element-6066-11e4-a52e-4f735466cecf': id } });

        await call(api('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
        await execute("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),p=document.querySelector('#senha'),f=document.querySelector('form.login-form');e.value='ATLAS';u.value='admin';p.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();"); await wait(1800);
        check('Login Atlas realizado', await execute('return location.href.includes("main.php");'));
        await execute("var t=document.querySelector('[data-sidebar-toggle]');if(t)t.click();var s=document.querySelector('#menuSearch');if(s){s.value='Licencas';s.dispatchEvent(new Event('input',{bubbles:true}));}var b=document.querySelector(\"button[data-open-tab][data-tab-url*='manutencaolegal_licencas.php']\");if(!b)throw new Error('Menu de licencas nao localizado');b.click();"); await wait(1900);
        const mainFrame = await find('iframe[src*="manutencaolegal_licencas.php"]'); await switchFrame(mainFrame); await wait(2300);
        const target = await execute("var rows=Array.from(document.querySelectorAll('.licencas-grid-fragment .menu-batch-grid tbody tr[data-key]'));var r=rows.find(x=>/02\\.01 IBAMA.*Atlas Schindler/i.test(x.innerText+' '+Array.from(x.querySelectorAll('input,textarea')).map(y=>y.value||'').join(' ')));if(!r)return null;r.click();var b=r.querySelector('[data-ml-file-button]');if(!b)throw new Error('Botao de arquivos nao localizado');b.click();return r.getAttribute('data-key');");
        check('Licenca Atlas localizada', Boolean(target), `CODLICENCA=${target}`); result.codlicencaBase = target;
        await switchFrame(null); await wait(700);
        const gedFrame = await find('[data-popup-transacao-independent="1"].show iframe[src*="gestao_arquivos.php"]'); await switchFrame(gedFrame); await wait(1200);
        check('Popup de arquivos aberto', await execute('return !!document.querySelector("#gedGrid");'));
        const before = await execute('return {history:Array.from(document.querySelectorAll("#gedVersions tbody tr")).map(r=>Array.from(r.cells).map(c=>c.innerText.trim())),files:Array.from(document.querySelectorAll("#gedGrid tbody tr[data-name]")).map(r=>r.getAttribute("data-name"))};');
        result.historicoAntes = before.history;
        const input = await find('#gedInput'); await call(api(`/element/${input}/value`), 'POST', { text: sourceFile }); await execute('document.querySelector("#gedInput").dispatchEvent(new Event("change",{bubbles:true}));'); await wait(3000);
        let uploaded = null;
        for (let attempt = 0; attempt < 20 && !uploaded; attempt += 1) { uploaded = await execute('var before=arguments[0]||[];return Array.from(document.querySelectorAll("#gedGrid tbody tr[data-name]")).map(r=>r.getAttribute("data-name")).find(n=>/^upload_.*Cadastro_Tecnico_Federal_IBAMA_Atlas_Schindler/i.test(n||"")&&!before.includes(n))||null;', [before.files]); if (!uploaded) await wait(500); }
        const uploadMessage = await execute('return (document.querySelector("#gedMessage")||{}).textContent||"";'); result.uploadMessage = uploadMessage; result.arquivo = uploaded;
        check('Nova versao criada com PDF real', Boolean(uploaded), uploadMessage);
        const analyze = await execute('return {exists:!!document.querySelector("#gedAnalyze"),enabled:!!document.querySelector("#gedAnalyze")&&!document.querySelector("#gedAnalyze").disabled};');
        check('Acao de extracao/IA habilitada apos upload', analyze.exists && analyze.enabled, JSON.stringify(analyze));
        await execute('document.querySelector("#gedAnalyze").click();');
        let suggestion = null; let lastMessage = '';
        for (let attempt = 0; attempt < 120; attempt += 1) { await wait(1000); const state = await execute('var m=document.querySelector(".modal.show [data-confirmar]"),msg=(document.querySelector("#gedMessage")||{}).textContent||"";return {modal:!!m,message:msg};'); lastMessage = state.message; if (state.modal) { suggestion = await execute('var m=document.querySelector(".modal.show");return {text:m.innerText||"",fields:Array.from(m.querySelectorAll("[data-ia-field]")).map(x=>({key:x.getAttribute("data-ia-field"),value:x.value||""}))};'); break; } if (/nao foi|nÃ£o foi|erro|falha/i.test(state.message)) break; }
        result.suggestion = suggestion; check('Extracao/analise gerou sugestao para aprovacao', Boolean(suggestion), lastMessage); check('Sugestao possui dados extraidos', suggestion.fields.some(x => x.value));
        const confidenceText = suggestion.text.match(/Confianca:\s*([0-9]+(?:[.,][0-9]+)?)%/i); result.confiancaExibida = confidenceText ? confidenceText[1] : null; check('Confianca exibida como percentual', Boolean(confidenceText) && Number((confidenceText[1] || '').replace(',', '.')) >= 70, suggestion.text);
        await execute('var b=document.querySelector(".modal.show [data-confirmar]");if(!b)throw new Error("Botao de confirmacao nao localizado");b.click();'); await wait(2200);
        const confirmation = await execute('return (document.querySelector("#gedMessage")||{}).textContent||"";'); result.confirmationMessage = confirmation; check('Confirmacao gravada pela tela', /confirmados e gravados/i.test(confirmation));
        await wait(900);
        const history = await execute('return Array.from(document.querySelectorAll("#gedVersions tbody tr")).map(r=>Array.from(r.cells).map(c=>c.innerText.trim()));'); result.historicoDepois = history;
        const current = history.find(row => row.includes(uploaded)) || [];
        result.versao = current[0] || null; result.statusVersao = current[1] || null; result.statusExtracao = current[3] || null; result.confiancaGravada = current[4] || null;
        check('Nova versao aparece no historico', current.length >= 6, JSON.stringify(current)); check('Nova versao ficou ativa e concluida', /ATIVA/i.test(current[1] || '') && /CONCLUIDA/i.test(current[3] || ''), JSON.stringify(current)); check('Confianca gravada como percentual', Number(String(current[4] || '').replace(',', '.')) >= 70, JSON.stringify(current));
        await switchFrame(null); await call(api('/screenshot')).then(image => fs.writeFileSync(path.join(evidenceDir, '04-teste-completo-versionamento.png'), Buffer.from(image, 'base64'))); result.screenshot = path.join(evidenceDir, '04-teste-completo-versionamento.png'); result.status = 'APROVADO';
    } catch (error) { result.error = error.message; console.log(`ERRO - ${error.message}`); try { if (sid) { await call(`/session/${sid}/frame`, 'POST', { id: null }); await call(`/session/${sid}/screenshot`).then(image => fs.writeFileSync(path.join(evidenceDir, '04-falha-teste-completo.png'), Buffer.from(image, 'base64'))); } } catch (_) {} }
    finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync('tests/resultado-teste-completo-versionamento-firefox.json', JSON.stringify(result, null, 2), 'utf8'); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
    process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

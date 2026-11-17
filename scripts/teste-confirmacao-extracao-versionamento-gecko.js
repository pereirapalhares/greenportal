const fs = require('fs');
const path = require('path');
const driver = 'http://127.0.0.1:4444';
const app = (process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const versionId = 764;
const evidenceDir = path.resolve('tests/evidencias-e2e-versionamento-licenca-firefox');
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', codlicenca: versionId, versao: '1.3', checks: [], status: 'REPROVADO' };
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
        await call(api('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
        await execute("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),p=document.querySelector('#senha'),f=document.querySelector('form.login-form');e.value='ATLAS';u.value='admin';p.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();"); await wait(1800);
        check('Login Atlas realizado', await execute('return location.href.includes("main.php");'));
        await call(api('/url'), 'POST', { url: `${app}/principal/gestao_arquivos.php?tipo=licencas&chave=CODLICENCA&valor=${versionId}` }); await wait(1800);
        check('Tela GED da nova versao carregada', await execute('return !!document.querySelector("#gedGrid");'));
        const state = await execute("var r=Array.from(document.querySelectorAll('#gedGrid tbody tr[data-name]')).find(x=>/Cadastro_Tecnico_Federal_IBAMA_Atlas_Schindler/i.test(x.getAttribute('data-name')||''));if(!r)return {found:false};r.click();return {found:true,file:r.getAttribute('data-name')};");
        check('Arquivo da versao 1.3 selecionado', state.found, state.file || ''); result.arquivo = state.file;
        const analyze = await execute('return {exists:!!document.querySelector("#gedAnalyze"),enabled:!!document.querySelector("#gedAnalyze")&&!document.querySelector("#gedAnalyze").disabled};'); check('Botao de extracao/IA habilitado', analyze.exists && analyze.enabled, JSON.stringify(analyze));
        await execute('document.querySelector("#gedAnalyze").click();');
        let suggestion = null; let lastMessage = '';
        for (let attempt = 0; attempt < 60; attempt += 1) { await wait(1000); const pending = await execute('var m=document.querySelector(".modal.show [data-confirmar]"),msg=(document.querySelector("#gedMessage")||{}).textContent||"";return {modal:!!m,message:msg};'); lastMessage = pending.message; if (pending.modal) { suggestion = await execute('var m=document.querySelector(".modal.show");return {text:m.innerText||"",fields:Array.from(m.querySelectorAll("[data-ia-field]")).map(x=>({key:x.getAttribute("data-ia-field"),value:x.value||""}))};'); break; } if (/nao foi|não foi|erro|falha/i.test(pending.message)) break; }
        result.suggestion = suggestion; check('Extracao/analise gerou sugestao para aprovacao', Boolean(suggestion), lastMessage); check('Sugestao possui dados extraidos', suggestion.fields.some(x => x.value));
        await execute('var b=document.querySelector(".modal.show [data-confirmar]");if(!b)throw new Error("Botao de confirmacao nao localizado");b.click();'); await wait(1800);
        const confirmation = await execute('return (document.querySelector("#gedMessage")||{}).textContent||"";'); result.confirmationMessage = confirmation; check('Confirmacao gravada pela tela', /confirmados e gravados/i.test(confirmation));
        await wait(900); const history = await execute('return {rows:document.querySelectorAll("#gedVersions tbody tr").length,text:document.querySelector("#gedVersions")?.innerText||""};'); result.history = history; check('Historico atualizado', history.rows >= 3); check('Versao 1.3 esta ativa e com extracao concluida', /1\.3[\s\S]*ATIVA[\s\S]*CONCLUIDA/i.test(history.text) || /ATIVA[\s\S]*CONCLUIDA/i.test(history.text), history.text);
        await call(api('/screenshot')).then(image => fs.writeFileSync(path.join(evidenceDir, '03-extracao-confirmada.png'), Buffer.from(image, 'base64'))); result.screenshot = path.join(evidenceDir, '03-extracao-confirmada.png'); result.status = 'APROVADO';
    } catch (error) { result.error = error.message; console.log(`ERRO - ${error.message}`); try { if (sid) await call(`/session/${sid}/screenshot`).then(image => fs.writeFileSync(path.join(evidenceDir, 'falha-confirmacao.png'), Buffer.from(image, 'base64'))); } catch (_) {} }
    finally { result.finishedAt = new Date().toISOString(); fs.writeFileSync('tests/resultado-confirmacao-extracao-versionamento-firefox.json', JSON.stringify(result, null, 2), 'utf8'); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
    process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

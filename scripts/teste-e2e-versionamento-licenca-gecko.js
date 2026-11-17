const fs = require('fs');
const path = require('path');

const driver = 'http://127.0.0.1:4444';
const app = (process.env.GREENPORTAL_URL || 'http://localhost/greenportal').replace(/\/$/, '');
const evidenceDir = path.resolve('tests/evidencias-e2e-versionamento-licenca-firefox');
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', licenca: '02.01 IBAMA_Cadastro Tecnico Federal_Elevadores Atlas Schindler', checks: [], status: 'REPROVADO' };
const check = (name, ok, detail = '') => { result.checks.push({ name, ok: Boolean(ok), detail }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`); if (!ok) throw new Error(name); };
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

async function call(pathname, method = 'GET', body) {
    const response = await fetch(driver + pathname, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
    const json = await response.json();
    if (json.value && json.value.error) throw new Error(json.value.message || json.value.error);
    return json.value;
}

(async () => {
    let sid; let current; const tempPdf = path.join(process.env.TEMP || 'C:\\Windows\\Temp', `greenportal-e2e-versionamento-${Date.now()}.pdf`);
    try {
        fs.mkdirSync(evidenceDir, { recursive: true });
        fs.writeFileSync(tempPdf, '%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n', 'binary');
        sid = (await call('/session', 'POST', { capabilities: { alwaysMatch: { browserName: 'firefox', 'moz:firefoxOptions': { binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe', args: ['-headless'] } } } })).sessionId;
        const api = suffix => `/session/${sid}${suffix}`;
        const execute = async (script, args = []) => call(api('/execute/sync'), 'POST', { script, args });
        const find = async selector => { const value = await call(api('/element'), 'POST', { using: 'css selector', value: selector }); return value['element-6066-11e4-a52e-4f735466cecf'] || value.ELEMENT; };
        const switchFrame = async id => call(api('/frame'), 'POST', { id: id === null ? null : { 'element-6066-11e4-a52e-4f735466cecf': id } });

        await call(api('/url'), 'POST', { url: `${app}/index.php` }); await wait(900);
        await execute("var e=document.querySelector('#empresa'),u=document.querySelector('#userid'),p=document.querySelector('#senha'),f=document.querySelector('form.login-form');e.value='ATLAS';u.value='admin';p.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();"); await wait(1800);
        check('Login Atlas realizado', await execute('return location.href.includes("main.php");'));
        await execute(`var t=document.querySelector('[data-sidebar-toggle]');if(t)t.click();var s=document.querySelector('#menuSearch');if(s){s.value='Licencas';s.dispatchEvent(new Event('input',{bubbles:true}));}var b=document.querySelector('button[data-open-tab][data-tab-url*="manutencaolegal_licencas.php"]');if(!b)throw new Error('Menu de licencas nao localizado');b.click();`); await wait(1800);
        const mainFrame = await find('iframe[src*="manutencaolegal_licencas.php"]'); await switchFrame(mainFrame); current = 'licencas'; await wait(2600);
        const target = await execute("var rows=Array.from(document.querySelectorAll('.licencas-grid-fragment .menu-batch-grid tbody tr[data-key]'));var r=rows.find(x=>/02\\.01 IBAMA.*Atlas Schindler/i.test(x.innerText+' '+Array.from(x.querySelectorAll('input,textarea')).map(y=>y.value||'').join(' ')));if(!r)return null;r.click();var b=r.querySelector('[data-ml-file-button]');if(!b)throw new Error('Botao de arquivos nao localizado');b.click();return r.getAttribute('data-key');");
        check('Licenca Atlas localizada', Boolean(target), `CODLICENCA=${target}`);
        await switchFrame(null); current = 'top'; await wait(700);
        const gedFrame = await find('[data-popup-transacao-independent="1"].show iframe[src*="gestao_arquivos.php"]'); await switchFrame(gedFrame); current = 'ged'; await wait(1400);
        check('Popup de arquivos aberto', await execute('return !!document.querySelector("#gedGrid");'));
        const before = await execute('return { rows: document.querySelectorAll("#gedVersions tbody tr").length, existing: Array.from(document.querySelectorAll("#gedGrid tbody tr[data-name]")).find(r=>/e2e-versionamento/i.test(r.getAttribute("data-name")||""))?.getAttribute("data-name")||"" };');
        let fileName = before.existing;
        if (!fileName) {
            const input = await find('#gedInput'); await call(api(`/element/${input}/value`), 'POST', { text: tempPdf }); await execute('document.querySelector("#gedInput").dispatchEvent(new Event("change",{bubbles:true}));'); await wait(2600);
            const state = await execute('return { name: Array.from(document.querySelectorAll("#gedGrid tbody tr[data-name]")).map(r=>r.getAttribute("data-name")).find(n=>/e2e-versionamento/i.test(n||""))||"", message: (document.querySelector("#gedMessage")||{}).textContent||"" };'); fileName = state.name; result.uploadMessage = state.message; check('Upload de nova versao concluido', Boolean(fileName), state.message);
        } else result.reusedExistingVersion = true;
        const preview = await execute('return { active: document.querySelector("#gedPreviewPane")?.classList.contains("active"), src: document.querySelector("#gedPreviewFrame")?.getAttribute("src")||"", label: document.querySelector("#gedPreviewTab")?.textContent||"" };');
        check('Aba Visualizacao do arquivo ativa', preview.active && /Visualiza.{0,3}o do arquivo/i.test(preview.label), preview.label);
        check('PDF carregado no visualizador', /action=download/i.test(preview.src), preview.src);
        const clicked = await execute("var n=arguments[0],r=Array.from(document.querySelectorAll('#gedGrid tbody tr[data-name]')).find(x=>x.getAttribute('data-name')===n);if(!r) return false;var a=r.querySelector('a');if(!a)return false;a.click();return true;", [fileName]);
        check('Link do arquivo abre a visualizacao sem download', clicked && await execute('return document.querySelector("#gedPreviewPane")?.classList.contains("active")===true;'));
        const history = await execute('return { text: document.querySelector("#gedVersions")?.innerText||"", rows: document.querySelectorAll("#gedVersions tbody tr").length };');
        check('Historico de versoes exibido', history.rows >= before.rows);
        check('Arquivo e versao aparecem no historico', history.text.includes(fileName) || /e2e-versionamento/i.test(history.text), `${history.rows} registro(s)`);
        await switchFrame(null); current = 'top'; await call(api('/screenshot')).then(image => fs.writeFileSync(path.join(evidenceDir, '01-versionamento-e2e.png'), Buffer.from(image, 'base64')));
        result.codlicenca = target; result.arquivo = fileName; result.screenshot = path.join(evidenceDir, '01-versionamento-e2e.png'); result.status = 'APROVADO';
    } catch (error) { result.error = error.message; console.log(`ERRO - ${error.message}`); try { if (sid) { await switchFrame(null); await call(`/session/${sid}/screenshot`).then(image => fs.writeFileSync(path.join(evidenceDir, 'falha.png'), Buffer.from(image, 'base64'))); } } catch (_) {} }
    finally { try { fs.unlinkSync(tempPdf); } catch (_) {} result.finishedAt = new Date().toISOString(); fs.writeFileSync('tests/resultado-e2e-versionamento-licenca-firefox.json', JSON.stringify(result, null, 2), 'utf8'); if (sid) await call(`/session/${sid}`, 'DELETE').catch(() => {}); }
    process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

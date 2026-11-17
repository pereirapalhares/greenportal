const fs = require('fs');

const driver = 'http://127.0.0.1:4444';
const app = process.env.GREENPORTAL_URL || 'http://localhost/greenportal';
const evidenceDir = 'tests/evidencias-licenca-versao-firefox';
const result = { navegador: 'Firefox via GeckoDriver', empresa: 'Atlas', licenca: '02.01 IBAMA_Cadastro Técnico Federal_Elevadores Atlas Schindler', checks: [], screenshots: [], status: 'REPROVADO' };

const check = (name, ok, detail = '') => {
  result.checks.push({ name, ok: Boolean(ok), detail });
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`);
  if (!ok) throw new Error(name + (detail ? `: ${detail}` : ''));
};

async function call(path, method = 'GET', body) {
  const response = await fetch(driver + path, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const json = await response.json();
  if (json.value && json.value.error) throw new Error(json.value.message || json.value.error);
  return json.value;
}

const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
const saveShot = async (api, name) => {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const image = await call(api('/screenshot'));
  const file = `${evidenceDir}/${name}.png`;
  fs.writeFileSync(file, Buffer.from(image, 'base64'));
  result.screenshots.push(file);
  console.log(`EVIDÊNCIA - ${file}`);
};

(async () => {
  let session;
  try {
    session = (await call('/session', 'POST', {
      capabilities: {
        alwaysMatch: {
          browserName: 'firefox',
          'moz:firefoxOptions': {
            binary: 'C:\\Program Files\\Mozilla Firefox\\firefox.exe',
            args: ['-headless'],
          },
        },
      },
    })).sessionId;
    const api = path => `/session/${session}${path}`;

    await call(api('/url'), 'POST', { url: `${app}/index.php` });
    await wait(900);
    await call(api('/execute/sync'), 'POST', {
      script: "const e=document.querySelector('#empresa'),u=document.querySelector('#userid'),p=document.querySelector('#senha'),f=document.querySelector('form.login-form');if(!e||!u||!p||!f)throw new Error('Tela de login não carregada');e.value='ATLAS';u.value='admin';p.value='"+(process.env.GREEN_TEST_PASSWORD || "")+"';f.submit();",
      args: [],
    });
    await wait(1800);
    check('Login Atlas realizado', await call(api('/execute/sync'), 'POST', { script: 'return location.href.includes("main.php");', args: [] }));

    await call(api('/url'), 'POST', { url: `${app}/principal/manutencaolegal_licencas.php` });
    await wait(2500);
    const main = await call(api('/execute/sync'), 'POST', {
      script: "const rows=Array.from(document.querySelectorAll('tbody tr'));const row=rows.find(r=>{const t=r.querySelector('textarea[name*=DSCLICENCA]');return t&&t.value==='02.01 IBAMA_Cadastro Técnico Federal_Elevadores Atlas Schindler';});return {found:!!row,key:row?row.getAttribute('data-key'):'',arquivos:!!document.querySelector('[data-column=ARQUIVOS]'),grid:!!document.querySelector('.licencas-grid-fragment .menu-batch-grid')};",
      args: [],
    });
    check('Grid geral de licenças carregado', main.grid);
    check('Licença alvo localizada', main.found, `CODLICENCA=${main.key}`);
    check('Coluna Arquivos disponível na manutenção geral', main.arquivos);
    await saveShot(api, '01-manutencao-licencas');

    await call(api('/url'), 'POST', { url: `${app}/principal/gestao_arquivos.php?tipo=licencas&chave=CODLICENCA&valor=${encodeURIComponent(main.key)}` });
    await wait(1800);
    const ged = await call(api('/execute/sync'), 'POST', {
      script: "const text=document.body.innerText||'';const history=Array.from(document.querySelectorAll('table')).find(t=>/Vers[aã]o\\s+Status/i.test(t.innerText||''));const versions=history?Array.from(history.querySelectorAll('tbody tr')).map(r=>r.innerText):[];return {title:document.title,hasTarget:/Arquivos da licen[cç]a/i.test(text),hasUpload:!!document.querySelector('#gedUpload'),hasHistory:/Hist[oó]rico de vers[oõ]es/i.test(text),versionRows:versions.length,active:text.includes('1.0')&&text.includes('ATIVA'),legacyFiles:Array.from(document.querySelectorAll('#gedGrid tbody tr[data-name]')).length};",
      args: [],
    });
    check('Popup GED da licença aberto', ged.hasTarget, ged.title);
    check('Upload de nova versão disponível', ged.hasUpload);
    check('Histórico de versões visível', ged.hasHistory && ged.versionRows >= 1, `${ged.versionRows} registro(s)`);
    check('Versão legada exibida como ativa', ged.active);
    check('Arquivos legados preservados no diretório', ged.legacyFiles > 0, `${ged.legacyFiles} arquivo(s)`);
    await saveShot(api, '02-popup-arquivos-historico');

    result.codlicenca = main.key;
    result.version = '1.0';
    result.finishedAt = new Date().toISOString();
    result.status = 'APROVADO';
  } catch (error) {
    result.error = error.message;
    result.finishedAt = new Date().toISOString();
    console.log(`ERRO - ${error.message}`);
  } finally {
    fs.mkdirSync('tests', { recursive: true });
    fs.writeFileSync('tests/resultado-visual-firefox-licenca-versao.json', JSON.stringify(result, null, 2), 'utf8');
    if (session) await call(`/session/${session}`, 'DELETE').catch(() => {});
  }
  process.exit(result.status === 'APROVADO' ? 0 : 1);
})();

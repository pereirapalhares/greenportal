const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 ? (args[i + 1] || fallback) : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const empresa = arg('--empresa', 'Modelo');
const usuario = arg('--usuario', 'admin');
const senha = arg('--senha', process.env.GREEN_TEST_PASSWORD || '');
const timeout = Number(arg('--timeout', '30000'));
let cookie = '';

function check(name, ok, detail = '') {
  console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${detail ? `: ${detail}` : ''}`);
  if (!ok) throw new Error(name + (detail ? `: ${detail}` : ''));
}

function updateCookie(headers) {
  const values = typeof headers.getSetCookie === 'function' ? headers.getSetCookie() : (headers.get('set-cookie') || '').split(/,(?=[^;]+?=)/);
  const jar = new Map(cookie.split(/;\s*/).filter(Boolean).map(part => part.split('=', 2)));
  values.filter(Boolean).forEach(value => {
    const part = value.split(';')[0].trim();
    const separator = part.indexOf('=');
    if (separator > 0) jar.set(part.slice(0, separator), part.slice(separator + 1));
  });
  cookie = Array.from(jar, ([name, value]) => `${name}=${value}`).join('; ');
}

async function request(url, options = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  try {
    const headers = { ...(options.headers || {}) };
    if (cookie) headers.Cookie = cookie;
    const response = await fetch(url, { ...options, headers, redirect: 'manual', signal: controller.signal });
    updateCookie(response.headers);
    const bytes = new Uint8Array(await response.arrayBuffer());
    return { response, text: new TextDecoder('utf-8').decode(bytes), bytes: bytes.length };
  } finally { clearTimeout(timer); }
}

async function main() {
  const loginPage = await request(`${baseUrl}/index.php`);
  check('Página de login disponível', loginPage.response.status === 200, `HTTP ${loginPage.response.status}`);
  const form = new URLSearchParams({ empresa, userid: usuario, senha, idioma: 'pt' });
  const login = await request(`${baseUrl}/credenciais/logar.php`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() });
  check('Login realizado', login.response.status === 302 && /main\.php/.test(login.response.headers.get('location') || ''), `HTTP ${login.response.status}`);
  const mainPage = await request(`${baseUrl}/principal/main.php`);
  check('Sessão autenticada', mainPage.response.status === 200 && !/sessão expirada|sessao_expirada|Acesso negado/i.test(mainPage.text));

  const started = Date.now();
  const gridPage = await request(`${baseUrl}/principal/manutencaolegal_licencas.php`);
  const elapsed = Date.now() - started;
  const html = gridPage.text;
  const rows = (html.match(/<tr\s+data-row\s+data-key=/g) || []).length;
  const columns = (html.match(/<th\s+data-column=/g) || []).length;
  check('Página de Licenças retornou HTTP 200', gridPage.response.status === 200, `HTTP ${gridPage.response.status}`);
  check('Grid de Licenças presente', html.includes('id="manutencaolegal_licencasBatchGrid"'));
  check('Fragmento de Licenças presente', html.includes('licencas-grid-fragment'));
  check('Registros do grid retornados', rows > 0, `${rows} linhas`);
  check('Colunas do grid retornadas', columns >= 15, `${columns} colunas`);
  check('Responsável sem combo pesado', !/<select\b[^>]*data-column-filter=["']CODUSUARIO["']/i.test(html));
  check('Unidade com filtro textual', /data-column-filter="DSCUNIDADE"[^>]*>[\s\S]*?<input/i.test(html));
  check('Lista de distribuição com filtro textual', /data-column-filter="CODLISTA"[^>]*>[\s\S]*?<input/i.test(html));
  check('Sem erro PHP no retorno', !/Fatal error|Uncaught|PDOException|SQLSTATE\[/i.test(html));
  check('Tempo de resposta dentro do limite', elapsed < 15000, `${elapsed} ms`);
  const countStarted = Date.now();
  const countRequest = await request(`${baseUrl}/principal/manutencaolegal_licencas.php`, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'count_rows', filtros: '{}', busca: '', ajax: '1' }).toString()
  });
  const countElapsed = Date.now() - countStarted;
  let countData = {};
  try { countData = JSON.parse(countRequest.text); } catch (_) {}
  check('AJAX de contagem retornou HTTP 200', countRequest.response.status === 200, `HTTP ${countRequest.response.status}`);
  check('AJAX de contagem retornou JSON válido', countData.ok === true, countRequest.text.slice(0, 180));
  check('AJAX de contagem retornou total', Number.isInteger(Number(countData.total)) && Number(countData.total) > 0, String(countData.total));
  check('AJAX de contagem dentro do limite', countElapsed < 15000, `${countElapsed} ms`);
  console.log(`INFO - Tempo de resposta da página de Licenças: ${elapsed} ms`);
  console.log(`INFO - Tempo de resposta da contagem AJAX: ${countElapsed} ms`);
  console.log(`INFO - Tamanho da resposta: ${gridPage.bytes} bytes`);
  console.log(`INFO - Linhas: ${rows}; colunas: ${columns}`);
}

main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error}`); process.exitCode = 1; });

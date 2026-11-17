const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');

const args = process.argv.slice(2);
const arg = (name, fallback = '') => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const baseUrl = arg('--url', 'http://localhost/greenportal').replace(/\/$/, '');
const output = path.resolve(arg('--saida', 'tests/resultado-sem-navegador-backlog-01-login.json'));
const result = { startedAt: new Date().toISOString(), item: 1, tipo: 'HTTP/HTML sem navegador', checks: [], status: 'REPROVADO' };
function check(name, ok, details = '') { result.checks.push({ name, ok: Boolean(ok), details: String(details) }); console.log(`${ok ? 'PASS' : 'FAIL'} - ${name}${details ? `: ${details}` : ''}`); if (!ok) throw new Error(name); }
function get(url) { return new Promise((resolve, reject) => { const client = url.startsWith('https:') ? https : http; const request = client.get(url, { headers: { 'User-Agent': 'GreenPortal-Backlog-Test/1.0' } }, response => { let body = ''; response.setEncoding('utf8'); response.on('data', chunk => { body += chunk; }); response.on('end', () => resolve({ status: response.statusCode || 0, headers: response.headers, body })); }); request.setTimeout(30000, () => request.destroy(new Error('Timeout HTTP'))); request.on('error', reject); }); }
async function main() {
  try {
    const response = await get(`${baseUrl}/index.php`);
    check('Tela de entrada responde por HTTP', response.status >= 200 && response.status < 400, `HTTP ${response.status}`);
    check('HTML declara viewport responsivo', /<meta[^>]+name=["']viewport["'][^>]+content=["'][^"']*width\s*=\s*device-width/i.test(response.body), 'meta viewport');
    check('Formulario de Login existe', /<form[^>]+class=["'][^"']*login-form/i.test(response.body), 'form.login-form');
    check('Botão Login existe e é submit', /<button[^>]+type=["']submit["'][^>]*>/i.test(response.body), 'button[type=submit]');
    check('Campos de empresa, usuário e senha existem', /id=["']empresa["']/i.test(response.body) && /id=["']userid["']/i.test(response.body) && /id=["']senha["']/i.test(response.body), 'campos de autenticação');
    result.observacao = 'A posição visual do botão em resoluções específicas requer navegador e não é coberta por este teste HTTP.';
    result.status = 'APROVADO'; result.finishedAt = new Date().toISOString();
  } catch (error) { result.error = error.message; result.finishedAt = new Date().toISOString(); throw error; }
  finally { fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(result, null, 2), 'utf8'); }
}
main().catch(error => { console.error(`ERRO FATAL: ${error.stack || error.message}`); process.exit(1); });

<?php
declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';

function greenPortalBaseUrl(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/credenciais/logar.php'));
    $base = dirname(dirname($script));
    $base = str_replace('\\', '/', $base);
    return $base === '/' || $base === '.' ? '' : rtrim($base, '/');
}

$greenPortalBaseUrl = greenPortalBaseUrl();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $greenPortalBaseUrl . '/index.php');
    exit;
}

function greenPortalEnv(): array
{
    $values = [];
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if (!array_key_exists($key, $values)) {
            $values[$key] = $value;
        }
    }

    return $values;
}

function greenPortalRedirectError(string $code): never
{
    global $greenPortalBaseUrl;
    // Migração 343: o link do e-mail de alerta continua valendo depois de um erro de login
    $alerta = max(0, (int)($_POST['abrir_alerta'] ?? 0));
    $empresa = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($_POST['empresa'] ?? '')) ?? '';
    header('Location: ' . $greenPortalBaseUrl . '/index.php?erro=' . rawurlencode($code) . ($alerta > 0 ? '&abrir_alerta=' . $alerta . '&empresa=' . rawurlencode($empresa) : ''));
    exit;
}

$inputEmpresa = trim((string)($_POST['empresa'] ?? ''));
$userid = trim((string)($_POST['userid'] ?? ''));
$senha = (string)($_POST['senha'] ?? '');
$idioma = strtolower(trim((string)($_POST['idioma'] ?? 'pt')));

if ($inputEmpresa === '' || $userid === '' || $senha === '') {
    greenPortalRedirectError('dados_obrigatorios');
}

if (!in_array($idioma, ['pt', 'en', 'es'], true)) {
    $idioma = 'pt';
}
// O navegador lembra o idioma escolhido no login (pré-seleciona a próxima entrada).
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
IdiomaService::lembrarIdioma($idioma);

$config = greenPortalEnv();
$centralHost = $config['GUNIVERSAL_DB_HOST'] ?? $config['GPT_DB_HOST'] ?? '127.0.0.1';
$centralPort = $config['GUNIVERSAL_DB_PORT'] ?? $config['GPT_DB_PORT'] ?? '3306';
$centralDatabase = $config['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp';
$centralUsername = $config['GUNIVERSAL_DB_USERNAME'] ?? $config['GPT_DB_USERNAME'] ?? '';
$centralPassword = $config['GUNIVERSAL_DB_PASSWORD'] ?? $config['GPT_DB_PASSWORD'] ?? '';
$tenantHost = $config['GPT_DB_HOST'] ?? '127.0.0.1';
$tenantPort = $config['GPT_DB_PORT'] ?? '3306';
$tenantUsername = $config['GPT_DB_USERNAME'] ?? '';
$tenantPassword = $config['GPT_DB_PASSWORD'] ?? '';

try {
    $central = GreenPortalDatabase::connect($config, $centralDatabase, 'GUNIVERSAL');
    $empresas = GreenPortalDatabase::call($central, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_empresas_login_migradas', 'filtros' => json_encode([], JSON_UNESCAPED_UNICODE)]);
    $empresa = null;
    foreach ($empresas as $candidate) {
        if (strcasecmp((string)($candidate['codigo'] ?? ''), $inputEmpresa) === 0 || strcasecmp((string)($candidate['nome'] ?? ''), $inputEmpresa) === 0 || strcasecmp((string)($candidate['banco_dados'] ?? ''), $inputEmpresa) === 0 || (string)($candidate['codempresa'] ?? '') === $inputEmpresa) {
            $empresa = $candidate;
            break;
        }
    }

    if (!$empresa || !preg_match('/^[A-Za-z0-9_]+$/', (string)$empresa['banco_dados'])) {
        greenPortalRedirectError('empresa_invalida');
    }

    try {
        $tenant = GreenPortalDatabase::connect($config, (string)$empresa['banco_dados'], 'GPT');
    } catch (Throwable $exception) {
        error_log('Green Portal tenant database error: ' . $exception->getMessage());
        greenPortalRedirectError('banco_indisponivel');
    }
    $usuarios = GreenPortalDatabase::call($tenant, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'usuarios', 'filtros' => json_encode(['userid' => $userid], JSON_UNESCAPED_UNICODE)]);
    $usuario = $usuarios[0] ?? null;
    if ($usuario && !empty($usuario['codperfil'])) {
        $perfis = GreenPortalDatabase::call($tenant, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'perfis', 'filtros' => json_encode(['codperfil' => (int)$usuario['codperfil']], JSON_UNESCAPED_UNICODE)]);
        $usuario['codigo_perfil'] = (string)($perfis[0]['codigo'] ?? '');
    }

    if (!$usuario) {
        greenPortalRedirectError('usuario_nao_encontrado');
    }

    if (!(bool)$usuario['ativo']) {
        greenPortalRedirectError('usuario_inativo');
    }

    $senhaValida = (bool)$usuario['senha_usa_hash']
        ? password_verify($senha, (string)$usuario['senha'])
        : hash_equals((string)$usuario['senha'], $senha);

    if (!$senhaValida) {
        greenPortalRedirectError('senha_incorreta');
    }

    // GER-18 (12/11/2026, decisão "Cadastro manda"): depois do login vale o idioma do cadastro do usuário (Profile);
    // o idioma escolhido no login só traduz a própria tela de login. Sem idioma no cadastro, fica o do login.
    require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';
    $idiomaCadastro = UsuarioImagem::idioma($tenant, (int)$usuario['codusuario']);
    if ($idiomaCadastro !== null && $idiomaCadastro !== $idioma) { $idioma = $idiomaCadastro; IdiomaService::lembrarIdioma($idioma); }

    session_regenerate_id(true);
    $_SESSION['autenticado'] = true;
    $_SESSION['idioma'] = $idioma;
    $_SESSION['tenant'] = [
        'codempresa' => (int)$empresa['codempresa'],
        'codigo' => $empresa['codigo'],
        'nome' => $empresa['nome'],
        'banco_dados' => $empresa['banco_dados'],
    ];
    TenantContext::require($_SESSION);
    $_SESSION['usuario'] = [
        'codusuario' => (int)$usuario['codusuario'],
        'userid' => $usuario['userid'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'codperfil' => $usuario['codperfil'],
        'codigo_perfil' => $usuario['codigo_perfil'],
    ];
    AuditService::record($tenant, 'LOGIN', 'AUTENTICAR', 'SUCESSO', 'usuarios', (string)$usuario['codusuario'], ['empresa' => $empresa['codigo']]);
    // 19/11/2026: grava o último login (usuarios.ultimo_acesso; a coluna "Último login" de Segurança › Usuários ficava sempre vazia).
    // Nunca impede o login.
    if (array_key_exists('ultimo_acesso', $usuario)) {
        try { GreenPortalDatabase::call($tenant, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'usuarios', 'chave' => json_encode(['codusuario' => (int)$usuario['codusuario']]), 'dados' => json_encode(['ultimo_acesso' => date('Y-m-d H:i:s')])]); }
        catch (Throwable $e) { error_log('Green Portal login ultimo_acesso: ' . $e->getMessage()); }
    }

    // Migração 343: veio do link "Abrir no portal" do e-mail de alerta: a tela do alerta abre como aba
    $abrirAlerta = max(0, (int)($_POST['abrir_alerta'] ?? 0));
    header('Location: ' . $greenPortalBaseUrl . '/principal/main.php' . ($abrirAlerta > 0 ? '?abrir_alerta=' . $abrirAlerta : ''));
    exit;
} catch (Throwable $exception) {
    error_log('Green Portal login error: ' . $exception->getMessage());
    greenPortalRedirectError('indisponivel');
}

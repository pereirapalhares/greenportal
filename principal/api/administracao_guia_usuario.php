<?php
declare(strict_types=1);

/*
 * API da transação Administração › Guia do usuário (ADMINISTRACAO_GUIA_USUARIO).
 * Ações: carregar (GET), versoes (GET), menus (GET), salvar (POST), publicar (POST), restaurar (POST).
 * O guia é universal: alterações só pelo perfil ADMINISTRADOR, com CSRF e auditoria no tenant.
 */

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/AuditService.php';
require_once dirname(__DIR__, 2) . '/classes/GuiaUsuarioService.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$json = static function (array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
$transacao = GuiaUsuarioService::TRANSACAO;
if (!PermissaoTransacao::obter($transacao)['pode_visualizar']) $json(['ok' => false, 'message' => 'Sem permissão para administrar o guia do usuário.'], 403);

try {
    $env = GreenPortalDatabase::env();
    $tenant = TenantContext::connect($env, $_SESSION);
    if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
    $universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
} catch (Throwable $e) {
    error_log('administracao_guia_usuario: ' . $e->getMessage());
    $json(['ok' => false, 'message' => 'Banco de dados indisponível.'], 503);
}
$service = new GuiaUsuarioService($universal);

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'carregar')));
$metodo = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mutacoes = ['salvar', 'publicar', 'restaurar'];

if (in_array($action, $mutacoes, true)) {
    if ($metodo !== 'POST') $json(['ok' => false, 'message' => 'Método não permitido.'], 405);
    $csrf = (string)($_SESSION['guia_usuario_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => 'Token de segurança inválido. Recarregue a tela.'], 419);
    // O guia vale para todas as empresas: além da permissão da transação, exige o perfil ADMINISTRADOR.
    if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR' || !PermissaoTransacao::obter($transacao)['pode_editar']) {
        AuditService::record($tenant, $transacao, strtoupper($action), 'NEGADO', 'guia_usuario');
        $json(['ok' => false, 'message' => 'Somente o perfil ADMINISTRADOR pode alterar o guia do usuário.'], 403);
    }
}

$autor = [
    'nome' => (string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['userid'] ?? ''),
    'codusuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0),
    'banco' => (string)($_SESSION['tenant']['banco_dados'] ?? ''),
];

try {
    if ($action === 'carregar') {
        $rascunho = $service->rascunho();
        if (!$rascunho) $json(['ok' => false, 'message' => 'O guia ainda não foi inicializado. Execute a migração 199.'], 409);
        $json(['ok' => true, 'rascunho' => $rascunho]);
    }
    if ($action === 'versoes') $json(['ok' => true, 'versoes' => $service->versoes()]);
    if ($action === 'menus') $json(['ok' => true, 'menus' => array_values($service->menusComUrl())]);

    if ($action === 'salvar') {
        $conteudo = json_decode((string)($_POST['conteudo'] ?? ''), true);
        if (!is_array($conteudo)) $json(['ok' => false, 'message' => 'Conteúdo inválido.'], 422);
        $revisao = $service->salvarRascunho($conteudo, (int)($_POST['revisao'] ?? 0), $autor);
        AuditService::record($tenant, $transacao, 'SALVAR', 'SUCESSO', 'guia_usuario_rascunho', '1', ['revisao' => $revisao]);
        $json(['ok' => true, 'revisao' => $revisao, 'rascunho' => $service->rascunho()]);
    }
    if ($action === 'publicar') {
        $versao = $service->publicar((int)($_POST['revisao'] ?? 0), (string)($_POST['observacao'] ?? ''), $autor);
        AuditService::record($tenant, $transacao, 'PUBLICAR', 'SUCESSO', 'guia_usuario_versoes', (string)$versao['codversao'], ['numero' => $versao['numero']]);
        $json(['ok' => true, 'versao' => $versao, 'rascunho' => $service->rascunho()]);
    }
    if ($action === 'restaurar') {
        $codversao = (int)($_POST['codversao'] ?? 0);
        $revisao = $service->restaurar($codversao, (int)($_POST['revisao'] ?? 0), $autor);
        AuditService::record($tenant, $transacao, 'RESTAURAR', 'SUCESSO', 'guia_usuario_versoes', (string)$codversao, ['revisao' => $revisao]);
        $json(['ok' => true, 'revisao' => $revisao, 'rascunho' => $service->rascunho()]);
    }
    $json(['ok' => false, 'message' => 'Ação não reconhecida.'], 400);
} catch (Throwable $e) {
    [$status, $mensagem] = GuiaUsuarioService::mensagemErro($e);
    if (in_array($action, $mutacoes, true)) AuditService::record($tenant, $transacao, strtoupper($action), 'ERRO', 'guia_usuario', null, ['mensagem' => mb_substr($mensagem, 0, 300)]);
    $json(['ok' => false, 'message' => $mensagem], $status);
}

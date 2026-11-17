<?php
declare(strict_types=1);

/*
 * Troca o idioma da sessão (menu de idioma do cabeçalho, ao lado do nome do usuário).
 * POST: idioma (pt|en|es), csrf. O navegador também lembra a escolha para a tela de login, e o idioma passa a ser o do
 * cadastro do usuário (preferencias_usuario.idioma — GER-18, 12/11/2026).
 */

require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/AuditService.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');
$responder = static function (array $dados, int $status = 200): never {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['autenticado'])) $responder(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $responder(['ok' => false, 'message' => 'Método não permitido.'], 405);
$csrf = (string)($_SESSION['idioma_troca_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $responder(['ok' => false, 'message' => 'Token de segurança inválido. Recarregue a página.'], 419);

$novo = strtolower(trim((string)($_POST['idioma'] ?? '')));
if (!in_array($novo, IdiomaService::IDIOMAS, true)) $responder(['ok' => false, 'message' => 'Idioma inválido.'], 422);
$anterior = (string)($_SESSION['idioma'] ?? 'pt');
$_SESSION['idioma'] = $novo;
IdiomaService::lembrarIdioma($novo);
try {
    $tenant = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    AuditService::record($tenant, 'MAIN', 'IDIOMA', 'SUCESSO', 'sessao', (string)($_SESSION['usuario']['codusuario'] ?? ''), ['de' => $anterior, 'para' => $novo]);
    // GER-18 (12/11/2026): a troca pelo cabeçalho também vale para o cadastro do usuário (próximos logins).
    require_once dirname(__DIR__, 2) . '/classes/UsuarioImagem.php';
    UsuarioImagem::salvarIdioma($tenant, (int)($_SESSION['usuario']['codusuario'] ?? 0), $novo);
} catch (Throwable $e) {
    error_log('api/idioma: ' . $e->getMessage());
}
$responder(['ok' => true, 'idioma' => $novo]);

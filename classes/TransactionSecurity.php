<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantContext.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PermissaoTransacao.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AuditService.php';

/** Bootstrap comum das transacoes: sessao, tenant, usuario e permissao. */
final class TransactionSecurity
{
    /** Open the authenticated tenant for universal Home routes. */
    public static function connectAuthenticated(string $auditTransaction = 'HOME'): PDO
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessao expirada.'); }
        $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
        if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit('Sessao invalida.'); }
        AuditService::record($pdo, $auditTransaction, 'ACESSAR', 'SUCESSO', 'transacao', $auditTransaction);
        return $pdo;
    }

    public static function connect(string $transaction, ?string $action = null): PDO
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessao expirada.'); }
        $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
        if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit('Sessao invalida.'); }
        $permission = PermissaoTransacao::obter($transaction);
        if (empty($permission[$action ?: 'pode_visualizar'])) { http_response_code(403); exit('Acesso nao autorizado.'); }
        AuditService::record($pdo, $transaction, 'ACESSAR', 'SUCESSO', 'transacao', $transaction);
        return $pdo;
    }
}

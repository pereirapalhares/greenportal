<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PermissaoTransacao.php';

/** Ponto unico para verificacao de acesso por transacao e acao. */
final class AuthorizationService
{
    public static function can(string $transaction, string $action = 'pode_visualizar'): bool
    {
        $permission = PermissaoTransacao::obter($transaction);
        return !empty($permission[$action]);
    }

    public static function require(string $transaction, string $action = 'pode_visualizar'): void
    {
        if (!self::can($transaction, $action)) {
            http_response_code(403);
            throw new RuntimeException('Acesso nao autorizado.');
        }
    }

    public static function userId(): int
    {
        return (int)($_SESSION['usuario']['codusuario'] ?? 0);
    }
}

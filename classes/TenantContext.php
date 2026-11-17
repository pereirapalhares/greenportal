<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';

/** Contexto validado do tenant corrente. Nunca aceita o banco vindo livremente de uma requisicao. */
final class TenantContext
{
    public static function require(array $session): array
    {
        if (empty($session['autenticado']) || empty($session['tenant']) || !is_array($session['tenant'])) {
            throw new RuntimeException('Sessao de tenant invalida.');
        }
        $tenant = $session['tenant'];
        $database = trim((string)($tenant['banco_dados'] ?? ''));
        if ($database === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('Banco do tenant invalido.');
        }
        $tenant['banco_dados'] = $database;
        $tenant['codempresa'] = (int)($tenant['codempresa'] ?? 0);
        if ($tenant['codempresa'] <= 0) throw new RuntimeException('Empresa do tenant invalida.');
        return $tenant;
    }

    public static function database(array $session): string
    {
        return self::require($session)['banco_dados'];
    }

    public static function connect(array $env, array $session, string $prefix = 'GPT'): PDO
    {
        return GreenPortalDatabase::connect($env, self::database($session), $prefix);
    }
}

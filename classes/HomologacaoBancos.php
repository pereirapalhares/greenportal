<?php
declare(strict_types=1);

/*
 * Bancos de cliente que recebem as migrações da Homologação de Fornecedores (216 a 303) — definidos pelo usuário em 17/10/2026.
 * Cada banco tem o banco legado de origem (cargas e comparações) e a pasta do GED (E:\projetoGreen\GED\<legado>).
 *
 * Os scripts aceitam --banco=gcrown_dbp (pode repetir) para rodar só nos bancos informados (sempre dentro desta lista).
 * Para incluir uma empresa nova na homologação: acrescentar o banco em BANCOS e rodar scripts\aplicar-homologacao-bancos.php.
 */
final class HomologacaoBancos
{
    /** banco novo => banco legado de origem */
    public const BANCOS = [
        'gmodelo_dbp' => 'green_modelo',
        'gatlas_dbp' => 'green_atlas',
        'gmodelo2_dbp' => 'green_modelo',
        'gcrown_dbp' => 'green_crown',
        'ggdamericana_dbp' => 'green_gd_americana',
        'gganchieta_dbp' => 'green_goodyear_anchieta',
    ];

    /** Bancos que já tinham a homologação completa antes de 17/10/2026 (as conversões de dados já decididas valem só para eles). */
    public const ORIGINAIS = ['gmodelo_dbp', 'gatlas_dbp', 'gmodelo2_dbp'];

    /** Bancos pedidos com --banco= (pode repetir); sem --banco, todos. Banco fora da lista gera erro. */
    public static function lista(array $argv = []): array
    {
        $pedidos = [];
        foreach ($argv as $a) {
            if (!str_starts_with((string)$a, '--banco=')) continue;
            foreach (explode(',', substr((string)$a, 8)) as $b) {
                $b = strtolower(trim($b));
                if ($b === '') continue;
                if (!isset(self::BANCOS[$b])) throw new InvalidArgumentException("Banco $b não está na lista da homologação (classes/HomologacaoBancos.php).");
                $pedidos[$b] = true;
            }
        }
        return $pedidos ? array_values(array_filter(array_keys(self::BANCOS), static fn($b) => isset($pedidos[$b]))) : array_keys(self::BANCOS);
    }

    /** [[legado, banco], ...] na ordem da lista */
    public static function pares(array $argv = []): array
    {
        return array_map(static fn($b) => [self::BANCOS[$b], $b], self::lista($argv));
    }

    /** banco => legado */
    public static function legados(array $argv = []): array
    {
        $out = [];
        foreach (self::lista($argv) as $b) $out[$b] = self::BANCOS[$b];
        return $out;
    }

    public static function legado(string $banco): ?string
    {
        return self::BANCOS[strtolower($banco)] ?? null;
    }

    public static function original(string $banco): bool
    {
        return in_array(strtolower($banco), self::ORIGINAIS, true);
    }
}

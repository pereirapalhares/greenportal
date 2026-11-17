<?php
declare(strict_types=1);

/**
 * Acesso a dados nas migrações (scripts/aplicar-migracao-*.php) sempre por procedure:
 *   leitura  → sp_crud_select (tabela ou view, filtros de igualdade, em lotes de 1000)
 *   inclusão → sp_crud_insert
 *   alteração → sp_crud_update
 * A estrutura (CREATE/ALTER TABLE, CREATE VIEW, CREATE PROCEDURE) continua por DDL direto, e os metadados
 * (information_schema) são lidos direto, porque não há outro jeito.
 *
 * garantir() cria no banco as procedures sp_crud_* que faltarem (definição de database/004_procedures_crud_views_modelo.sql),
 * para que o universal e as empresas novas tenham as quatro.
 */
final class MigracaoCrud
{
    private const LOTE = 1000;
    private const PROCEDURES = ['sp_crud_insert', 'sp_crud_update', 'sp_crud_delete', 'sp_crud_select'];

    /** Colunas da tabela/view no banco atual, em minúsculas (vazio = não existe). */
    public static function colunas(PDO $pdo, string $tabela): array
    {
        $s = $pdo->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$tabela]);
        $r = $s->fetchAll(PDO::FETCH_COLUMN);
        $s->closeCursor();
        return $r;
    }

    public static function existe(PDO $pdo, string $tabela): bool
    {
        return self::colunas($pdo, $tabela) !== [];
    }

    /** Cria as procedures sp_crud_* que não existirem no banco. Retorna as criadas. */
    public static function garantir(PDO $pdo): array
    {
        $s = $pdo->query("SELECT LOWER(ROUTINE_NAME) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'");
        $tem = $s->fetchAll(PDO::FETCH_COLUMN);
        $s->closeCursor();
        $faltam = array_values(array_diff(self::PROCEDURES, $tem));
        if (!$faltam) return [];
        $texto = (string)file_get_contents(dirname(__DIR__) . '/database/004_procedures_crud_views_modelo.sql');
        if (!preg_match('/DELIMITER \$\$(.*?)DELIMITER ;/s', $texto, $m)) throw new RuntimeException('definição das procedures sp_crud_* não encontrada (database/004)');
        $criadas = [];
        foreach (preg_split('/END\$\$/', $m[1]) as $bloco) {
            if (!preg_match('/CREATE PROCEDURE `(\w+)`/', $bloco, $n) || !in_array(strtolower($n[1]), $faltam, true)) continue;
            $ddl = trim(substr($bloco, (int)strpos($bloco, 'CREATE PROCEDURE'))) . "\nEND";
            // sp_crud_select roda como DEFINER (migração 155: SQL dinâmico sobre views no MySQL 8.4)
            if (strtolower($n[1]) === 'sp_crud_select') $ddl = str_replace('SQL SECURITY INVOKER', 'SQL SECURITY DEFINER', $ddl);
            $pdo->exec($ddl);
            $criadas[] = $n[1];
        }
        return $criadas;
    }

    /** Linhas da tabela/view com os filtros (igualdade). $maximo = 0: todas. */
    public static function selecionar(PDO $pdo, string $tabela, array $filtros = [], int $maximo = 0): array
    {
        self::validarFiltros($pdo, $tabela, $filtros);
        $json = $filtros ? json_encode(self::valores($filtros), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
        $linhas = [];
        for ($offset = 0; ; $offset += self::LOTE) {
            $lote = self::chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, ?, ?)', [$tabela, $json, self::LOTE, $offset]);
            array_push($linhas, ...$lote);
            if (count($lote) < self::LOTE || ($maximo > 0 && count($linhas) >= $maximo)) break;
        }
        return $maximo > 0 ? array_slice($linhas, 0, $maximo) : $linhas;
    }

    public static function primeiro(PDO $pdo, string $tabela, array $filtros): ?array
    {
        return self::selecionar($pdo, $tabela, $filtros, 1)[0] ?? null;
    }

    /** Maior valor numérico da coluna (0 se não houver linhas). */
    public static function maximo(PDO $pdo, string $tabela, string $coluna, array $filtros = []): int
    {
        $max = 0;
        foreach (self::selecionar($pdo, $tabela, $filtros) as $l) $max = max($max, (int)(array_change_key_case($l)[strtolower($coluna)] ?? 0));
        return $max;
    }

    /** Quantidade de linhas por valor da coluna (ex.: por status). */
    public static function contarPor(PDO $pdo, string $tabela, string $coluna, array $filtros = []): array
    {
        $r = [];
        foreach (self::selecionar($pdo, $tabela, $filtros) as $l) { $v = (string)(array_change_key_case($l)[strtolower($coluna)] ?? ''); $r[$v] = ($r[$v] ?? 0) + 1; }
        ksort($r);
        return $r;
    }

    /** Inclui e devolve a chave gerada (0 quando a tabela não tem auto incremento). */
    public static function inserir(PDO $pdo, string $tabela, array $dados): int
    {
        $r = self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', [$tabela, json_encode(self::valores($dados), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return (int)($r[0]['chave_gerada'] ?? 0);
    }

    /** Altera as linhas da chave. (O registros_afetados da sp_crud_update não é confiável: quem precisa contar, conta antes.) */
    public static function atualizar(PDO $pdo, string $tabela, array $chave, array $dados): void
    {
        if (!$chave || !$dados) throw new InvalidArgumentException('chave e dados são obrigatórios');
        self::chamar($pdo, 'CALL sp_crud_update(?, ?, ?)', [$tabela,
            json_encode(self::valores($chave), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode(self::valores($dados), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /**
     * Grava pela chave: altera se existir (só os campos de $dados), senão inclui ($chave + $dados + $soNaInclusao).
     * Retorna 'incluido' ou 'alterado'.
     */
    public static function salvar(PDO $pdo, string $tabela, array $chave, array $dados, array $soNaInclusao = []): string
    {
        if (self::primeiro($pdo, $tabela, $chave)) {
            if ($dados) self::atualizar($pdo, $tabela, $chave, $dados);
            return 'alterado';
        }
        self::inserir($pdo, $tabela, $chave + $dados + $soNaInclusao);
        return 'incluido';
    }

    private static function chamar(PDO $pdo, string $sql, array $params): array
    {
        // As procedures montam o comando com GROUP_CONCAT: textos longos (ajuda em HTML) passam do limite padrão de 1024
        $pdo->exec('SET SESSION group_concat_max_len = 16777216');
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $r = $s->fetchAll(PDO::FETCH_ASSOC);
        $s->closeCursor();
        return $r;
    }

    /** sp_crud_select ignora filtro de coluna inexistente: aqui isso é erro, para não trazer a tabela inteira. */
    private static function validarFiltros(PDO $pdo, string $tabela, array $filtros): void
    {
        if (!$filtros) return;
        $cols = self::colunas($pdo, $tabela);
        if (!$cols) throw new RuntimeException("tabela ou view $tabela não existe");
        foreach ($filtros as $campo => $valor) {
            if (!in_array(strtolower((string)$campo), $cols, true)) throw new RuntimeException("coluna $campo não existe em $tabela");
            if ($valor === null || is_array($valor)) throw new InvalidArgumentException("filtro $campo precisa de um valor simples");
        }
    }

    private static function valores(array $dados): array
    {
        return array_map(static fn($v) => is_bool($v) ? (int)$v : $v, $dados);
    }
}

<?php
declare(strict_types=1);

/*
 * Apoio às telas do Ranking de fornecedores (migração 289): administracao_pesos.php, homologacaofornecedor_ranking.php e
 * homologacaofornecedor_ranking_detalhe.php. Leitura só por views (sp_crud_select); gravação só por procedures.
 */
final class FornecedorRanking
{
    public const MENU_PESOS = 'ADMINISTRACAO_PESOS';
    public const MENU_RANKING = 'HOMOLOGACAO_FORNECEDOR_RANKING';
    public const CORES = ['A' => '#1f8a5b', 'B' => '#5aa469', 'C' => '#d99a1e', 'D' => '#c0392b'];

    /** Perfil normalizado (ADM_DA_EMPRESA → ADM DA EMPRESA). */
    public static function perfil(): string
    {
        $p = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
        return trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[_-]+/', ' ', $p)));
    }

    /** ADMINISTRADOR, ADM DA EMPRESA ou GESTOR: veem todas as unidades. */
    public static function visaoTotal(): bool
    {
        return in_array(self::perfil(), ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA', 'GESTOR'], true);
    }

    /** sp_crud_select em lotes de 1000; null se a view não existe (migração não aplicada). */
    public static function ler(PDO $pdo, string $view, array $filtros = []): ?array
    {
        try {
            $out = [];
            for ($o = 0; ; $o += 1000) {
                $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1000, :o)');
                $s->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'o' => $o]);
                $l = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
                array_push($out, ...$l);
                if (count($l) < 1000) break;
            }
            return $out;
        } catch (Throwable) { return null; }
    }

    /** Fornecedores do ranking visíveis para o usuário (todas as unidades ou só as dele). */
    public static function fornecedores(PDO $pdo, array $filtros = []): ?array
    {
        if (self::visaoTotal()) return self::ler($pdo, 'vw_fornecedor_ranking', $filtros);
        $linhas = self::ler($pdo, 'vw_fornecedor_ranking_acesso', $filtros + ['CODUSUARIO_ACESSO' => (int)($_SESSION['usuario']['codusuario'] ?? 0)]);
        if ($linhas === null) return null;
        $unicos = [];
        foreach ($linhas as $l) $unicos[(int)$l['CODFORNECEDOR']] = $l;
        return array_values($unicos);
    }

    /** CALL de procedure com parâmetro OUT; devolve o valor do OUT. */
    public static function chamar(PDO $pdo, string $procedure, array $parametros): string
    {
        $marcas = implode(', ', array_fill(0, count($parametros), '?'));
        $s = $pdo->prepare("CALL $procedure($marcas" . ($marcas !== '' ? ', ' : '') . '@gp_resultado)');
        $s->execute(array_values($parametros));
        do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset());
        $s->closeCursor();
        return (string)($pdo->query('SELECT @gp_resultado')->fetchColumn() ?: '');
    }

    public static function numero(mixed $v, int $casas = 1): string
    {
        return $v === null || $v === '' ? '–' : number_format((float)$v, $casas, ',', '.');
    }
}

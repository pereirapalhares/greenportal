<?php
declare(strict_types=1);

require_once __DIR__ . '/LegislacaoAssociacoes.php';

/**
 * Migração 394 — Tipos × Palavras-chave (glegislacao_dbp.tiposxpalavras_chaves; tipos em tipos_palavras_chaves; palavras no
 * catálogo único guniversal_dbp.palavras_chaves, o mesmo das leis e das licenças). Leitura e gravação por sp_crud_*.
 * Os volumes são pequenos (5 tipos, ~900 palavras, ~1.100 vínculos): as listas são montadas em memória.
 */
final class TiposPalavrasChave
{
    public const MENU = 'TIPOS_X_PALAVRAS_CHAVE';
    public const MENU_TIPOS = 'TIPOS_PALAVRAS_CHAVE';

    private static function call(string $sql, array $p): array
    {
        $s = LegislacaoAssociacoes::legislacao()->prepare($sql);
        $s->execute($p);
        $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        return $r;
    }

    /** Todas as linhas de uma tabela/view do glegislacao_dbp (sp_crud_select tem limite de 1.000 por chamada). */
    private static function todas(string $tabela, array $filtros = []): array
    {
        $out = [];
        for ($o = 0; ; $o += 1000) {
            $p = LegislacaoAssociacoes::ler($tabela, $filtros, '', 1000, $o);
            foreach ($p as $r) $out[] = array_change_key_case($r, CASE_LOWER);
            if (count($p) < 1000) break;
        }
        return $out;
    }

    /** [cod => ['cod', 'nome', 'qtd']] em ordem alfabética. */
    public static function tipos(): array
    {
        $t = [];
        foreach (self::todas('vw_tipos_palavras_chaves_grid') as $r)
            $t[(int)$r['codtipopalavrachave']] = ['cod' => (int)$r['codtipopalavrachave'], 'nome' => (string)$r['dsctipopalavrachave'], 'qtd' => (int)($r['qtd_palavras'] ?? 0)];
        uasort($t, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
        return $t;
    }

    /** Catálogo de palavras: [cod => texto]. */
    public static function palavras(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $out = [];
        for ($pagina = 1; ; $pagina++) {
            [$itens] = LegislacaoAssociacoes::paginaPalavras('', $pagina, 1000);
            foreach ($itens as $i) $out[(int)$i['cod']] = (string)$i['texto'];
            if (count($itens) < 1000) break;
        }
        return $cache = $out;
    }

    /** Vínculos: [codpalavra => [codtipo => codvinculo]]. */
    public static function vinculos(): array
    {
        $v = [];
        foreach (self::todas('tiposxpalavras_chaves') as $r) $v[(int)$r['codpalavrachave']][(int)$r['codtipopalavrachave']] = (int)$r['codtipoxpalavrachave'];
        return $v;
    }

    public static function vincular(int $tipo, int $palavra): bool
    {
        if (!isset(self::tipos()[$tipo])) throw new RuntimeException(LegislacaoTextos::t('erro.tipo_palavra_nao_encontrado'));
        if (!isset(self::palavras()[$palavra])) throw new RuntimeException(LegislacaoTextos::t('erro.palavra_nao_encontrada'));
        if (LegislacaoAssociacoes::ler('tiposxpalavras_chaves', ['CODTIPOPALAVRACHAVE' => $tipo, 'CODPALAVRACHAVE' => $palavra], '', 1)) return false;
        self::call('CALL sp_crud_insert(?, ?)', ['tiposxpalavras_chaves', json_encode(['CODTIPOPALAVRACHAVE' => $tipo, 'CODPALAVRACHAVE' => $palavra])]);
        return true;
    }

    public static function desvincular(int $tipo, int $palavra): int
    {
        $n = 0;
        foreach (LegislacaoAssociacoes::ler('tiposxpalavras_chaves', ['CODTIPOPALAVRACHAVE' => $tipo, 'CODPALAVRACHAVE' => $palavra]) as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            self::call('CALL sp_crud_delete(?, ?)', ['tiposxpalavras_chaves', json_encode(['CODTIPOXPALAVRACHAVE' => (int)$r['codtipoxpalavrachave']])]);
            $n++;
        }
        return $n;
    }
}

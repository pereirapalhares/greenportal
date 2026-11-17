<?php
declare(strict_types=1);

/**
 * Participantes externos das listas de distribuição de NC (migração 333, 22/10/2026 — decisão do usuário: "continuam, recebendo por e-mail").
 * No legado são usuários sem login (<legado>_seguranca.usuarios.ativado = 'X', nome + e-mail) colocados nas listas; o greenportal
 * não os migrou para usuarios (Crown: 809, 644 em listas, 863 vínculos de lista apontando para eles). Ficam em nc_participantes com o
 * mesmo código (os vínculos de lista continuam valendo) e ORIGEM = 'LEGADO'.
 *
 * sincronizar(): legado → greenportal, o legado prevalece (como o resto do sincronismo): inclui os que faltam, atualiza nome e e-mail,
 * exclui os que deixaram de ser participantes no legado (cópia em fo_sinc_exclusoes). A coluna ATIVO é do greenportal e não é alterada.
 * Participantes incluídos no greenportal (ORIGEM = 'GREENPORTAL', código a partir de 90000001) não são tocados.
 * Usado por scripts/sincronizar-fo-legado.php (a cada sincronismo) e pela migração 333 (carga inicial). Conexão sem banco padrão:
 * nomes sempre qualificados.
 */
final class NaoConformidadeParticipantes
{
    public const FAIXA = 90000001;

    private static function q(string $n): string { return '`' . str_replace('`', '``', $n) . '`'; }

    private static function valor(PDO $pdo, string $sql, array $p = []): mixed
    {
        $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r;
    }

    private static function existeTabela(PDO $pdo, string $db, string $t): bool
    {
        return (int)self::valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$db, $t]) > 0;
    }

    /** Banco do legado de onde o banco do greenportal foi migrado (mesma regra do sincronismo), ou null. */
    public static function origemDoBanco(PDO $pdo, string $destino): ?string
    {
        $origens = [];
        try {
            $s = $pdo->prepare('SELECT bancos_origem FROM guniversal_dbp.migracao_solicitacoes WHERE banco_destino = ? ORDER BY codsolicitacao DESC LIMIT 1');
            $s->execute([$destino]); $j = $s->fetchColumn(); $s->closeCursor();
            if ($j) $origens = (array)(json_decode((string)$j, true) ?: []);
        } catch (Throwable) {}
        if (!$origens) {
            try {
                foreach ($pdo->query('SELECT origem FROM ' . self::q($destino) . ".registros_migracao WHERE status = 'CONCLUIDA' AND origem LIKE 'green\\_%' ORDER BY codregistromigracao DESC")->fetchAll(PDO::FETCH_COLUMN) as $o)
                    foreach (preg_split('/[^A-Za-z0-9_]+/', (string)$o) as $x) if (str_starts_with($x, 'green_')) $origens[] = $x;
            } catch (Throwable) {}
        }
        foreach (array_unique($origens) as $o) {
            if (!is_string($o) || !preg_match('/^[A-Za-z0-9_]+$/', $o) || str_ends_with($o, '_seguranca')) continue;
            if (self::existeTabela($pdo, $o . '_seguranca', 'usuarios')) return $o;
        }
        return null;
    }

    /** @return array{incluidos:int, alterados:int, excluidos:int}|array{status:string}|array{erro:string} */
    public static function sincronizar(PDO $pdo, string $origem, string $destino, bool $aplicar): array
    {
        $seg = $origem . '_seguranca';
        $r = ['origem' => $seg . ".usuarios (ativado = 'X')", 'destino' => $destino . '.nc_participantes', 'incluidos' => 0, 'alterados' => 0, 'excluidos' => 0];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $origem) || !preg_match('/^g[a-z0-9_]+_dbp$/i', $destino)) return $r + ['erro' => 'banco inválido'];
        if (!self::existeTabela($pdo, $destino, 'nc_participantes')) return $r + ['status' => 'IGNORADA (aplicar a migração 333)'];
        if (!self::existeTabela($pdo, $seg, 'usuarios')) return $r + ['status' => "IGNORADA ($seg.usuarios não existe)"];
        $D = self::q($destino) . '.nc_participantes';
        $bin = static fn(string $x): string => "CONVERT($x USING utf8mb4) COLLATE utf8mb4_bin";
        // Participantes do legado (nome: NOME ou login; e-mail: EMAILS como está no legado)
        $S = '(SELECT u.CODUSUARIO AS cod, ' . $bin("LEFT(TRIM(COALESCE(NULLIF(TRIM(u.NOME), ''), NULLIF(TRIM(u.USERID), ''), CONCAT('Participante ', u.CODUSUARIO))), 200)") . ' AS nome, '
            . $bin("LEFT(TRIM(COALESCE(u.EMAILS, '')), 500)") . ' AS email FROM ' . self::q($seg) . ".usuarios u WHERE u.ativado = 'X' AND u.CODUSUARIO > 0)";
        $faltam = "FROM $S s WHERE NOT EXISTS (SELECT 1 FROM $D d WHERE d.CODUSUARIO = s.cod)";
        $mudaram = "FROM $D d JOIN $S s ON s.cod = d.CODUSUARIO WHERE d.ORIGEM = 'LEGADO' AND (NOT (" . $bin('d.NOME') . ' <=> s.nome) OR NOT (' . $bin('d.EMAIL') . ' <=> s.email))';
        $sairam = "FROM $D d WHERE d.ORIGEM = 'LEGADO' AND NOT EXISTS (SELECT 1 FROM $S s WHERE s.cod = d.CODUSUARIO)";
        try {
            if (!$aplicar) {
                return ['incluidos' => (int)self::valor($pdo, "SELECT COUNT(*) $faltam"), 'alterados' => (int)self::valor($pdo, "SELECT COUNT(*) $mudaram"),
                    'excluidos' => (int)self::valor($pdo, "SELECT COUNT(*) $sairam")] + $r;
            }
            $pdo->beginTransaction();
            $r['incluidos'] = (int)$pdo->exec("INSERT INTO $D (CODUSUARIO, NOME, EMAIL, ATIVO, ORIGEM) SELECT s.cod, s.nome, s.email, 1, 'LEGADO' $faltam");
            $r['alterados'] = (int)$pdo->exec("UPDATE $D d JOIN $S s ON s.cod = d.CODUSUARIO SET d.NOME = s.nome, d.EMAIL = s.email, d.ALTERADO_EM = NOW() WHERE d.ORIGEM = 'LEGADO' AND (NOT ("
                . $bin('d.NOME') . ' <=> s.nome) OR NOT (' . $bin('d.EMAIL') . ' <=> s.email))');
            if (self::existeTabela($pdo, $destino, 'fo_sinc_exclusoes'))
                $pdo->exec('INSERT INTO ' . self::q($destino) . ".fo_sinc_exclusoes (tabela, chave, dados) SELECT 'nc_participantes', JSON_OBJECT('CODUSUARIO', d.CODUSUARIO),
                    JSON_OBJECT('CODUSUARIO', d.CODUSUARIO, 'NOME', d.NOME, 'EMAIL', d.EMAIL, 'ATIVO', d.ATIVO, 'ORIGEM', d.ORIGEM) $sairam");
            $r['excluidos'] = (int)$pdo->exec("DELETE FROM $D WHERE ORIGEM = 'LEGADO' AND NOT EXISTS (SELECT 1 FROM $S s WHERE s.cod = $D.CODUSUARIO)");
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return $r + ['erro' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
}

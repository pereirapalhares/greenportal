<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/*
 * Migração 384 — Manutenção Legal › Legislação é do ADMINISTRADOR e administra TODAS as empresas já migradas.
 * Empresas migradas = guniversal_dbp.vw_empresas_login_migradas (as mesmas da tela de login, MODELO incluída).
 * O vínculo lei × empresa/unidade fica no banco de cada empresa (legislacaoxempresa); as unidades, em unidades.
 * Leitura por sp_crud_select e gravação por sp_crud_insert / sp_crud_delete.
 */
final class LegislacaoEmpresas
{
    private static ?array $empresas = null;
    private static array $conexoes = [];

    /** [codempresa => ['nome' => ..., 'banco' => ..., 'codigo' => ...]], em ordem de nome. */
    public static function migradas(): array
    {
        if (self::$empresas !== null) return self::$empresas;
        $env = GreenPortalDatabase::env();
        $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        $rows = self::ler($u, 'vw_empresas_login_migradas');
        usort($rows, static fn($a, $b) => strcasecmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? '')));
        self::$empresas = [];
        foreach ($rows as $r) {
            $banco = (string)($r['banco_dados'] ?? '');
            if ((int)($r['codempresa'] ?? 0) < 1 || !preg_match('/^[A-Za-z0-9_]+$/', $banco)) continue;
            self::$empresas[(int)$r['codempresa']] = ['nome' => (string)($r['nome'] ?? $r['codigo'] ?? $r['codempresa']), 'banco' => $banco, 'codigo' => (string)($r['codigo'] ?? '')];
        }
        return self::$empresas;
    }

    public static function pdo(int $codempresa): PDO
    {
        $e = self::migradas()[$codempresa] ?? null;
        if ($e === null) throw new InvalidArgumentException('Empresa não migrada para o GreenPortal.');
        if (!isset(self::$conexoes[$e['banco']])) {
            $p = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $e['banco'], 'GPT');
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$conexoes[$e['banco']] = $p;
        }
        return self::$conexoes[$e['banco']];
    }

    /** Unidades da empresa usadas na Manutenção Legal: [CODUNIDADE => DSCUNIDADE]. */
    public static function unidades(int $codempresa): array
    {
        return array_map(static fn(array $u): string => $u['nome'], self::unidadesRcl($codempresa));
    }

    /**
     * Migração 386: só as unidades marcadas "usado em manutenção legal = Sim" e com "tipo de RCL" = RCL Completa ou RCL Padrão
     * (colunas da tabela unidades do banco da empresa). [CODUNIDADE => ['nome' => ..., 'tipo' => 'COMPLETA'|'PADRAO']].
     */
    public static function unidadesRcl(int $codempresa): array
    {
        $chave = static function (string $v): string {
            if (class_exists('Normalizer')) $v = (string)preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($v, Normalizer::FORM_D));
            return strtoupper(trim((string)preg_replace('/\s+/', ' ', $v)));
        };
        $out = [];
        try {
            foreach (self::ler(self::pdo($codempresa), 'unidades') as $u) {
                $u = array_change_key_case($u, CASE_LOWER);
                if ($chave((string)($u['usado_em_manutencao_legal'] ?? '')) !== 'SIM') continue;
                $tipo = ['RCL COMPLETA' => 'COMPLETA', 'RCL PADRAO' => 'PADRAO'][$chave((string)($u['tipo_de_rcl'] ?? ''))] ?? null;
                if ($tipo === null) continue;
                $out[(int)$u['codunidade']] = ['nome' => (string)($u['dscunidade'] ?? $u['codunidade']), 'tipo' => $tipo];
            }
        } catch (Throwable) { return []; }
        uasort($out, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
        return $out;
    }

    /** Leis vinculadas à empresa (e à unidade, na mesma linha do vínculo). */
    public static function leis(int $codempresa, ?int $codunidade = null): array
    {
        $ids = [];
        $f = ['CODEMPRESA' => $codempresa];
        if ($codunidade !== null) $f['codunidade'] = $codunidade;
        foreach (self::ler(self::pdo($codempresa), 'legislacaoxempresa', $f) as $r) $ids[(int)$r['CODLEGISLACAO']] = true;
        return array_keys($ids);
    }

    public static function vincular(int $codlegislacao, int $codempresa, int $codunidade): void
    {
        if (!isset(self::unidades($codempresa)[$codunidade])) throw new InvalidArgumentException('A unidade escolhida não pertence a esta empresa.');
        $pdo = self::pdo($codempresa);
        if (self::ler($pdo, 'legislacaoxempresa', ['CODLEGISLACAO' => $codlegislacao, 'CODEMPRESA' => $codempresa, 'codunidade' => $codunidade], 1)) return;
        self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['legislacaoxempresa', json_encode(['CODLEGISLACAO' => $codlegislacao, 'CODEMPRESA' => $codempresa, 'codunidade' => $codunidade])]);
    }

    /** Ao excluir a lei: tira o vínculo em todas as empresas migradas. Devolve quantos saíram. */
    public static function desvincularDeTodas(int $codlegislacao): int
    {
        $n = 0;
        foreach (array_keys(self::migradas()) as $cod) {
            try { $pdo = self::pdo($cod); } catch (Throwable) { continue; }
            if (!self::ler($pdo, 'legislacaoxempresa', ['CODLEGISLACAO' => $codlegislacao], 1)) continue;
            self::chamar($pdo, 'CALL sp_crud_delete(?, ?)', ['legislacaoxempresa', json_encode(['CODLEGISLACAO' => $codlegislacao])]);
            $n++;
        }
        return $n;
    }

    public static function ler(PDO $pdo, string $tabela, array $filtros = [], int $maximo = 0): array
    {
        $out = [];
        for ($o = 0; ; $o += 1000) {
            $p = self::chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 1000, ?)', [$tabela, json_encode($filtros ?: new stdClass(), JSON_UNESCAPED_UNICODE), $o]);
            $out = array_merge($out, $p);
            if (count($p) < 1000 || ($maximo > 0 && count($out) >= $maximo)) break;
        }
        return $maximo > 0 ? array_slice($out, 0, $maximo) : $out;
    }

    private static function chamar(PDO $pdo, string $sql, array $p): array
    {
        $s = $pdo->prepare($sql); $s->execute($p);
        $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        return $r;
    }
}

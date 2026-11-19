<?php
declare(strict_types=1);

/**
 * Migração 393 — Sincronismo da LEGISLAÇÃO CENTRAL por OPERAÇÕES (de-para), legado (green_legislacao) → greenportal
 * (glegislacao_dbp). Decisão do usuário (17/11/2026): "continuar trazendo do sistema antigo, mas só as operações feitas lá,
 * sem apagar o que foi feito aqui"; se o mesmo registro mudou dos dois lados, vale o que mudou no legado (source).
 *
 * Como funciona:
 *  - Linha de base (glegislacao_dbp.legislacao_sinc_base): para cada tabela e registro do legado, a chave e um hash (MD5) de
 *    cada coluna, como estavam na última execução aplicada.
 *  - Em cada execução compara o legado ATUAL com a linha de base e descobre só as operações feitas no legado:
 *      incluído  (está no legado e não estava na base)  → inclui no greenportal (se já existir, o legado vale);
 *      alterado  (hash de alguma coluna mudou)           → atualiza SÓ as colunas que mudaram no legado (as outras ficam como
 *                                                          estão no greenportal); se o registro não existir mais aqui, inclui;
 *      excluído  (estava na base e saiu do legado)       → exclui no greenportal (cópia antes em legislacao_sinc_exclusoes).
 *    O que foi feito só no greenportal (inclusões, alterações, exclusões) não é tocado.
 *  - De-para das chaves: tabela com chave primária igual nos dois lados usa a chave; tabela sem chave comum (vínculos com
 *    código próprio do greenportal, ou sem chave) usa todas as colunas comuns como identidade (só inclui/exclui; repetições
 *    contadas). Colunas que só existem de um lado são ignoradas. Tabelas: as legislacao% + tipos, ambitos, assuntos,
 *    perguntas que existem nos dois bancos.
 *  - 1ª execução aplicada de cada tabela: só cria a linha de base (não muda nada no greenportal).
 *  - Mais exclusões numa tabela do que o limite (sincronismo_config.limite_exclusoes, padrão 500): as exclusões dessa tabela
 *    ficam para revisão (não são aplicadas e voltam na próxima execução).
 *  - Simulação (aplicar = false): só conta e mostra exemplos; nada é gravado.
 */
final class SincronismoLegislacaoOperacoes
{
    private const ORIGEM = 'green_legislacao';
    private const DESTINO = 'glegislacao_dbp';
    private const BASE = 'legislacao_sinc_base';
    private const LOTE = 300;
    private const MARCA = '00000000000000000000000000000000'; // linha de base criada (mesmo se a tabela do legado estiver vazia)

    // Bancos e tabelas da execução atual. Padrão = legislação central (393); universal() troca para o universal (400).
    private static string $origem = self::ORIGEM;
    private static string $destino = self::DESTINO;
    private static string $base = self::BASE;
    private static string $exclusoes = 'legislacao_sinc_exclusoes';
    private static ?array $soTabelas = null;

    /**
     * Migração 400 (19/11/2026, decisão do usuário): universal legado (green_universal) → guniversal_dbp, pelas mesmas regras
     * (só o que mudou no legado; o que foi feito no greenportal fica). Só o catálogo de palavras-chave (palavras_chaves), o
     * mesmo das leis e licenças: os vínculos lei × palavra já vêm pela legislação central. Tipos de palavras-chave e Tipos ×
     * Palavras-chave ficam fora (decisão de 18/11); Help Desk e pesquisas não são sincronizados (novo módulo no futuro).
     */
    public const UNIVERSAL_TABELAS = ['palavras_chaves'];

    public static function universal(PDO $pdo, bool $aplicar, int $limiteExclusoes = 500): array
    {
        self::$origem = 'green_universal'; self::$destino = 'guniversal_dbp'; self::$base = 'universal_sinc_base';
        self::$exclusoes = 'universal_sinc_exclusoes'; self::$soTabelas = self::UNIVERSAL_TABELAS;
        try { return self::executar($pdo, $aplicar, $limiteExclusoes, 'sinc_universal'); }
        finally { self::$origem = self::ORIGEM; self::$destino = self::DESTINO; self::$base = self::BASE; self::$exclusoes = 'legislacao_sinc_exclusoes'; self::$soTabelas = null; }
    }

    public static function executar(PDO $pdo, bool $aplicar, int $limiteExclusoes = 500, string $lock = 'sinc_legislacao_central'): array
    {
        $r = ['modo' => 'OPERACOES: só o que mudou no legado desde a última execução; o que foi feito no greenportal fica',
            'origem' => self::$origem, 'destino' => self::$destino, 'tabelas' => [],
            'totais' => ['processados' => 0, 'afetados' => 0, 'excluidos' => 0, 'incluidos' => 0, 'alterados' => 0, 'protegidos' => 0, 'exclusoes_bloqueadas' => 0]];
        $mem = (string)ini_get('memory_limit');
        if ($mem !== '-1' && (int)$mem < 1024) @ini_set('memory_limit', '2048M'); // 38 mil leis × hash por coluna
        if ((int)self::valor($pdo, 'SELECT GET_LOCK(?, 5)', [$lock]) !== 1) return $r + ['erro' => 'outra sincronizacao de ' . self::$destino . ' esta em execucao'];
        try {
            foreach ([self::$origem, self::$destino] as $db) if (!(int)self::valor($pdo, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db])) return $r + ['erro' => "banco $db não existe"];
            $pdo->exec("SET SESSION collation_connection = 'utf8mb4_bin'");
            $filtro = self::$soTabelas !== null ? 's.TABLE_NAME IN (' . implode(',', array_map(static fn($t) => $pdo->quote($t), self::$soTabelas)) . ')'
                : "(s.TABLE_NAME LIKE 'legislacao%' OR s.TABLE_NAME IN ('tipos','ambitos','assuntos','perguntas'))";
            $tabelas = array_column(self::linhas($pdo, "SELECT s.TABLE_NAME FROM information_schema.TABLES s JOIN information_schema.TABLES d ON d.TABLE_SCHEMA = ? AND d.TABLE_NAME = s.TABLE_NAME AND d.TABLE_TYPE = 'BASE TABLE' WHERE s.TABLE_SCHEMA = ? AND s.TABLE_TYPE = 'BASE TABLE' AND $filtro", [self::$destino, self::$origem]), 'TABLE_NAME');
            // pais antes dos vínculos (inclusões); exclusões na ordem inversa
            usort($tabelas, static fn($a, $b) => [self::ordem($a), $a] <=> [self::ordem($b), $b]);
            // estrutura antes da transação (DDL faz commit implícito)
            if ($aplicar) {
                $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::q(self::$destino) . '.' . self::q(self::$exclusoes) . ' (codexclusao BIGINT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(128) NOT NULL, chave JSON NULL, dados JSON NOT NULL, excluido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::q(self::$destino) . '.' . self::q(self::$base) . " (tabela VARCHAR(128) NOT NULL, chave CHAR(32) NOT NULL, chave_valores JSON NOT NULL, hashes JSON NOT NULL, atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (tabela, chave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Migração 393: estado do legado na última sincronização aplicada da legislação central'");
            }
            $temBase = (int)self::valor($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [self::$destino, self::$base]) > 0;

            $planos = [];
            foreach ($tabelas as $t) $planos[$t] = self::planejar($pdo, $t, $temBase);

            $pdo->beginTransaction();
            try {
                foreach ($planos as $t => $p) {
                    $info = ['legado' => $p['qtd_legado'], 'identidade' => $p['natural'] ? 'todas as colunas comuns' : 'chave ' . implode('+', $p['chave'])];
                    if ($p['sem_colunas']) continue;
                    $r['totais']['processados'] += $p['qtd_legado'];
                    if ($p['inicial']) {
                        $info['linha_de_base'] = $aplicar ? 'criada (nada mudou no greenportal)' : 'será criada na 1ª execução aplicada (nada muda no greenportal)';
                        if ($aplicar) {
                            self::gravarBase($pdo, $t, $p['legado'], array_keys($p['legado']));
                            $pdo->prepare('INSERT IGNORE INTO ' . self::q(self::$destino) . '.' . self::q(self::$base) . " (tabela, chave, chave_valores, hashes) VALUES (?, ?, '[]', '{}')")->execute([$t, self::MARCA]);
                        }
                        $r['tabelas'][$t] = $info; continue;
                    }
                    $bloqueia = count($p['excluidos']) > $limiteExclusoes;
                    $info += ['incluidos' => count($p['incluidos']), 'alterados' => count($p['alterados']), 'excluidos' => count($p['excluidos'])];
                    if ($bloqueia) { $info['exclusoes_bloqueadas'] = 'mais de ' . $limiteExclusoes . ' exclusões: não aplicadas, revisar'; $r['totais']['exclusoes_bloqueadas'] += count($p['excluidos']); }
                    $info['exemplos'] = self::exemplos($p);
                    if ($aplicar) {
                        [$inc, $alt] = self::aplicarInclusoesAlteracoes($pdo, $t, $p);
                        $info['incluidos'] = $inc; $info['alterados'] = $alt;
                        $r['totais']['incluidos'] += $inc; $r['totais']['alterados'] += $alt; $r['totais']['afetados'] += $inc + $alt;
                        self::gravarBase($pdo, $t, $p['legado'], array_merge(array_keys($p['incluidos']), array_keys($p['alterados'])));
                    } else {
                        $r['totais']['incluidos'] += count($p['incluidos']); $r['totais']['alterados'] += count($p['alterados']); $r['totais']['afetados'] += count($p['incluidos']) + count($p['alterados']);
                    }
                    $planos[$t]['bloqueia'] = $bloqueia;
                    $r['tabelas'][$t] = $info;
                }
                // exclusões dos vínculos antes das tabelas principais
                foreach (array_reverse($planos, true) as $t => $p) {
                    if ($p['sem_colunas'] || $p['inicial'] || !$p['excluidos'] || !empty($p['bloqueia'])) continue;
                    if (!$aplicar) { $r['totais']['excluidos'] += count($p['excluidos']); continue; }
                    [$exc, $prot] = self::aplicarExclusoes($pdo, $t, $p);
                    $r['tabelas'][$t]['excluidos'] = $exc; $r['tabelas'][$t]['protegidos'] = $prot;
                    $r['totais']['excluidos'] += $exc; $r['totais']['protegidos'] += $prot;
                    $parciais = array_keys(array_filter($p['excluidos'], static fn($e) => !empty($e['parcial'])));
                    self::apagarBase($pdo, $t, array_values(array_diff(array_keys($p['excluidos']), $parciais)));
                    if ($parciais) self::gravarBase($pdo, $t, $p['legado'], $parciais);
                }
                if ($aplicar) $pdo->commit(); else $pdo->rollBack();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            return $r;
        } catch (Throwable $e) {
            $r['erro'] = $e->getMessage();
            return $r;
        } finally {
            self::valor($pdo, 'SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function ordem(string $t): int
    {
        return match (true) { in_array($t, ['tipos', 'ambitos', 'assuntos', 'perguntas'], true) => 0, $t === 'legislacao' => 1, default => 2 };
    }

    /** Lê o legado (chave + hash de cada coluna) e a linha de base; devolve as operações. */
    private static function planejar(PDO $pdo, string $t, bool $temBase): array
    {
        $src = self::colunas($pdo, self::$origem, $t); $dst = self::colunas($pdo, self::$destino, $t);
        $comuns = array_values(array_intersect(array_keys($dst), array_keys($src))); // nomes em minúsculas
        $p = ['tabela' => $t, 'src' => $src, 'dst' => $dst, 'campos' => $comuns, 'sem_colunas' => !$comuns, 'inicial' => false,
            'legado' => [], 'qtd_legado' => 0, 'incluidos' => [], 'alterados' => [], 'excluidos' => [], 'natural' => false, 'chave' => []];
        if (!$comuns) return $p;
        $pk = array_map('strtolower', array_column(self::linhas($pdo, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [self::$destino, $t]), 'COLUMN_NAME'));
        $p['natural'] = !$pk || array_diff($pk, $comuns);
        $p['chave'] = $p['natural'] ? $comuns : $pk;
        // legado: chave + MD5 de cada coluna comum (sem trazer os textos)
        $sel = [];
        foreach ($p['chave'] as $i => $c) $sel[] = 'CAST(s.' . self::q($src[$c]) . ' AS CHAR CHARACTER SET utf8mb4) AS ' . self::q('k' . $i);
        if (!$p['natural']) foreach ($comuns as $i => $c) $sel[] = 'MD5(CAST(s.' . self::q($src[$c]) . ' AS CHAR CHARACTER SET utf8mb4)) AS ' . self::q('h' . $i);
        $st = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM ' . self::q(self::$origem) . '.' . self::q($t) . ' s');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $kv = []; foreach ($p['chave'] as $i => $c) $kv[] = $row['k' . $i];
            $k = md5(json_encode($kv, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            if ($p['natural']) {
                if (isset($p['legado'][$k])) { $p['legado'][$k]['h']['__n']++; } else $p['legado'][$k] = ['kv' => $kv, 'h' => ['__n' => 1]];
            } else {
                $h = []; foreach ($comuns as $i => $c) $h[$c] = $row['h' . $i];
                $p['legado'][$k] = ['kv' => $kv, 'h' => $h];
            }
            $p['qtd_legado']++;
        }
        $st->closeCursor();
        $base = [];
        if ($temBase) {
            $st = $pdo->prepare('SELECT chave, chave_valores, hashes FROM ' . self::q(self::$destino) . '.' . self::q(self::$base) . ' WHERE tabela = ?');
            $st->execute([$t]);
            $marca = false;
            while ($b = $st->fetch(PDO::FETCH_ASSOC)) { if ($b['chave'] === self::MARCA) { $marca = true; continue; } $base[$b['chave']] = ['kv' => json_decode((string)$b['chave_valores'], true), 'h' => json_decode((string)$b['hashes'], true)]; }
            $st->closeCursor();
            if (!$marca && !$base) { $p['inicial'] = true; return $p; }
        } else { $p['inicial'] = true; return $p; }
        foreach ($p['legado'] as $k => $l) {
            if (!isset($base[$k])) { $p['incluidos'][$k] = ['n' => $p['natural'] ? (int)$l['h']['__n'] : 1, 'novo' => true]; continue; }
            if ($p['natural']) {
                $d = (int)$l['h']['__n'] - (int)($base[$k]['h']['__n'] ?? 1);
                if ($d > 0) $p['incluidos'][$k] = ['n' => $d, 'novo' => false]; elseif ($d < 0) $p['excluidos'][$k] = ['kv' => $l['kv'], 'n' => -$d, 'parcial' => true];
                continue;
            }
            $mud = [];
            foreach ($l['h'] as $c => $h) if (!array_key_exists($c, $base[$k]['h']) || $base[$k]['h'][$c] !== $h) $mud[] = $c;
            if ($mud) $p['alterados'][$k] = $mud;
        }
        foreach ($base as $k => $b) if (!isset($p['legado'][$k])) $p['excluidos'][$k] = ['kv' => $b['kv'], 'n' => $p['natural'] ? (int)($b['h']['__n'] ?? 1) : 1, 'parcial' => false];
        return $p;
    }

    private static function exemplos(array $p): array
    {
        $ex = [];
        foreach (['incluidos', 'alterados', 'excluidos'] as $op) {
            $i = 0;
            foreach ($p[$op] as $k => $v) {
                if (++$i > 5) break;
                $kv = $op === 'excluidos' ? $v['kv'] : $p['legado'][$k]['kv'];
                $ex[$op][] = implode('|', array_map(static fn($x) => $x === null ? 'NULL' : mb_substr((string)$x, 0, 40), $kv)) . ($op === 'alterados' ? ' (' . implode(', ', $v) . ')' : '');
            }
        }
        return $ex;
    }

    private static function whereChave(array $p, string $alias, array $nomes): string
    {
        return '(' . implode(' AND ', array_map(static fn($c) => $alias . self::q($nomes[$c]) . ' <=> ?', $p['chave'])) . ')';
    }

    /** Linhas completas do legado para as chaves pedidas: [chave => [coluna => valor]]. */
    private static function lerLegado(PDO $pdo, array $p, array $chaves): array
    {
        $out = [];
        $cols = implode(', ', array_map(static fn($c) => 's.' . self::q($p['src'][$c]) . ' AS ' . self::q($c), $p['campos']))
            . ', ' . implode(', ', array_map(static fn($i, $c) => 'CAST(s.' . self::q($p['src'][$c]) . ' AS CHAR CHARACTER SET utf8mb4) AS ' . self::q('k__' . $i), array_keys($p['chave']), $p['chave']));
        foreach (array_chunk($chaves, self::LOTE) as $lote) {
            $where = []; $args = [];
            foreach ($lote as $k) { $where[] = self::whereChave($p, 's.', $p['src']); foreach ($p['legado'][$k]['kv'] as $v) $args[] = $v; }
            $st = $pdo->prepare('SELECT ' . $cols . ' FROM ' . self::q(self::$origem) . '.' . self::q($p['tabela']) . ' s WHERE ' . implode(' OR ', $where));
            $st->execute($args);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $kv = []; foreach (array_keys($p['chave']) as $i) { $kv[] = $row['k__' . $i]; unset($row['k__' . $i]); }
                $out[md5(json_encode($kv, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))] = $row;
            }
            $st->closeCursor();
        }
        return $out;
    }

    private static function aplicarInclusoesAlteracoes(PDO $pdo, string $t, array $p): array
    {
        if (!$p['incluidos'] && !$p['alterados']) return [0, 0];
        $dst = $p['dst']; $tab = self::q(self::$destino) . '.' . self::q($t);
        $linhas = self::lerLegado($pdo, $p, array_merge(array_keys($p['incluidos']), array_keys($p['alterados'])));
        $campos = $p['campos']; $lista = implode(', ', array_map(static fn($c) => self::q($dst[$c]), $campos));
        $marc = implode(', ', array_fill(0, count($campos), '?'));
        $naoChave = array_values(array_diff($campos, $p['chave']));
        $upd = $naoChave ? implode(', ', array_map(static fn($c) => self::q($dst[$c]) . ' = VALUES(' . self::q($dst[$c]) . ')', $naoChave)) : self::q($dst[$p['chave'][0]]) . ' = ' . self::q($dst[$p['chave'][0]]);
        $ins = $pdo->prepare("INSERT INTO $tab ($lista) VALUES ($marc)" . ($p['natural'] ? '' : " ON DUPLICATE KEY UPDATE $upd"));
        $existe = $pdo->prepare("SELECT COUNT(*) FROM $tab d WHERE " . self::whereChave($p, 'd.', $dst));
        $nInc = 0; $nAlt = 0;
        foreach ($p['incluidos'] as $k => $inc) {
            $row = $linhas[$k] ?? null; if (!$row) continue;
            $vals = array_map(static fn($c) => $row[$c], $campos);
            if (!$p['natural']) { $ins->execute($vals); $nInc++; continue; } // chave já existente aqui: o legado vale (ON DUPLICATE)
            $n = (int)$inc['n'];
            if ($inc['novo']) { $existe->execute($p['legado'][$k]['kv']); $n -= (int)$existe->fetchColumn(); $existe->closeCursor(); } // já incluído aqui também: não repete
            for ($i = 0; $i < $n; $i++) { $ins->execute($vals); $nInc++; }
        }
        foreach ($p['alterados'] as $k => $mud) {
            $row = $linhas[$k] ?? null; if (!$row) continue;
            $existe->execute($p['legado'][$k]['kv']); $ja = (int)$existe->fetchColumn(); $existe->closeCursor();
            if (!$ja) { $ins->execute(array_map(static fn($c) => $row[$c], $campos)); $nAlt++; continue; } // apagado aqui e alterado lá: o legado vale
            $set = implode(', ', array_map(static fn($c) => self::q($dst[$c]) . ' = ?', $mud));
            $st = $pdo->prepare("UPDATE $tab d SET $set WHERE " . self::whereChave($p, 'd.', $dst));
            $st->execute(array_merge(array_map(static fn($c) => $row[$c], $mud), $p['legado'][$k]['kv']));
            $nAlt++;
        }
        return [$nInc, $nAlt];
    }

    private static function aplicarExclusoes(PDO $pdo, string $t, array $p): array
    {
        $dst = $p['dst']; $tab = self::q(self::$destino) . '.' . self::q($t);
        $json = 'JSON_OBJECT(' . implode(', ', array_map(static fn($c) => $pdo->quote($dst[$c]) . ', d.' . self::q($dst[$c]), array_keys($dst))) . ')';
        $w = self::whereChave($p, 'd.', $dst);
        $exc = 0; $prot = 0;
        foreach ($p['excluidos'] as $k => $e) {
            $lim = $p['natural'] ? ' LIMIT ' . max(1, (int)$e['n']) : '';
            try {
                $pdo->exec('SAVEPOINT exc393');
                $b = $pdo->prepare('INSERT INTO ' . self::q(self::$destino) . '.' . self::q(self::$exclusoes) . " (tabela, chave, dados) SELECT ?, ?, $json FROM $tab d WHERE $w$lim");
                $b->execute(array_merge([$t, json_encode($e['kv'], JSON_UNESCAPED_UNICODE)], $e['kv']));
                $d = $pdo->prepare("DELETE FROM $tab WHERE " . self::whereChave($p, '', $dst) . $lim);
                $d->execute($e['kv']);
                $exc += $d->rowCount();
                $pdo->exec('RELEASE SAVEPOINT exc393');
            } catch (PDOException $x) {
                $pdo->exec('ROLLBACK TO SAVEPOINT exc393');
                $prot++; // ainda usado por outro registro (chave estrangeira): fica
            }
        }
        return [$exc, $prot];
    }

    private static function gravarBase(PDO $pdo, string $t, array $legado, array $chaves): void
    {
        $tab = self::q(self::$destino) . '.' . self::q(self::$base);
        foreach (array_chunk($chaves, 500) as $lote) {
            $vals = []; $args = [];
            foreach ($lote as $k) { if (!isset($legado[$k])) continue; $vals[] = '(?, ?, ?, ?)'; array_push($args, $t, $k, json_encode($legado[$k]['kv'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), json_encode($legado[$k]['h'])); }
            if ($vals) $pdo->prepare("INSERT INTO $tab (tabela, chave, chave_valores, hashes) VALUES " . implode(', ', $vals) . ' ON DUPLICATE KEY UPDATE chave_valores = VALUES(chave_valores), hashes = VALUES(hashes)')->execute($args);
        }
    }

    private static function apagarBase(PDO $pdo, string $t, array $chaves): void
    {
        $tab = self::q(self::$destino) . '.' . self::q(self::$base);
        foreach (array_chunk($chaves, 500) as $lote) $pdo->prepare("DELETE FROM $tab WHERE tabela = ? AND chave IN (" . implode(', ', array_fill(0, count($lote), '?')) . ')')->execute(array_merge([$t], $lote));
    }

    private static function colunas(PDO $pdo, string $db, string $t): array
    {
        $out = [];
        foreach (self::linhas($pdo, 'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]) as $c)
            if (stripos((string)$c['EXTRA'], 'GENERATED') === false) $out[strtolower((string)$c['COLUMN_NAME'])] = (string)$c['COLUMN_NAME'];
        return $out;
    }

    private static function q(string $n): string { return '`' . str_replace('`', '``', $n) . '`'; }
    private static function linhas(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; }
    private static function valor(PDO $pdo, string $sql, array $p = []): mixed { $r = self::linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; }
}

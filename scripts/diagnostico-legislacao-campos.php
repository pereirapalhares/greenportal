<?php
declare(strict_types=1);

/*
 * Diagnóstico SOMENTE LEITURA (24/11/2026) para a Atualização do Access: colunas do cadastro de Legislação do portal
 * (glegislacao_dbp) e as linhas de algumas leis, para ligar cada coluna do Access [Legislação Ambiental Aplicável]
 * a um campo do portal. Textos longos saem cortados em 300 caracteres.
 * Uso: php scripts\diagnostico-legislacao-campos.php storage\diag-legislacao-campos.json [CODIGO ...]   Temporário.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$saida = (string)($argv[1] ?? '');
$codigos = array_slice($argv ?? [], 2) ?: ['RES1729', 'NT1387', 'AL0366'];
$codigos = array_values(array_filter($codigos, static fn($c) => preg_match('/^[A-Za-z0-9_.\-]+$/', $c)));
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$corta = static fn(array $rs): array => array_map(static fn($r) => array_map(static fn($v) => is_string($v) && mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '…' : $v, $r), $rs);
$out = [];
try {
    $out['tabelas'] = array_column($q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'glegislacao_dbp' AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1"), 'TABLE_NAME');
    foreach (['legislacao', 'tipos', 'ambitos', 'assuntos', 'legislacaoxassunto', 'legislacaoxalteracao'] as $t)
        $out['colunas'][$t] = array_map(static fn($r) => $r['COLUMN_NAME'] . ' ' . $r['COLUMN_TYPE'], $q('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', ['glegislacao_dbp', $t]));
    if ($codigos) {
        $m = implode(',', array_fill(0, count($codigos), '?'));
        $out['leis'] = $corta($q("SELECT * FROM glegislacao_dbp.legislacao WHERE CODIGO IN ($m)", $codigos));
        $ids = array_column($out['leis'], 'CODLEGISLACAO');
        if ($ids) { $m2 = implode(',', array_fill(0, count($ids), '?')); $out['assuntos_das_leis'] = $corta($q("SELECT * FROM glegislacao_dbp.legislacaoxassunto WHERE CODLEGISLACAO IN ($m2)", $ids)); }
    }
    foreach (['tipos', 'ambitos', 'assuntos'] as $t) $out['amostra'][$t] = $corta($q("SELECT * FROM glegislacao_dbp.`$t` LIMIT 3"));
} catch (Throwable $e) { $out['erro'] = $e->getMessage(); }
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
if ($saida !== '') { file_put_contents($saida, $json); echo 'gravado em ' . $saida . PHP_EOL; } else echo $json;

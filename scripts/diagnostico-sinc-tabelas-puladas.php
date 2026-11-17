<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — tabelas da lista do sincronismo (SincronismoLegado::TABELAS_LEGAL e legislação central) que o
 * scripts/sincronizar-fo-legado.php PULA sem avisar (validação de 19/10/2026 na Atlas: legislacaoxaspecto, legislacaoxatividade,
 * legislacaoxpoder, legislacaoxdocumentos, legislacaoxlicencas; central: legislacaoxassunto, legislacaoxpergunta).
 * Para cada tabela: existe nos dois lados? colunas em comum? chave primária no greenportal e no legado? linhas de cada lado,
 * linhas do legado que não estão no greenportal e linhas do greenportal que não estão no legado (comparando todas as colunas
 * em comum, com repetições contadas).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-sinc-tabelas-puladas.php [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/SincronismoLegado.php';

$banco = 'gatlas_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$legado = HomologacaoBancos::legado($banco);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$Q = static fn(string $n): string => '`' . str_replace('`', '', $n) . '`';
$cols = static function (string $db, string $t) use ($q): array {
    $o = [];
    foreach ($q("SELECT COLUMN_NAME, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$db, $t]) as $c)
        if (stripos((string)$c['EXTRA'], 'GENERATED') === false) $o[strtolower($c['COLUMN_NAME'])] = $c;
    return $o;
};
$pk = static fn(string $db, string $t): array => array_column($q("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$db, $t]), 'COLUMN_NAME');

$analisar = static function (string $origem, string $destino, string $t) use ($q, $Q, $cols, $pk): array {
    $cO = $cols($origem, $t); $cD = $cols($destino, $t);
    if (!$cO) return ['situacao' => 'não existe no legado'];
    if (!$cD) return ['situacao' => 'não existe no greenportal'];
    $pkD = $pk($destino, $t); $pkO = $pk($origem, $t);
    // colunas em comum, sem autoincremento só do greenportal
    $comum = array_values(array_filter(array_keys(array_intersect_key($cD, $cO)), static fn($c) => !(stripos((string)$cD[$c]['EXTRA'], 'auto_increment') !== false && !isset($cO[$c]))));
    $r = ['pk_greenportal' => $pkD ?: '(sem chave primária)', 'pk_legado' => $pkO ?: '(sem chave primária)',
        'colunas_comuns' => count($comum), 'so_no_greenportal' => array_values(array_diff(array_keys($cD), array_keys($cO))), 'so_no_legado' => array_values(array_diff(array_keys($cO), array_keys($cD))),
        'linhas_legado' => (int)$q('SELECT COUNT(*) n FROM ' . $Q($origem) . '.' . $Q($t))[0]['n'], 'linhas_greenportal' => (int)$q('SELECT COUNT(*) n FROM ' . $Q($destino) . '.' . $Q($t))[0]['n']];
    $r['pulada_pelo_sincronismo'] = !$pkD ? 'SIM: sem chave primária no greenportal' : 'não';
    if (!$comum) return $r + ['situacao' => 'sem colunas em comum'];
    // comparação por conteúdo (todas as colunas em comum), contando repetições
    $h = static fn(string $a) => 'SHA1(JSON_ARRAY(' . implode(',', array_map(static fn($c) => "CAST($a." . '`' . $c . '`' . ' AS CHAR)', $comum)) . '))';
    $sql = 'SELECT SUM(GREATEST(COALESCE(o.n, 0) - COALESCE(d.n, 0), 0)) AS faltam, SUM(GREATEST(COALESCE(d.n, 0) - COALESCE(o.n, 0), 0)) AS sobram FROM ('
        . 'SELECT h FROM (SELECT ' . $h('s') . ' h FROM ' . $Q($origem) . '.' . $Q($t) . ' s UNION SELECT ' . $h('x') . ' FROM ' . $Q($destino) . '.' . $Q($t) . ' x) u) k'
        . ' LEFT JOIN (SELECT ' . $h('s') . ' h, COUNT(*) n FROM ' . $Q($origem) . '.' . $Q($t) . ' s GROUP BY 1) o ON o.h = k.h'
        . ' LEFT JOIN (SELECT ' . $h('x') . ' h, COUNT(*) n FROM ' . $Q($destino) . '.' . $Q($t) . ' x GROUP BY 1) d ON d.h = k.h';
    try { $c = $q($sql)[0]; $r['faltam_no_greenportal'] = (int)$c['faltam']; $r['sobram_no_greenportal'] = (int)$c['sobram']; }
    catch (Throwable $e) { $r['comparacao_erro'] = mb_substr($e->getMessage(), 0, 200); }
    return $r;
};

$saida = ['banco' => $banco, 'legado' => $legado, 'gerado_em' => date('c'), 'modulo_legal' => [], 'legislacao_central' => []];
foreach (SincronismoLegado::TABELAS_LEGAL as $t) {
    $a = $analisar((string)$legado, $banco, $t);
    if (($a['pulada_pelo_sincronismo'] ?? 'não') !== 'não' || ($a['faltam_no_greenportal'] ?? 0) || ($a['sobram_no_greenportal'] ?? 0) || isset($a['situacao'])) $saida['modulo_legal'][$t] = $a;
}
foreach (['legislacaoxassunto', 'legislacaoxpergunta', 'assuntos', 'legislacao', 'perguntas', 'legislacaoxpalavraschave', 'legislacaopaixlegislacaofilha'] as $t)
    $saida['legislacao_central'][$t] = $analisar('green_legislacao', 'glegislacao_dbp', $t);

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_sinc_tabelas_puladas_' . $banco . '.json', $json);
echo $json . PHP_EOL;

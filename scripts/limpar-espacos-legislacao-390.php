<?php
declare(strict_types=1);

/*
 * Migração 390 — limpeza: espaços sobrando (no início/fim, tabulação, quebra de linha, espaço duro, espaços repetidos)
 * nas colunas de lista da legislação: situacao_da_lei, devido_da_lei, MEIO_AMBIENTE, SEGURANCA, QUALIDADE, RESPOSABILIDADE.
 * Esses valores não são achados pelo filtro da coluna (ex.: "Devido à Atualização " com espaço no fim).
 * Sem --aplicar só mostra o que mudaria. Com --aplicar grava pelo sp_crud_update e guarda cópia do valor antigo em
 * database\backups\390_espacos_legislacao_<data>.json.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\limpar-espacos-legislacao-390.php [--aplicar]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
ini_set('memory_limit', '2048M');
@set_time_limit(0);

$aplicar = in_array('--aplicar', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$call = static function (string $sql, array $p = []) use ($leg): array {
    $s = $leg->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return $r;
};
$limpo = static fn(string $v): string => trim((string)preg_replace('/[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]+/u', ' ', $v));

$COLS = ['situacao_da_lei', 'devido_da_lei', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'];
$mudar = []; $resumo = [];
for ($o = 0; ; $o += 1000) {
    $p = $call('CALL sp_crud_select(?, ?, NULL, 1000, ?)', ['legislacao', '{}', $o]);
    foreach ($p as $r) {
        $id = (int)($r['CODLEGISLACAO'] ?? $r['codlegislacao']);
        foreach ($COLS as $c) {
            $v = $r[$c] ?? $r[strtolower($c)] ?? $r[strtoupper($c)] ?? null;
            if ($v === null || $v === '') continue;
            $n = $limpo((string)$v);
            if ($n === (string)$v) continue;
            $mudar[$id][$c] = ['de' => (string)$v, 'para' => $n === '' ? null : $n];
            $k = $c . ' | ' . json_encode((string)$v) . ' -> ' . json_encode($n); // json_encode mostra \t \n \u00a0
            $resumo[$k] = ($resumo[$k] ?? 0) + 1;
        }
    }
    $q = count($p); unset($p);
    if ($q < 1000) break;
}
arsort($resumo);
$rel = ['modo' => $aplicar ? 'APLICAR' : 'SIMULACAO (nada foi gravado; rode com --aplicar para corrigir)', 'leis_afetadas' => count($mudar),
    'valores' => array_sum(array_map('count', $mudar)), 'por_valor' => $resumo];

if ($aplicar && $mudar) {
    $dir = dirname(__DIR__) . '/database/backups'; @mkdir($dir, 0775, true);
    $arq = $dir . '/390_espacos_legislacao_' . date('Ymd_His') . '.json';
    file_put_contents($arq, json_encode($mudar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $ok = 0; $erros = [];
    foreach ($mudar as $id => $cols) {
        try {
            $call('CALL sp_crud_update(?, ?, ?)', ['legislacao', json_encode(['CODLEGISLACAO' => $id]), json_encode(array_map(static fn($x) => $x['para'], $cols), JSON_UNESCAPED_UNICODE)]);
            $ok++;
        } catch (Throwable $e) { if (count($erros) < 10) $erros[] = "$id: " . $e->getMessage(); }
    }
    $rel['gravadas'] = $ok; $rel['erros'] = $erros; $rel['backup'] = $arq;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

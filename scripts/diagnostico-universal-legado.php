<?php
declare(strict_types=1);
/*
 * Diagnóstico (só leitura, 19/11/2026): o que existe no legado green_universal (e green_seguranca) e o que corresponde no
 * guniversal_dbp — para decidir se/como o universal entra no sincronismo. Nada é gravado no banco.
 * Saída: tela + storage\logs\diagnostico_universal_legado.json
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-universal-legado.php
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
@set_time_limit(0);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$destino = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$tabelas = static fn(string $db): array => array_column($q("SELECT TABLE_NAME t, TABLE_TYPE tipo FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$db]), 'tipo', 't');
$colunas = static function (string $db, string $t) use ($q): array {
    return array_column($q('SELECT LOWER(COLUMN_NAME) c, COLUMN_KEY k FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]), 'k', 'c');
};
$conta = static function (string $db, string $t) use ($pdo): ?int { try { return (int)$pdo->query('SELECT COUNT(*) FROM `' . $db . '`.`' . str_replace('`', '', $t) . '`')->fetchColumn(); } catch (Throwable) { return null; } };
$dest = $tabelas($destino);
$r = ['gerado_em' => date('Y-m-d H:i:s'), 'destino' => $destino, 'destino_total_tabelas' => count(array_filter($dest, static fn($x) => $x === 'BASE TABLE'))];
foreach (['green_universal', 'green_seguranca'] as $origem) {
    $orig = $tabelas($origem);
    if (!$orig) { $r[$origem] = 'banco não encontrado ou sem tabelas'; continue; }
    $lista = [];
    foreach ($orig as $t => $tipo) {
        if ($tipo !== 'BASE TABLE') { $lista[$t] = ['tipo' => $tipo]; continue; }
        $item = ['linhas_legado' => $conta($origem, $t)];
        $par = null;
        foreach (array_keys($dest) as $d) if (strtolower($d) === strtolower($t)) { $par = $d; break; }
        if ($par === null) { $item['no_portal'] = 'NÃO EXISTE'; $item['colunas_legado'] = implode(', ', array_keys($colunas($origem, $t))); }
        else {
            $co = $colunas($origem, $t); $cd = $colunas($destino, $par);
            $item += ['no_portal' => $par, 'linhas_portal' => $conta($destino, $par),
                'chave_legado' => implode(',', array_keys(array_filter($co, static fn($k) => $k === 'PRI'))), 'chave_portal' => implode(',', array_keys(array_filter($cd, static fn($k) => $k === 'PRI'))),
                'colunas_comuns' => count(array_intersect_key($co, $cd)), 'so_legado' => implode(', ', array_keys(array_diff_key($co, $cd))), 'so_portal' => implode(', ', array_keys(array_diff_key($cd, $co)))];
        }
        $lista[$t] = $item;
    }
    $r[$origem] = ['tabelas' => count($lista), 'detalhe' => $lista];
}
$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_universal_legado.json', $json);
echo "Gravado em storage\\logs\\diagnostico_universal_legado.json\n";
foreach (['green_universal', 'green_seguranca'] as $o) if (is_array($r[$o] ?? null)) {
    $n = count(array_filter($r[$o]['detalhe'], static fn($x) => ($x['no_portal'] ?? '') !== 'NÃO EXISTE' && isset($x['no_portal'])));
    echo "$o: {$r[$o]['tabelas']} tabelas/views, $n com tabela de mesmo nome no $destino\n";
}

<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — tabelas "de sobra" do legado (_backup, _aux, _temp, _finalizados, _old...):
 * para cada uma, quantas linhas têm a mesma chave na tabela principal (já estão lá) e quantas NÃO estão
 * (informação que só existe na sobra e se perderia se a sobra não fosse migrada).
 * A tabela principal é o nome sem o sufixo (ex.: nc_indicacao_ocorrencia_finalizados -> nc_indicacao_ocorrencia).
 * A chave usada é a chave primária da tabela principal (ou a primeira coluna, se não houver).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-tabelas-sobra-legado.php --origem=green_crown [--prefixo=nc_]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$opc = static function (string $n) use ($argv): ?string { foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$n=")) return substr($a, strlen($n) + 3); return null; };
$origem = (string)$opc('origem'); $prefixo = strtolower((string)($opc('prefixo') ?? ''));
if (!preg_match('/^[A-Za-z0-9_]+$/', $origem)) { fwrite(STDERR, "Uso: --origem=green_crown [--prefixo=nc_]\n"); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$n = static fn(string $s): string => '`' . str_replace('`', '``', $s) . '`';

$tabelas = array_column($q("SELECT TABLE_NAME t FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$origem]), 't');
$porNome = array_change_key_case(array_combine($tabelas, $tabelas));
$rel = ['diagnostico' => 'tabelas de sobra do legado', 'origem' => $origem, 'gerado_em' => date('c'), 'tabelas' => []];
foreach ($tabelas as $t) {
    $tl = strtolower($t);
    if ($prefixo !== '' && !str_starts_with($tl, $prefixo)) continue;
    if (!preg_match('/^(.+?)_(backup|bkp|aux|temp|tmp|old|finalizados|\d{6,8})$/', $tl, $m) && !str_starts_with($tl, 'recupera_')) continue;
    $base = str_starts_with($tl, 'recupera_') ? substr($tl, 9) : $m[1];
    $item = ['principal' => $porNome[$base] ?? null];
    try {
        $item['linhas'] = (int)$q('SELECT COUNT(*) c FROM ' . $n($origem) . '.' . $n($t))[0]['c'];
        if (!$item['principal']) { $item['situacao'] = 'sem tabela principal com o mesmo nome — conferir à parte'; $rel['tabelas'][$t] = $item; continue; }
        $pk = array_column($q("SELECT COLUMN_NAME c FROM KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$origem, $item['principal']]), 'c');
        if (!$pk) $pk = [array_column($q('SELECT COLUMN_NAME c FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION LIMIT 1', [$origem, $item['principal']]), 'c')[0] ?? ''];
        $colsSobra = array_map('strtolower', array_column($q('SELECT COLUMN_NAME c FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$origem, $t]), 'c'));
        if (array_diff(array_map('strtolower', $pk), $colsSobra)) { $item['situacao'] = 'a sobra não tem a chave da principal (' . implode(', ', $pk) . ') — conferir à parte'; $rel['tabelas'][$t] = $item; continue; }
        $join = implode(' AND ', array_map(static fn($c) => 'p.' . $n($c) . ' <=> s.' . $n($c), $pk));
        $item['chave'] = implode(', ', $pk);
        $item['ja_na_principal'] = (int)$q('SELECT COUNT(*) c FROM ' . $n($origem) . '.' . $n($t) . ' s WHERE EXISTS (SELECT 1 FROM ' . $n($origem) . '.' . $n($item['principal']) . " p WHERE $join)")[0]['c'];
        $item['so_na_sobra'] = $item['linhas'] - $item['ja_na_principal'];
        $item['situacao'] = $item['so_na_sobra'] === 0 ? 'tudo já está na principal: pode ficar de fora' : 'TEM LINHAS QUE SÓ EXISTEM AQUI: migrar ou conferir';
    } catch (Throwable $e) { $item['erro'] = $e->getMessage(); }
    $rel['tabelas'][$t] = $item;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;

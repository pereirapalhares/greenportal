<?php
declare(strict_types=1);

/*
 * Migração 392 — SIMULAÇÃO (não grava nada) da limpeza dos catálogos repetidos:
 *   - palavras-chave (guniversal_dbp.palavras_chaves): mesma palavra com número no fim ("conhecimento"/"conhecimento2") ou
 *     igual sem diferença de espaços/maiúsculas/acentos; NR10, NR33 etc. (sigla + número) NÃO são juntadas;
 *   - perguntas do checklist (glegislacao_dbp.perguntas): texto igual sem diferença de espaços/maiúsculas/acentos.
 * Para cada grupo: qual fica (a sem número / a mais usada), quais sairiam e todas as referências que seriam movidas, em
 * TODOS os bancos que têm a coluna (glegislacao, guniversal e os bancos das empresas migradas — inclui as licenças).
 * Mostra também a situação do sincronismo com o legado, que traz de volta a legislação central e os vínculos das licenças.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\limpar-repetidos-catalogos-392.php
 * Resultado também em storage\limpeza-catalogos-392-simulacao.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';
ini_set('memory_limit', '2048M');
@set_time_limit(0);

$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
foreach ([$leg, $uni] as $p) $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$call = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return $r;
};
$todas = static function (PDO $pdo, string $tabela, array $f = []) use ($call): array {
    $out = [];
    for ($o = 0; ; $o += 1000) { $p = $call($pdo, 'CALL sp_crud_select(?, ?, NULL, 1000, ?)', [$tabela, json_encode($f ?: new stdClass()), $o]); foreach ($p as $r) $out[] = array_change_key_case($r, CASE_LOWER); if (count($p) < 1000) break; }
    return $out;
};
$norm = static fn(string $t): string => strtr(mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $t)), 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);

$rel = ['quando' => date('Y-m-d H:i:s'), 'modo' => 'SIMULACAO — nada foi gravado'];

// --- bancos onde procurar referências
$bancos = ['glegislacao_dbp' => $leg, 'guniversal_dbp' => $uni];
foreach (LegislacaoEmpresas::migradas() as $cod => $e) { try { $bancos[(string)$e['banco']] = LegislacaoEmpresas::pdo((int)$cod); } catch (Throwable $x) { $rel['avisos'][] = 'banco ' . $e['banco'] . ': ' . $x->getMessage(); } }
$referencias = static function (string $coluna, string $tabelaCatalogo) use ($bancos, $call): array {
    $out = [];
    foreach ($bancos as $banco => $pdo) {
        $s = $pdo->prepare("SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE' WHERE c.TABLE_SCHEMA = DATABASE() AND LOWER(c.COLUMN_NAME) = ?");
        $s->execute([strtolower($coluna)]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (strcasecmp((string)$r['TABLE_NAME'], $tabelaCatalogo) === 0) continue;
            if (preg_match('/(_sinc_|_backup|_bkp|exclusoes)/i', (string)$r['TABLE_NAME'])) continue; // backups do sincronismo
            $out[] = ['banco' => $banco, 'tabela' => (string)$r['TABLE_NAME'], 'coluna' => (string)$r['COLUMN_NAME']];
        }
    }
    return $out;
};
// contagem por código em cada tabela de referência (lê só a coluna pedida)
$usoPorCodigo = static function (array $refs) use ($bancos, $todas): array {
    $uso = [];
    foreach ($refs as $ref) {
        $pdo = $bancos[$ref['banco']];
        try { foreach ($todas($pdo, $ref['tabela']) as $r) { $c = (int)($r[strtolower($ref['coluna'])] ?? 0); if ($c > 0) $uso[$c][$ref['banco'] . '.' . $ref['tabela']] = ($uso[$c][$ref['banco'] . '.' . $ref['tabela']] ?? 0) + 1; } }
        catch (Throwable $e) { $uso['erro'][] = $ref['banco'] . '.' . $ref['tabela'] . ': ' . $e->getMessage(); }
    }
    return $uso;
};
$montarGrupos = static function (array $itens, callable $chave, callable $semNumero) use ($norm): array {
    $g = [];
    foreach ($itens as $cod => $txt) { $k = $chave($txt); if ($k !== '') $g[$k][$cod] = $txt; }
    return array_filter($g, static fn($v) => count($v) > 1);
};
$planejar = static function (array $grupos, array $uso, callable $base) : array {
    $plano = []; $totMover = 0; $totSair = 0;
    foreach ($grupos as $k => $membros) {
        $u = static fn($c) => array_sum($uso[$c] ?? []);
        $cods = array_keys($membros);
        usort($cods, static fn($a, $b) => [$base($membros[$b]) ? 1 : 0, $u($b), -$b] <=> [$base($membros[$a]) ? 1 : 0, $u($a), -$a]);
        $fica = array_shift($cods);
        $sai = [];
        foreach ($cods as $c) { $sai[] = '#' . $c . ' "' . $membros[$c] . '" — ' . ($uso[$c] ?? [] ? implode(', ', array_map(static fn($t, $n) => "$t: $n", array_keys($uso[$c]), $uso[$c])) : 'sem uso'); $totMover += $u($c); $totSair++; }
        $plano[] = ['fica' => '#' . $fica . ' "' . $membros[$fica] . '" (' . $u($fica) . ' usos)', 'sai' => $sai];
    }
    return [$plano, $totSair, $totMover];
};

// --- palavras-chave
$pal = []; foreach ($todas($uni, 'palavras_chaves') as $r) $pal[(int)$r['codpalavrachave']] = (string)($r['dscpalavrachave'] ?? '');
$sigla = static fn(string $t): bool => (bool)preg_match('/^\s*(nr|nbr|iso|nho|nt|it|cbm|abnt)\s*-?\s*\d/i', $t);
$chavePal = static function (string $t) use ($norm, $sigla): string {
    $n = $norm($t);
    if ($sigla($t)) return 'sigla:' . $n; // NR10, NR33... ficam separadas
    return (string)preg_replace('/(?<=\p{L})\s*\d{1,2}$/u', '', $n);
};
$refsPal = $referencias('codpalavrachave', 'palavras_chaves');
$usoPal = $usoPorCodigo($refsPal);
$gruposPal = $montarGrupos($pal, $chavePal, static fn() => true);
[$planoPal, $saiPal, $moverPal] = $planejar($gruposPal, $usoPal, static fn(string $t): bool => !preg_match('/\d$/', trim($t)));
$rel['palavras_chave'] = ['catalogo' => count($pal), 'grupos' => count($gruposPal), 'sairiam_do_catalogo' => $saiPal, 'referencias_a_mover' => $moverPal,
    'onde_ha_referencias' => array_map(static fn($r) => $r['banco'] . '.' . $r['tabela'], $refsPal), 'grupos_detalhe' => $planoPal, 'erros' => $usoPal['erro'] ?? []];

// --- perguntas
$per = []; foreach ($todas($leg, 'perguntas') as $r) $per[(int)$r['codpergunta']] = (string)($r['dscpergunta'] ?? '');
$refsPer = $referencias('codpergunta', 'perguntas');
$usoPer = $usoPorCodigo($refsPer);
$gruposPer = $montarGrupos($per, static fn(string $t): string => $norm($t), static fn() => true);
[$planoPer, $saiPer, $moverPer] = $planejar($gruposPer, $usoPer, static fn(string $t): bool => true);
$rel['perguntas'] = ['catalogo' => count($per), 'grupos' => count($gruposPer), 'sairiam_do_catalogo' => $saiPer, 'referencias_a_mover' => $moverPer,
    'onde_ha_referencias' => array_map(static fn($r) => $r['banco'] . '.' . $r['tabela'], $refsPer), 'grupos_detalhe' => $planoPer, 'erros' => $usoPer['erro'] ?? []];

// --- sincronismo com o legado (traz de volta perguntas, legislacaoxpergunta, legislacaoxpalavraschave e, nas empresas,
//     legislacaoxchecklist e li_licencasxpalavraschave)
try { $rel['sincronismo']['configuracao'] = array_map(static fn($r) => array_change_key_case($r, CASE_LOWER), $call($uni, 'CALL sp_crud_select(?, ?, NULL, 10, 0)', ['sincronismo_config', '{}'])); }
catch (Throwable $e) { $rel['sincronismo']['configuracao'] = 'não foi possível ler: ' . $e->getMessage(); }
try {
    $ex = array_map(static fn($r) => array_change_key_case($r, CASE_LOWER), $call($uni, 'CALL sp_crud_select(?, ?, NULL, 1000, 0)', ['vw_sincronismo_execucoes', '{}']));
    usort($ex, static fn($a, $b) => (int)($b['id'] ?? $b['codexecucao'] ?? 0) <=> (int)($a['id'] ?? $a['codexecucao'] ?? 0));
    $rel['sincronismo']['ultimas_execucoes'] = array_slice($ex, 0, 5);
} catch (Throwable $e) { $rel['sincronismo']['ultimas_execucoes'] = 'não foi possível ler: ' . $e->getMessage(); }
try {
    $s = $leg->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'green_legislacao' AND TABLE_NAME IN ('perguntas','legislacaoxpergunta','legislacaoxpalavraschave')");
    $rel['sincronismo']['legado_green_legislacao_tem_as_tabelas'] = (int)$s->fetchColumn() . ' de 3';
    $s = $leg->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'green_universal' AND TABLE_NAME = 'palavras_chaves'");
    $rel['sincronismo']['legado_green_universal_tem_palavras_chaves'] = (int)$s->fetchColumn() === 1;
} catch (Throwable $e) { $rel['sincronismo']['legado'] = 'não foi possível conferir: ' . $e->getMessage(); }

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@mkdir(dirname(__DIR__) . '/storage', 0775, true);
file_put_contents(dirname(__DIR__) . '/storage/limpeza-catalogos-392-simulacao.json', $json);
echo $json, PHP_EOL;

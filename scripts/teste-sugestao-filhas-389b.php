<?php
declare(strict_types=1);

/*
 * Teste 389b (somente leitura): segunda rodada dos critérios de "possíveis filhas", ajustada ao que o teste 389 mostrou.
 * Critérios: mesmo âmbito, mesmo tipo, mesmo órgão (sigla no tipo), mesma espécie (Lei, Decreto, Portaria...),
 * família emenda (Constituição/Lei Orgânica/Lei -> Emenda/ELOM/Medida Provisória), mesmo assunto, data.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-sugestao-filhas-389b.php
 * Grava o resultado também em storage\teste-sugestao-filhas-389b.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
ini_set('memory_limit', '2048M');
@set_time_limit(0);

$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$call = static function (string $sql, array $p = []) use ($leg): array {
    $s = $leg->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return array_map(static fn($x) => array_change_key_case($x, CASE_LOWER), $r);
};
$paginas = static function (string $tabela, callable $cada) use ($call): void {
    for ($o = 0; ; $o += 1000) {
        $p = $call('CALL sp_crud_select(?, ?, NULL, 1000, ?)', [$tabela, '{}', $o]);
        foreach ($p as $r) $cada($r);
        $n = count($p); unset($p);
        if ($n < 1000) break;
    }
};
$semAcento = static function (string $t): string {
    $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', $t)), 'UTF-8');
    $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    return $c === false ? $t : $c;
};
$pct = static fn(int $a, int $b): string => $b ? number_format(100 * $a / $b, 1, ',', '') . '%' : '-';
$mediana = static function (array $v) { if (!$v) return 0; sort($v); return $v[intdiv(count($v), 2)]; };

// órgãos = siglas em maiúsculas no nome do tipo ("Portaria de Consolidação MS" -> MS; "Resolução Conjunta SMA/SAA" -> SMA, SAA)
$orgaos = static function (string $tipo): array {
    $o = [];
    foreach (preg_split('/[\s\/\-–,.()]+/u', $tipo) as $t) if (mb_strlen($t) >= 2 && $t === mb_strtoupper($t) && preg_match('/\p{L}/u', $t) && !in_array($t, ['NR', 'N', 'NE', 'RE', 'RDC', 'GM', 'PR', 'E', 'DE', 'DA', 'DO'], true)) $o[$t] = true;
    return $o;
};
$especie = static function (string $tipo) use ($semAcento): string {
    $t = $semAcento($tipo);
    foreach (['emenda', 'elom', 'constitui', 'lei', 'medida provis', 'decreto', 'portaria', 'resolu', 'instru', 'delibera', 'norma', 'nota tecnica', 'comunicado', 'ato', 'despacho', 'circular', 'orienta', 'parecer', 'disposi', 'acuerdo', 'reglamento', 'regulamento', 'directiv', 'codigo'] as $e) if (str_starts_with($t, $e)) return $e;
    return $t;
};
$familiaEmenda = static function (string $tp, string $tf) use ($semAcento): bool {
    $p = $semAcento($tp); $f = $semAcento($tf);
    $paiBase = str_contains($p, 'constitui') || str_contains($p, 'organica') || str_starts_with($p, 'lei');
    $filhaEmenda = str_contains($f, 'emenda') || str_starts_with($f, 'elom') || str_contains($f, 'medida provis');
    return ($paiBase && $filhaEmenda) || (str_contains($p, 'medida provis') && str_starts_with($f, 'lei'));
};

$rel = ['quando' => date('Y-m-d H:i:s')];
$tipos = []; $paginas('tipos', function ($r) use (&$tipos) { $tipos[(int)$r['codtipo']] = trim((string)($r['dsctipo'] ?? '')); });

$lei = []; $porAmbito = [];
$paginas('legislacao', function ($r) use (&$lei, &$porAmbito) {
    $id = (int)$r['codlegislacao'];
    $lei[$id] = ['t' => (int)($r['codtipo'] ?? 0), 'a' => (int)($r['codambito'] ?? 0), 'ano' => (int)($r['ano'] ?? 0)];
    $porAmbito[(int)($r['codambito'] ?? 0)][] = $id;
});
$infoTipo = [];
foreach ($tipos as $cod => $nome) $infoTipo[$cod] = ['org' => $orgaos($nome), 'esp' => $especie($nome), 'nome' => $nome];

$pares = []; $filhasDe = [];
$paginas('legislacaopaixlegislacaofilha', function ($r) use (&$pares, &$filhasDe, $lei) {
    $p = (int)$r['codlegislacao_pai']; $f = (int)$r['codlegislacao_filha'];
    if ($p === $f || !isset($lei[$p], $lei[$f]) || isset($pares["$p-$f"])) return;
    $pares["$p-$f"] = [$p, $f]; $filhasDe[$p][$f] = true;
});
$assuntos = []; $paginas('legislacaoxassunto', function ($r) use (&$assuntos) { $assuntos[(int)$r['codlegislacao']][(int)$r['codassunto']] = true; });
$n = count($pares);

// leis por assunto: para saber se o assunto é largo demais
$porAssunto = [];
foreach ($assuntos as $id => $as) foreach ($as as $a => $_) $porAssunto[$a] = ($porAssunto[$a] ?? 0) + 1;
arsort($porAssunto);
$rel['leis_por_assunto_maiores'] = array_slice($porAssunto, 0, 10, true);
$rel['assuntos_total'] = count($porAssunto);
$rel['leis_por_assunto_mediana'] = $mediana(array_values($porAssunto));

// critérios de um par
$crit = static function (int $p, int $x) use ($lei, $infoTipo, $assuntos, $familiaEmenda): array {
    $tp = $infoTipo[$lei[$p]['t']] ?? ['org' => [], 'esp' => '', 'nome' => '']; $tx = $infoTipo[$lei[$x]['t']] ?? ['org' => [], 'esp' => '', 'nome' => ''];
    return [
        'ambito' => $lei[$p]['a'] === $lei[$x]['a'],
        'tipo' => $lei[$p]['t'] === $lei[$x]['t'],
        'orgao' => (bool)array_intersect_key($tp['org'], $tx['org']),
        'especie' => $tp['esp'] !== '' && $tp['esp'] === $tx['esp'],
        'familia' => $familiaEmenda($tp['nome'], $tx['nome']),
        'assunto' => isset($assuntos[$p], $assuntos[$x]) && (bool)array_intersect_key($assuntos[$p], $assuntos[$x]),
        'data' => !$lei[$p]['ano'] || !$lei[$x]['ano'] || $lei[$x]['ano'] >= $lei[$p]['ano'],
    ];
};

// 1) acerto de cada critério nos vínculos existentes
$cont = array_fill_keys(['ambito', 'tipo', 'orgao', 'especie', 'familia', 'assunto', 'data'], 0);
$combo = ['ambito_e_(tipo|orgao|familia)' => 0, 'ambito_e_(tipo|orgao|familia|especie)' => 0, 'ambito_e_(tipo|orgao|familia|especie|assunto)' => 0];
$duvidosos = []; $qtdDuvidosos = 0;
foreach ($pares as [$p, $f]) {
    $c = $crit($p, $f);
    foreach ($cont as $k => $_) if ($c[$k]) $cont[$k]++;
    $forte = $c['tipo'] || $c['orgao'] || $c['familia'];
    if ($c['ambito'] && $forte) $combo['ambito_e_(tipo|orgao|familia)']++;
    if ($c['ambito'] && ($forte || $c['especie'])) $combo['ambito_e_(tipo|orgao|familia|especie)']++;
    if ($c['ambito'] && ($forte || $c['especie'] || $c['assunto'])) $combo['ambito_e_(tipo|orgao|familia|especie|assunto)']++;
    if (!$c['ambito'] || !($forte || $c['especie'] || $c['assunto'])) {
        $qtdDuvidosos++;
        if (count($duvidosos) < 30) $duvidosos[] = "$p ({$infoTipo[$lei[$p]['t']]['nome']}/{$lei[$p]['ano']}) -> $f ({$infoTipo[$lei[$f]['t']]['nome']}/{$lei[$f]['ano']})" . ($c['ambito'] ? '' : ' [âmbito diferente]');
    }
}
foreach ($cont as $k => $v) $rel['criterios'][$k] = "$v de $n (" . $pct($v, $n) . ')';
foreach ($combo as $k => $v) $rel['combinacoes'][$k] = "$v de $n (" . $pct($v, $n) . ')';
$rel['vinculos_duvidosos'] = $qtdDuvidosos;
$rel['exemplos_duvidosos'] = $duvidosos;

// 2) simulação: Sugeridas = mesmo âmbito E (tipo | órgão | família | espécie | assunto); ordem pela nota
$nota = static function (array $c, int $p, int $x) use ($lei): int {
    $s = ($c['tipo'] ? 40 : 0) + ($c['orgao'] ? 25 : 0) + ($c['familia'] ? 30 : 0) + ($c['especie'] ? 10 : 0) + ($c['assunto'] ? 15 : 0) + ($c['data'] ? 5 : 0);
    if ($lei[$p]['ano'] && $lei[$x]['ano']) $s += max(0, 5 - intdiv(abs($lei[$x]['ano'] - $lei[$p]['ano']), 3)); // anos próximos primeiro
    return $s;
};
$res = ['A' => [], 'B' => []]; // A = com espécie e assunto; B = só tipo/órgão/família
foreach (['A', 'B'] as $v) $res[$v] = ['na' => 0, 't25' => 0, 't100' => 0, 'tam' => [], 'pos' => []];
foreach ($filhasDe as $p => $fs) {
    $sc = ['A' => [], 'B' => []];
    foreach ($porAmbito[$lei[$p]['a']] ?? [] as $x) {
        if ($x === $p) continue;
        $c = $crit($p, $x);
        $forte = $c['tipo'] || $c['orgao'] || $c['familia'];
        if ($forte || $c['especie'] || $c['assunto']) $sc['A'][$x] = $nota($c, $p, $x);
        if ($forte) $sc['B'][$x] = $nota($c, $p, $x);
    }
    foreach (['A', 'B'] as $v) {
        arsort($sc[$v]); $res[$v]['tam'][] = count($sc[$v]);
        $pos = array_flip(array_keys($sc[$v]));
        foreach ($fs as $f => $_) {
            if (!isset($pos[$f])) continue;
            $r = $pos[$f] + 1; $res[$v]['na']++; $res[$v]['pos'][] = $r;
            if ($r <= 25) $res[$v]['t25']++;
            if ($r <= 100) $res[$v]['t100']++;
        }
    }
}
foreach (['A' => 'A_ambito_e_(tipo|orgao|familia|especie|assunto)', 'B' => 'B_ambito_e_(tipo|orgao|familia)'] as $v => $nome) {
    $t = $res[$v]['tam'];
    $rel['simulacao'][$nome] = [
        'filhas_em_sugeridas' => "{$res[$v]['na']} de $n (" . $pct($res[$v]['na'], $n) . ')',
        'filhas_na_1a_pagina_25' => "{$res[$v]['t25']} de $n (" . $pct($res[$v]['t25'], $n) . ')',
        'filhas_nas_primeiras_100' => "{$res[$v]['t100']} de $n (" . $pct($res[$v]['t100'], $n) . ')',
        'tamanho_lista_mediana' => $mediana($t), 'tamanho_lista_maior' => $t ? max($t) : 0,
        'posicao_mediana_da_filha' => $mediana($res[$v]['pos']),
    ];
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@mkdir(dirname(__DIR__) . '/storage', 0775, true);
file_put_contents(dirname(__DIR__) . '/storage/teste-sugestao-filhas-389b.json', $json);
echo $json, PHP_EOL;

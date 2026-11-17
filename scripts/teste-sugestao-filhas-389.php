<?php
declare(strict_types=1);

/*
 * Teste 389 (somente leitura): os critérios de "possíveis filhas" funcionam nos vínculos pai/filha que já existem?
 * Critérios: 1 cita a lei pai (número/ano no texto), 2 mesmo assunto, 3 hierarquia de tipo e data, 4 âmbito, 5 palavras-chave.
 * Não grava nada. Lê só com sp_crud_select.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-sugestao-filhas-389.php
 * Grava o resultado também em storage\teste-sugestao-filhas-389.json
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
$todas = static function (string $tabela) use ($paginas): array { $o = []; $paginas($tabela, function ($r) use (&$o) { $o[] = $r; }); return $o; };
$semAcento = static function (string $t): string {
    $t = mb_strtolower($t, 'UTF-8');
    $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    return $c === false ? $t : $c;
};
$pct = static fn(int $a, int $b): string => $b ? number_format(100 * $a / $b, 1, ',', '') . '%' : '-';
$mediana = static function (array $v) { if (!$v) return 0; sort($v); return $v[intdiv(count($v), 2)]; };

// hierarquia por palavra do tipo (menor = mais alto)
$rankTipo = static function (string $t) use ($semAcento): int {
    $t = $semAcento($t);
    return match (true) {
        str_contains($t, 'constitui') => 0,
        str_contains($t, 'complementar') => 1,
        str_contains($t, 'decreto-lei'), str_contains($t, 'decreto lei') => 2,
        str_starts_with($t, 'lei'), str_contains($t, 'medida provis') => 2,
        str_contains($t, 'decreto') => 3,
        default => 4, // portaria, resolução, instrução normativa, norma, deliberação...
    };
};
$rankAmbito = static function (string $a) use ($semAcento): int {
    $a = $semAcento($a);
    return match (true) { str_contains($a, 'federal'), str_contains($a, 'nacional') => 0, str_contains($a, 'estad') => 1, str_contains($a, 'munic') => 2, default => 3 };
};

$rel = ['quando' => date('Y-m-d H:i:s')];

// --- catálogos
$tipos = []; foreach ($todas('tipos') as $r) $tipos[(int)$r['codtipo']] = trim((string)($r['dsctipo'] ?? ''));
$ambitos = []; foreach ($todas('ambitos') as $r) $ambitos[(int)$r['codambito']] = trim((string)($r['dscambito'] ?? ''));
$rel['tipos'] = array_count_values(array_map(static fn($t) => $t, $tipos));
$rel['ambitos'] = array_values($ambitos);

// --- leis (1ª passada: só metadados)
$lei = []; $colunas = null;
$paginas('legislacao', function ($r) use (&$lei, &$colunas, $tipos, $ambitos) {
    if ($colunas === null) $colunas = array_keys($r);
    $lei[(int)$r['codlegislacao']] = [
        'tipo' => $tipos[(int)($r['codtipo'] ?? 0)] ?? '', 'amb' => $ambitos[(int)($r['codambito'] ?? 0)] ?? '',
        'nr' => preg_replace('/\D+/', '', (string)($r['nr'] ?? '')), 'ano' => (int)($r['ano'] ?? 0),
    ];
});
$rel['colunas_legislacao'] = $colunas; // para descobrir se há UF / município
$rel['total_leis'] = count($lei);

// --- vínculos existentes (só pares válidos e sem duplicata)
$pares = []; $filhasDe = [];
foreach ($todas('legislacaopaixlegislacaofilha') as $r) {
    $p = (int)$r['codlegislacao_pai']; $f = (int)$r['codlegislacao_filha'];
    if ($p === $f || !isset($lei[$p], $lei[$f]) || isset($pares["$p-$f"])) continue;
    $pares["$p-$f"] = [$p, $f]; $filhasDe[$p][$f] = true;
}
$rel['vinculos'] = count($pares); $rel['pais'] = count($filhasDe);

// --- assuntos e palavras
$assuntos = []; foreach ($todas('legislacaoxassunto') as $r) $assuntos[(int)$r['codlegislacao']][(int)$r['codassunto']] = true;
$palavras = []; foreach ($todas('legislacaoxpalavraschave') as $r) $palavras[(int)$r['codlegislacao']][(int)$r['codpalavrachave']] = true;
$rel['leis_com_assunto'] = count($assuntos); $rel['leis_com_palavras'] = count($palavras);

// --- 2ª passada: quem cita cada lei pai (número com e sem ano)
$padroes = [];
foreach (array_keys($filhasDe) as $p) {
    $nr = ltrim($lei[$p]['nr'], '0');
    if ($nr === '') continue;
    $padroes[$p] = ['nr' => '/(?<!\d)0*' . $nr . '(?!\d)/', 'ano' => $lei[$p]['ano']];
}
$cita = []; // [pai][lei] = 2 número+ano, 1 só número com o tipo do pai perto
$paginas('legislacao', function ($r) use (&$cita, $padroes, $lei, $semAcento) {
    $id = (int)$r['codlegislacao'];
    $txt = (string)($r['padrao'] ?? '') . ' ' . (string)($r['ementa'] ?? '');
    if (trim($txt) === '') return;
    // junta números com ponto (12.305 -> 12305) e normaliza
    $t = preg_replace('/(\d)\.(?=\d{3}(?!\d))/', '$1', $semAcento(strip_tags($txt)));
    foreach ($padroes as $p => $pd) {
        if ($p === $id) continue;
        if (!preg_match_all($pd['nr'], $t, $m, PREG_OFFSET_CAPTURE)) continue;
        $nivel = 0;
        foreach ($m[0] as [$x, $pos]) {
            $volta = substr($t, max(0, $pos - 40), 40); $frente = substr($t, $pos, 25);
            $temAno = $pd['ano'] && (str_contains($frente, (string)$pd['ano']) || str_contains($frente, '/' . substr((string)$pd['ano'], 2)));
            $temTipo = (bool)preg_match('/(lei|decreto|portaria|resolu|instru|norma|n[ºo°.]|numero)/', $volta);
            if ($temAno && $temTipo) { $nivel = 2; break; }
            if ($temTipo) $nivel = max($nivel, 1);
        }
        if ($nivel) $cita[$p][$id] = $nivel;
    }
});

// --- 1) quanto cada critério acerta nos vínculos existentes
$c = ['cita_numero_e_ano' => 0, 'cita_numero' => 0, 'mesmo_assunto' => 0, 'pai_sem_assunto' => 0, 'filha_sem_assunto' => 0,
      'tipo_igual_ou_abaixo' => 0, 'data_igual_ou_depois' => 0, 'ambito_igual_ou_abaixo' => 0, 'palavras_em_comum' => 0, 'nenhum_dos_fortes' => 0];
$parTipo = []; $parAmbito = []; $semNada = [];
foreach ($pares as [$p, $f]) {
    $ct = $cita[$p][$f] ?? 0;
    if ($ct === 2) $c['cita_numero_e_ano']++;
    if ($ct >= 1) $c['cita_numero']++;
    $as = isset($assuntos[$p], $assuntos[$f]) && array_intersect_key($assuntos[$p], $assuntos[$f]);
    if ($as) $c['mesmo_assunto']++;
    if (!isset($assuntos[$p])) $c['pai_sem_assunto']++;
    if (!isset($assuntos[$f])) $c['filha_sem_assunto']++;
    if ($rankTipo($lei[$f]['tipo']) >= $rankTipo($lei[$p]['tipo'])) $c['tipo_igual_ou_abaixo']++;
    if (!$lei[$p]['ano'] || !$lei[$f]['ano'] || $lei[$f]['ano'] >= $lei[$p]['ano']) $c['data_igual_ou_depois']++;
    if ($rankAmbito($lei[$f]['amb']) >= $rankAmbito($lei[$p]['amb'])) $c['ambito_igual_ou_abaixo']++;
    $pl = isset($palavras[$p], $palavras[$f]) && array_intersect_key($palavras[$p], $palavras[$f]);
    if ($pl) $c['palavras_em_comum']++;
    if (!$ct && !$as && !$pl) { $c['nenhum_dos_fortes']++; if (count($semNada) < 15) $semNada[] = "$p ({$lei[$p]['tipo']} {$lei[$p]['nr']}/{$lei[$p]['ano']}) -> $f ({$lei[$f]['tipo']} {$lei[$f]['nr']}/{$lei[$f]['ano']})"; }
    $k = $lei[$p]['tipo'] . ' -> ' . $lei[$f]['tipo']; $parTipo[$k] = ($parTipo[$k] ?? 0) + 1;
    $k = $lei[$p]['amb'] . ' -> ' . $lei[$f]['amb']; $parAmbito[$k] = ($parAmbito[$k] ?? 0) + 1;
}
$n = count($pares);
foreach ($c as $k => $v) $rel['criterios'][$k] = "$v de $n (" . $pct($v, $n) . ')';
arsort($parTipo); arsort($parAmbito);
$rel['pai_filha_por_tipo'] = array_slice($parTipo, 0, 25, true);
$rel['pai_filha_por_ambito'] = $parAmbito;
$rel['exemplos_sem_criterio_forte'] = $semNada;

// --- 2) simulação da lista "Sugeridas": pontua todas as leis para cada pai e vê onde as filhas reais ficam
$pontos = static function (int $p, int $x) use ($cita, $assuntos, $palavras, $lei, $rankTipo, $rankAmbito): int {
    $s = 0; $forte = false;
    $ct = $cita[$p][$x] ?? 0; if ($ct) { $s += $ct === 2 ? 60 : 40; $forte = true; }
    if (isset($assuntos[$p], $assuntos[$x]) && ($q = count(array_intersect_key($assuntos[$p], $assuntos[$x])))) { $s += 20 + min(10, 2 * ($q - 1)); $forte = true; }
    if (isset($palavras[$p], $palavras[$x]) && ($q = count(array_intersect_key($palavras[$p], $palavras[$x])))) { $s += min(15, 5 * $q); $forte = true; }
    if (!$forte) return 0; // sem critério forte não entra em Sugeridas
    if ($rankTipo($lei[$x]['tipo']) >= $rankTipo($lei[$p]['tipo'])) $s += 8;
    if (!$lei[$p]['ano'] || !$lei[$x]['ano'] || $lei[$x]['ano'] >= $lei[$p]['ano']) $s += 6;
    if ($rankAmbito($lei[$x]['amb']) >= $rankAmbito($lei[$p]['amb'])) $s += 6;
    return $s;
};
$naSugerida = 0; $top25 = 0; $top100 = 0; $tamanhos = []; $posicoes = [];
foreach ($filhasDe as $p => $fs) {
    $sc = [];
    foreach ($lei as $x => $_) { if ($x === $p) continue; $v = $pontos($p, $x); if ($v > 0) $sc[$x] = $v; }
    arsort($sc); $tamanhos[] = count($sc);
    $pos = array_flip(array_keys($sc));
    foreach ($fs as $f => $_) {
        if (!isset($pos[$f])) continue;
        $naSugerida++; $r = $pos[$f] + 1; $posicoes[] = $r;
        if ($r <= 25) $top25++;
        if ($r <= 100) $top100++;
    }
}
$rel['simulacao'] = [
    'filhas_que_aparecem_em_sugeridas' => "$naSugerida de $n (" . $pct($naSugerida, $n) . ')',
    'filhas_na_1a_pagina_25' => "$top25 de $n (" . $pct($top25, $n) . ')',
    'filhas_nas_primeiras_100' => "$top100 de $n (" . $pct($top100, $n) . ')',
    'tamanho_da_lista_sugeridas_mediana' => $mediana($tamanhos),
    'tamanho_da_lista_sugeridas_maior' => $tamanhos ? max($tamanhos) : 0,
    'posicao_mediana_da_filha' => $mediana($posicoes),
];

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@mkdir(dirname(__DIR__) . '/storage', 0775, true);
file_put_contents(dirname(__DIR__) . '/storage/teste-sugestao-filhas-389.json', $json);
echo $json, PHP_EOL;

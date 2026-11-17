<?php
declare(strict_types=1);

/*
 * Teste 391 (somente leitura): critérios de "Sugeridas" para Palavras-chave e CheckList da lei, e qualidade dos catálogos.
 * Ideia: sugerir os itens usados por leis PARECIDAS. Para cada item já vinculado a uma lei, simula a sugestão sem essa lei
 * ("deixa um de fora") e mede se o item apareceria e em que posição.
 * Leis parecidas: família (pai, filhas, irmãs — mesmo pai), mesmo assunto, mesmo tipo + âmbito.
 * Comparação: lista dos mais usados no geral (sem olhar a lei).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-sugestao-catalogo-391.php
 * Resultado também em storage\teste-sugestao-catalogo-391.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
ini_set('memory_limit', '2048M');
@set_time_limit(0);

$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
foreach ([$leg, $uni] as $p) $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$paginas = static function (PDO $pdo, string $tabela, callable $cada): void {
    for ($o = 0; ; $o += 1000) {
        $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1000, ?)'); $s->execute([$tabela, '{}', $o]);
        $p = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        foreach ($p as $r) $cada(array_change_key_case($r, CASE_UPPER));
        $n = count($p); unset($p);
        if ($n < 1000) break;
    }
};
$pct = static fn(int $a, int $b): string => $b ? number_format(100 * $a / $b, 1, ',', '') . '%' : '-';
$mediana = static function (array $v) { if (!$v) return 0; sort($v); return $v[intdiv(count($v), 2)]; };
$chave = static fn(string $t): string => strtr(mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $t)), 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);

// --- leis (só metadados), família e assuntos
$grupo = []; $porGrupo = [];
$paginas($leg, 'legislacao', function ($r) use (&$grupo, &$porGrupo) { $id = (int)$r['CODLEGISLACAO']; $g = (int)($r['CODTIPO'] ?? 0) . '|' . (int)($r['CODAMBITO'] ?? 0); $grupo[$id] = $g; $porGrupo[$g][] = $id; });
$familia = []; $filhasDe = [];
$paginas($leg, 'legislacaopaixlegislacaofilha', function ($r) use (&$familia, &$filhasDe) { $p = (int)$r['CODLEGISLACAO_PAI']; $f = (int)$r['CODLEGISLACAO_FILHA']; if ($p === $f) return; $familia[$p][$f] = true; $familia[$f][$p] = true; $filhasDe[$p][$f] = true; });
foreach ($filhasDe as $p => $fs) foreach ($fs as $a => $_) foreach ($fs as $b => $_2) if ($a !== $b) $familia[$a][$b] = true; // irmãs
$assuntosDe = []; $leisDoAssunto = [];
$paginas($leg, 'legislacaoxassunto', function ($r) use (&$assuntosDe, &$leisDoAssunto) { $l = (int)$r['CODLEGISLACAO']; $a = (int)$r['CODASSUNTO']; $assuntosDe[$l][$a] = true; $leisDoAssunto[$a][$l] = true; });

$rel = ['quando' => date('Y-m-d H:i:s')];

$avaliar = function (string $nome, array $itensDe, array $texto) use ($grupo, $porGrupo, $familia, $assuntosDe, $leisDoAssunto, $pct, $mediana, $chave): array {
    // contagens por grupo e por assunto (para tirar a própria lei depois)
    $usoGrupo = []; $usoAssunto = []; $usoGeral = [];
    foreach ($itensDe as $l => $its) {
        $g = $grupo[$l] ?? '';
        foreach ($its as $i => $_) {
            $usoGeral[$i] = ($usoGeral[$i] ?? 0) + 1;
            if ($g !== '') $usoGrupo[$g][$i] = ($usoGrupo[$g][$i] ?? 0) + 1;
            foreach ($assuntosDe[$l] ?? [] as $a => $_a) $usoAssunto[$a][$i] = ($usoAssunto[$a][$i] ?? 0) + 1;
        }
    }
    arsort($usoGeral); $rankGeral = array_flip(array_keys($usoGeral));
    $crit = ['familia' => 0, 'assunto' => 0, 'tipo_ambito' => 0, 'algum' => 0];
    $top25 = 0; $top100 = 0; $tam = []; $pos = []; $geral25 = 0; $n = 0; $leisAval = 0;
    foreach ($itensDe as $l => $its) {
        // amostra: até 3.000 leis para não demorar
        if ($leisAval >= 3000) break;
        $leisAval++;
        $score = []; $fonte = [];
        foreach ($familia[$l] ?? [] as $m => $_) foreach ($itensDe[$m] ?? [] as $i => $_i) { $score[$i] = ($score[$i] ?? 0) + 50; $fonte[$i]['familia'] = true; }
        foreach ($assuntosDe[$l] ?? [] as $a => $_) foreach ($usoAssunto[$a] ?? [] as $i => $q) {
            $q -= isset($its[$i]) ? 1 : 0; // tira a própria lei
            if ($q > 0) { $score[$i] = ($score[$i] ?? 0) + 10 * min($q, 20); $fonte[$i]['assunto'] = true; }
        }
        $g = $grupo[$l] ?? '';
        foreach ($usoGrupo[$g] ?? [] as $i => $q) {
            $q -= isset($its[$i]) ? 1 : 0;
            if ($q > 0) { $score[$i] = ($score[$i] ?? 0) + min($q, 50); $fonte[$i]['tipo_ambito'] = true; }
        }
        arsort($score); $p = array_flip(array_keys($score)); $tam[] = count($score);
        foreach ($its as $i => $_) {
            $n++;
            foreach (['familia', 'assunto', 'tipo_ambito'] as $c) if (!empty($fonte[$i][$c])) $crit[$c]++;
            if (isset($score[$i])) { $crit['algum']++; $r = $p[$i] + 1; $pos[] = $r; if ($r <= 25) $top25++; if ($r <= 100) $top100++; }
            if (($rankGeral[$i] ?? 1e9) < 25) $geral25++;
        }
    }
    // qualidade do catálogo
    $semUso = count(array_diff_key($texto, $usoGeral));
    $porChave = []; foreach ($texto as $i => $t) $porChave[$chave((string)preg_replace('/\d+$/', '', $t))][] = $i;
    $repetidas = array_filter($porChave, static fn($v) => count($v) > 1);
    $exRep = []; foreach (array_slice($repetidas, 0, 15, true) as $v) $exRep[] = implode(' | ', array_map(static fn($i) => '#' . $i . ' ' . mb_substr($texto[$i] ?? '', 0, 50), array_slice($v, 0, 4)));
    $maisUsadas = []; foreach (array_slice($usoGeral, 0, 10, true) as $i => $q) $maisUsadas[] = mb_substr($texto[$i] ?? ('#' . $i), 0, 60) . ' — ' . $q . ' leis';
    return [
        'leis_com_itens' => count($itensDe), 'leis_avaliadas' => $leisAval, 'vinculos_avaliados' => $n,
        'item_aparece_por' => ['familia' => $pct($crit['familia'], $n), 'mesmo_assunto' => $pct($crit['assunto'], $n), 'mesmo_tipo_e_ambito' => $pct($crit['tipo_ambito'], $n), 'algum_criterio' => $pct($crit['algum'], $n)],
        'sugeridas_combinadas' => ['na_1a_pagina_25' => $pct($top25, $n), 'nas_100_primeiras' => $pct($top100, $n), 'posicao_mediana' => $mediana($pos), 'tamanho_lista_mediana' => $mediana($tam), 'tamanho_lista_maior' => $tam ? max($tam) : 0],
        'comparacao_25_mais_usados_no_geral' => $pct($geral25, $n),
        'catalogo' => ['itens' => count($texto), 'sem_uso_em_nenhuma_lei' => $semUso, 'grupos_de_repetidas' => count($repetidas), 'exemplos_repetidas' => $exRep, 'mais_usadas' => $maisUsadas],
    ];
};

// --- palavras-chave
$palavrasDe = []; $paginas($leg, 'legislacaoxpalavraschave', function ($r) use (&$palavrasDe) { $palavrasDe[(int)$r['CODLEGISLACAO']][(int)$r['CODPALAVRACHAVE']] = true; });
$txtPal = []; $paginas($uni, 'palavras_chaves', function ($r) use (&$txtPal) { $txtPal[(int)$r['CODPALAVRACHAVE']] = (string)($r['DSCPALAVRACHAVE'] ?? ''); });
$t0 = microtime(true);
$rel['palavras_chave'] = $avaliar('palavras', $palavrasDe, $txtPal);
$rel['palavras_chave']['segundos'] = round(microtime(true) - $t0, 1);
unset($palavrasDe);

// --- checklist
$perguntasDe = []; $paginas($leg, 'legislacaoxpergunta', function ($r) use (&$perguntasDe) { $perguntasDe[(int)$r['CODLEGISLACAO']][(int)$r['CODPERGUNTA']] = true; });
$txtPer = []; $paginas($leg, 'perguntas', function ($r) use (&$txtPer) { $txtPer[(int)$r['CODPERGUNTA']] = (string)($r['DSCPERGUNTA'] ?? ''); });
$t0 = microtime(true);
$rel['checklist'] = $avaliar('perguntas', $perguntasDe, $txtPer);
$rel['checklist']['segundos'] = round(microtime(true) - $t0, 1);

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@mkdir(dirname(__DIR__) . '/storage', 0775, true);
file_put_contents(dirname(__DIR__) . '/storage/teste-sugestao-catalogo-391.json', $json);
echo $json, PHP_EOL;

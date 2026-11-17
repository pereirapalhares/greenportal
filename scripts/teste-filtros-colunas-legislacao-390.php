<?php
declare(strict_types=1);

/*
 * Teste 390 (somente leitura): filtros das COLUNAS do grid Manutenção Legal › Legislação.
 * 1) Cada filtro de coluna (sozinho, em pares e junto com Empresa / Unidade / Mês-Ano): o total da tela
 *    (sp_legislacao_count_ids, a mesma chamada da tela) bate com a conta feita aqui direto nas tabelas?
 * 2) Listas dos filtros (migração 390): os valores que a tela oferece (sp_legislacao_opcoes_ids + regra da tela)
 *    são exatamente os valores das leis que passam nos outros filtros?
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-filtros-colunas-legislacao-390.php
 * Resultado também em storage\teste-filtros-colunas-legislacao-390.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';
ini_set('memory_limit', '2048M');
@set_time_limit(0);
mt_srand(390);

$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$call = static function (string $sql, array $p = []) use ($leg): array {
    $s = $leg->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return $r;
};
$paginas = static function (string $tabela, callable $cada) use ($call): void {
    for ($o = 0; ; $o += 1000) {
        $p = $call('CALL sp_crud_select(?, ?, NULL, 1000, ?)', [$tabela, '{}', $o]);
        foreach ($p as $r) $cada(array_change_key_case($r, CASE_UPPER));
        $n = count($p); unset($p);
        if ($n < 1000) break;
    }
};
$norm = static fn($v): string => strtr(mb_strtolower(trim((string)$v), 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);

$SELECTS = ['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'SITUACAO_DA_LEI', 'DEVIDO_DA_LEI'];
$TEXTOS = ['CODIGO', 'NR', 'ANO', 'ARTIGOS', 'PADRAO', 'ASSUNTOS'];
$chaveFiltro = static fn(string $c): string => in_array($c, ['SITUACAO_DA_LEI', 'DEVIDO_DA_LEI'], true) ? strtolower($c) : $c; // nomes usados pela tela

// --- dados
$lei = [];
$paginas('legislacao', function ($r) use (&$lei, $SELECTS, $norm) {
    $x = [];
    foreach ($SELECTS as $c) $x[$c] = in_array($c, ['CODAMBITO', 'CODTIPO'], true) ? (string)(int)($r[$c] ?? 0) : $norm($r[$c] ?? '');
    foreach (['CODIGO', 'NR', 'ANO', 'ARTIGOS', 'PADRAO'] as $c) $x[$c] = $norm($r[$c] ?? '');
    $lei[(int)$r['CODLEGISLACAO']] = $x;
});
$nomeAssunto = []; $paginas('assuntos', function ($r) use (&$nomeAssunto, $norm) { $nomeAssunto[(int)$r['CODASSUNTO']] = $norm($r['DSCASSUNTO'] ?? ''); });
$paginas('legislacaoxassunto', function ($r) use (&$lei, $nomeAssunto) { $id = (int)$r['CODLEGISLACAO']; if (isset($lei[$id])) $lei[$id]['ASSUNTOS'] = ($lei[$id]['ASSUNTOS'] ?? '') . '|' . ($nomeAssunto[(int)$r['CODASSUNTO']] ?? ''); });
$porMes = []; $paginas('legislacaoxalteracao', function ($r) use (&$porMes) { if (!empty($r['DATA_DA_ALTERACAO'])) $porMes[substr((string)$r['DATA_DA_ALTERACAO'], 0, 7)][(int)$r['CODLEGISLACAO']] = true; });
krsort($porMes);
$rel = ['quando' => date('Y-m-d H:i:s'), 'leis' => count($lei)];

// --- contextos: sem filtro, Crown, Crown + 1ª unidade, Atlas, último mês com alteração
$contextos = ['sem filtro de empresa' => ['ids' => null, 'periodo' => null, 'f' => []]];
foreach ([23 => 'Crown', 108 => 'Atlas'] as $cod => $nome) {
    try {
        $contextos[$nome] = ['ids' => array_fill_keys(LegislacaoEmpresas::leis($cod, null), true), 'periodo' => null, 'f' => ['CODEMPRESA' => $cod]];
        $uni = array_key_first(LegislacaoEmpresas::unidades($cod));
        if ($uni !== null && $nome === 'Crown') $contextos["$nome + unidade $uni"] = ['ids' => array_fill_keys(LegislacaoEmpresas::leis($cod, (int)$uni), true), 'periodo' => null, 'f' => ['CODEMPRESA' => $cod, 'CODUNIDADE' => $uni]];
    } catch (Throwable $e) { $rel['avisos'][] = "$nome: " . $e->getMessage(); }
}
$mes = array_key_first($porMes);
if ($mes) $contextos["mês $mes"] = ['ids' => $porMes[$mes], 'periodo' => $mes, 'f' => ['MES_ANO_ATUALIZACAO' => $mes]];

// --- conta esperada (aqui) e conta da tela (procedure)
$passa = static function (array $l, array $filtros) use ($SELECTS): bool {
    foreach ($filtros as $c => $v) {
        if (in_array($c, $SELECTS, true)) { if ($l[$c] !== $v) return false; }
        elseif (!str_contains($l[$c] ?? '', $v)) return false;
    }
    return true;
};
$esperado = static function (array $ctx, array $filtros) use ($lei, $passa): int {
    $n = 0;
    foreach ($lei as $id => $l) { if ($ctx['ids'] !== null && !isset($ctx['ids'][$id])) continue; if ($passa($l, $filtros)) $n++; }
    return $n;
};
$tela = static function (array $ctx, array $filtrosTela) use ($call): int {
    $ids = $ctx['ids'] === null ? null : json_encode(array_keys($ctx['ids']));
    return (int)($call('CALL sp_legislacao_count_ids(?, ?, NULL, NULL, ?)', [$ids, $ctx['periodo'], json_encode($filtrosTela ?: new stdClass(), JSON_UNESCAPED_UNICODE)])[0]['total_registros'] ?? -1);
};
// valor original (como a tela manda) a partir da chave normalizada: pega de uma lei qualquer
$original = [];
$paginas('legislacao', function ($r) use (&$original, $SELECTS, $norm) { foreach ($SELECTS as $c) { $k = in_array($c, ['CODAMBITO', 'CODTIPO'], true) ? (string)(int)($r[$c] ?? 0) : $norm($r[$c] ?? ''); if ($k !== '' && !isset($original[$c][$k])) $original[$c][$k] = trim((string)($r[$c] ?? '')); } });

$casos = 0; $erros = [];
$testar = function (string $nomeCtx, array $ctx, array $filtrosNorm) use (&$casos, &$erros, $esperado, $tela, $original, $SELECTS, $chaveFiltro): void {
    $ft = [];
    foreach ($filtrosNorm as $c => $v) $ft[$chaveFiltro($c)] = in_array($c, $SELECTS, true) ? ($original[$c][$v] ?? $v) : $v;
    $e = $esperado($ctx, $filtrosNorm); $t = $tela($ctx, $ft); $casos++;
    if ($e !== $t) $erros[] = "$nomeCtx | " . json_encode($ft, JSON_UNESCAPED_UNICODE) . " | esperado $e, tela $t";
};

$t0 = microtime(true);
foreach ($contextos as $nomeCtx => $ctx) {
    // valores presentes neste contexto
    $presentes = [];
    foreach ($lei as $id => $l) { if ($ctx['ids'] !== null && !isset($ctx['ids'][$id])) continue; foreach ($SELECTS as $c) if ($l[$c] !== '' && $l[$c] !== '0') $presentes[$c][$l[$c]] = ($presentes[$c][$l[$c]] ?? 0) + 1; }
    // 1) cada coluna com lista: até 10 valores (os 6 mais frequentes + 4 ao acaso)
    foreach ($SELECTS as $c) {
        $vals = $presentes[$c] ?? []; arsort($vals); $k = array_keys($vals);
        $amostra = array_slice($k, 0, 6); $resto = array_slice($k, 6); shuffle($resto); $amostra = array_merge($amostra, array_slice($resto, 0, 4));
        foreach ($amostra as $v) $testar($nomeCtx, $ctx, [$c => (string)$v]);
    }
    // 2) colunas de texto: 4 trechos de leis ao acaso
    $idsCtx = $ctx['ids'] === null ? array_keys($lei) : array_values(array_filter(array_keys($ctx['ids']), static fn($id) => isset($lei[$id])));
    $rel['contextos'][$nomeCtx] = count($idsCtx) . ' leis';
    if (!$idsCtx) continue; // contexto sem leis: nada a filtrar
    foreach ($TEXTOS as $c) for ($i = 0; $i < 4; $i++) {
        $l = $lei[$idsCtx[array_rand($idsCtx)]] ?? null; if (!$l) continue;
        $palavras = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', (string)($l[$c] ?? '')) ?: [], static fn($w) => mb_strlen($w) >= 3));
        if ($palavras) $testar($nomeCtx, $ctx, [$c => $palavras[array_rand($palavras)]]);
    }
    // 3) pares: âmbito + tipo, âmbito + situação, tipo + meio ambiente (valores mais comuns)
    $top = static function (array $p, string $c): ?string { $v = $p[$c] ?? []; arsort($v); return $v ? (string)array_key_first($v) : null; };
    foreach ([['CODAMBITO', 'CODTIPO'], ['CODAMBITO', 'SITUACAO_DA_LEI'], ['CODTIPO', 'MEIO_AMBIENTE'], ['SITUACAO_DA_LEI', 'DEVIDO_DA_LEI']] as [$a, $b]) {
        $va = $top($presentes, $a); $vb = $top($presentes, $b);
        if ($va !== null && $vb !== null) $testar($nomeCtx, $ctx, [$a => $va, $b => $vb]);
    }
}
$rel['totais'] = ['casos' => $casos, 'divergencias' => count($erros), 'segundos' => round(microtime(true) - $t0, 1)];
$rel['divergencias'] = array_slice($erros, 0, 40);

// --- listas dos filtros (migração 390): procedure + regra da tela × conta direta
$opcoesTela = function (array $ctx, array $filtrosNorm) use ($call, $SELECTS, $norm, $original, $chaveFiltro): ?array {
    $base = []; $want = [];
    foreach ($filtrosNorm as $c => $v) { if (in_array($c, $SELECTS, true)) $want[$c] = $v; else $base[$chaveFiltro($c)] = $v; }
    try { $g = $call('CALL sp_legislacao_opcoes_ids(?, ?, NULL, NULL, ?)', [$ctx['ids'] === null ? null : json_encode(array_keys($ctx['ids'])), $ctx['periodo'], json_encode($base ?: new stdClass(), JSON_UNESCAPED_UNICODE)]); }
    catch (Throwable $e) { return null; }
    $out = [];
    foreach ($g as $r) {
        $r = array_change_key_case($r, CASE_UPPER); $k = [];
        foreach ($SELECTS as $c) $k[$c] = in_array($c, ['CODAMBITO', 'CODTIPO'], true) ? (string)(int)($r[$c] ?? 0) : $norm($r[$c] ?? '');
        $miss = []; foreach ($want as $c => $v) if ($k[$c] !== $v) $miss[] = $c;
        if (count($miss) > 1) continue;
        foreach ($SELECTS as $c) { if ($k[$c] === '' || $k[$c] === '0') continue; if ($miss && $miss[0] !== $c) continue; $out[$c][$k[$c]] = true; }
    }
    return $out;
};
$opcoesEsperadas = function (array $ctx, array $filtrosNorm) use ($lei, $passa, $SELECTS): array {
    $out = [];
    foreach ($lei as $id => $l) {
        if ($ctx['ids'] !== null && !isset($ctx['ids'][$id])) continue;
        foreach ($SELECTS as $c) {
            if ($l[$c] === '' || $l[$c] === '0') continue;
            $outros = $filtrosNorm; unset($outros[$c]);
            if ($passa($l, $outros)) $out[$c][$l[$c]] = true;
        }
    }
    return $out;
};
$catalogo = ['CODAMBITO' => count($call('CALL sp_crud_select(?, ?, NULL, 1000, 0)', ['ambitos', '{}'])), 'CODTIPO' => count($original['CODTIPO'] ?? [])];
$okOpcoes = 0; $errOpcoes = [];
foreach ($contextos as $nomeCtx => $ctx) {
    $presentes = [];
    foreach ($lei as $id => $l) { if ($ctx['ids'] !== null && !isset($ctx['ids'][$id])) continue; foreach (['CODAMBITO', 'CODTIPO', 'SITUACAO_DA_LEI'] as $c) if ($l[$c] !== '') $presentes[$c][$l[$c]] = ($presentes[$c][$l[$c]] ?? 0) + 1; }
    $cenarios = ['sem filtro de coluna' => []];
    foreach (['CODAMBITO', 'CODTIPO', 'SITUACAO_DA_LEI'] as $c) { $v = $presentes[$c] ?? []; arsort($v); if ($v) $cenarios["$c=" . array_key_first($v)] = [$c => (string)array_key_first($v)]; }
    if (!empty($presentes['CODAMBITO']) && !empty($presentes['CODTIPO'])) {
        $amb = $presentes['CODAMBITO']; arsort($amb); $tip = $presentes['CODTIPO']; arsort($tip);
        $cenarios['CODAMBITO=' . array_key_first($amb) . ' + CODTIPO=' . array_key_first($tip)] = ['CODAMBITO' => (string)array_key_first($amb), 'CODTIPO' => (string)array_key_first($tip)];
    }
    $cenarios['ASSUNTOS=agua'] = ['ASSUNTOS' => 'agua'];
    foreach ($cenarios as $nomeCen => $f) {
        $tel = $opcoesTela($ctx, $f);
        if ($tel === null) { $rel['listas'] = 'sp_legislacao_opcoes_ids não existe — rode a migração 390 e repita'; break 2; }
        $esp = $opcoesEsperadas($ctx, $f);
        $linha = [];
        foreach ($SELECTS as $c) {
            $a = array_keys($tel[$c] ?? []); $b = array_keys($esp[$c] ?? []); sort($a); sort($b);
            if ($a === $b) $okOpcoes++; else $errOpcoes[] = "$nomeCtx | $nomeCen | $c: tela " . count($a) . ', esperado ' . count($b) . ' (faltam ' . implode(',', array_slice(array_diff($b, $a), 0, 5)) . '; sobram ' . implode(',', array_slice(array_diff($a, $b), 0, 5)) . ')';
            if (in_array($c, ['CODAMBITO', 'CODTIPO'], true)) $linha[$c] = count($a);
        }
        $rel['listas_tamanho']["$nomeCtx | $nomeCen"] = 'âmbitos ' . $linha['CODAMBITO'] . ' de ' . $catalogo['CODAMBITO'] . ', tipos ' . $linha['CODTIPO'];
    }
}
$rel['listas'] ??= ['conferidas' => $okOpcoes + count($errOpcoes), 'divergencias' => count($errOpcoes)];
$rel['listas_divergencias'] = array_slice($errOpcoes, 0, 30);

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@mkdir(dirname(__DIR__) . '/storage', 0775, true);
file_put_contents(dirname(__DIR__) . '/storage/teste-filtros-colunas-legislacao-390.json', $json);
echo $json, PHP_EOL;

<?php
declare(strict_types=1);

/*
 * Migração 392 — limpeza dos catálogos repetidos (aprovada pelo usuário em 17/11/2026), depois do sincronismo por operações
 * (393), que não desfaz o que é feito no greenportal.
 *   - Perguntas do checklist com o MESMO texto (diferença só de espaços/maiúsculas/acentos): fica a mais usada (empate: menor
 *     código), com o texto sem espaços repetidos; os vínculos das leis (glegislacao_dbp.legislacaoxpergunta) e as respostas do
 *     checklist das empresas (legislacaoxchecklist) passam para ela; as outras saem do catálogo.
 *   - Palavra-chave: só "cadastro na AMLURB" (#522) → "cadastro na  AMLURB" (#154), texto corrigido para um espaço. As
 *     numeradas ("obra2", "qualidade1"...) NÃO são juntadas: estão em tiposxpalavras_chaves e parecem intencionais.
 *   Vínculos das palavras: glegislacao_dbp.legislacaoxpalavraschave e tiposxpalavras_chaves e, em cada empresa migrada,
 *   todas as tabelas *xpalavraschave (licenças, documentos, homologação, treinamentos...), menos as de backup (bkp*).
 *   Se o registro de destino já existir (mesmo dono), o vínculo repetido é apagado em vez de movido.
 * Sem --aplicar: só mostra. Com --aplicar: grava pelas procedures sp_crud_* e guarda tudo o que mudou em
 * database\backups\392_limpeza_catalogos_<data>.json.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-limpeza-catalogos-392.php [--aplicar]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';
ini_set('memory_limit', '2048M');
@set_time_limit(0);

$aplicar = in_array('--aplicar', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$bancos = ['glegislacao_dbp' => $leg, 'guniversal_dbp' => $uni];
foreach (LegislacaoEmpresas::migradas() as $cod => $e) { try { $bancos[(string)$e['banco']] = LegislacaoEmpresas::pdo((int)$cod); } catch (Throwable) {} }
foreach ($bancos as $p) $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$call = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return $r;
};
$todas = static function (PDO $pdo, string $tabela, array $f = []) use ($call): array {
    $out = [];
    for ($o = 0; ; $o += 1000) { $p = $call($pdo, 'CALL sp_crud_select(?, ?, NULL, 1000, ?)', [$tabela, json_encode($f ?: new stdClass()), $o]); array_push($out, ...$p); if (count($p) < 1000) break; }
    return $out;
};
$pk = static function (PDO $pdo, string $tabela): array {
    $s = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
    $s->execute([$tabela]); return $s->fetchAll(PDO::FETCH_COLUMN);
};
$norm = static fn(string $t): string => strtr(mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $t)), 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
$espacos = static fn(string $t): string => trim((string)preg_replace('/[ \t]{2,}/u', ' ', $t));

$rel = ['quando' => date('Y-m-d H:i:s'), 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO (nada foi gravado; use --aplicar)'];
$backup = [];
// cópia do que mudou gravada sempre no fim do --aplicar (mesmo se der erro no meio)
$arqBackup = dirname(__DIR__) . '/database/backups/392_limpeza_catalogos_' . date('Ymd_His') . '.json';
if ($aplicar) register_shutdown_function(static function () use (&$backup, $arqBackup) { if (!$backup) return; @mkdir(dirname($arqBackup), 0775, true); file_put_contents($arqBackup, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); });

/**
 * Move as referências de $de para $para na coluna $col da tabela; se o mesmo "dono" (colunas cod* da linha, fora a chave e
 * a própria coluna) já tem $para, apaga a linha repetida. Devolve [movidas, apagadas].
 */
$mover = function (string $banco, string $tabela, string $col, int $de, int $para) use (&$backup, $bancos, $call, $todas, $pk, $aplicar): array {
    $pdo = $bancos[$banco]; $chave = $pk($pdo, $tabela);
    $linhas = $todas($pdo, $tabela, [$col => $de]);
    $mov = 0; $apag = 0;
    foreach ($linhas as $r) {
        if ((int)($r[$col] ?? 0) !== $de) continue; // segurança (filtro numérico é igual)
        $dono = [];
        foreach ($r as $c => $v) if (strcasecmp($c, $col) !== 0 && !in_array($c, $chave, true) && stripos($c, 'cod') === 0 && $v !== null) $dono[$c] = $v;
        $ja = array_filter($todas($pdo, $tabela, $dono + [$col => $para]), static function ($x) use ($dono, $col, $para) { foreach ($dono as $c => $v) if ((string)$x[$c] !== (string)$v) return false; return (int)$x[$col] === $para; });
        $k = $chave ? array_intersect_key($r, array_flip($chave)) : array_filter($r, static fn($v) => $v !== null);
        $backup[] = ['banco' => $banco, 'tabela' => $tabela, 'acao' => $ja ? 'apagada (já tinha o destino)' : "movida $de -> $para", 'linha' => $r];
        if ($ja) { if ($aplicar) $call($pdo, 'CALL sp_crud_delete(?, ?)', [$tabela, json_encode($k, JSON_UNESCAPED_UNICODE)]); $apag++; }
        else { if ($aplicar) $call($pdo, 'CALL sp_crud_update(?, ?, ?)', [$tabela, json_encode($k, JSON_UNESCAPED_UNICODE), json_encode([$col => $para])]); $mov++; }
    }
    return [$mov, $apag];
};

// ---------- perguntas
$per = []; foreach ($todas($leg, 'perguntas') as $r) $per[(int)$r['CODPERGUNTA']] = (string)($r['DSCPERGUNTA'] ?? '');
$uso = []; foreach ($todas($leg, 'legislacaoxpergunta') as $r) $uso[(int)$r['CODPERGUNTA']] = ($uso[(int)$r['CODPERGUNTA']] ?? 0) + 1;
$grupos = [];
foreach ($per as $c => $t) $grupos[$norm($t)][] = $c;
$grupos = array_filter($grupos, static fn($v) => count($v) > 1);
$tabsChecklist = [];
foreach ($bancos as $b => $pdo) { if ($b === 'glegislacao_dbp' || $b === 'guniversal_dbp') continue; $s = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaoxchecklist'"); if ((int)$s->fetchColumn()) $tabsChecklist[] = $b; }
$rel['perguntas'] = ['grupos' => count($grupos), 'detalhe' => []];
foreach ($grupos as $cods) {
    usort($cods, static fn($a, $b) => [($uso[$b] ?? 0), -$b] <=> [($uso[$a] ?? 0), -$a]);
    $fica = array_shift($cods);
    $linha = ['fica' => "#$fica", 'texto' => mb_substr($espacos($per[$fica]), 0, 90), 'sai' => []];
    foreach ($cods as $c) {
        [$m1, $a1] = $mover('glegislacao_dbp', 'legislacaoxpergunta', 'CODPERGUNTA', $c, $fica);
        $m2 = 0; $a2 = 0; foreach ($tabsChecklist as $b) { [$x, $y] = $mover($b, 'legislacaoxchecklist', 'codpergunta', $c, $fica); $m2 += $x; $a2 += $y; }
        $backup[] = ['banco' => 'glegislacao_dbp', 'tabela' => 'perguntas', 'acao' => 'excluida (repetida de #' . $fica . ')', 'linha' => ['CODPERGUNTA' => $c, 'DSCPERGUNTA' => $per[$c]]];
        if ($aplicar) $call($leg, 'CALL sp_crud_delete(?, ?)', ['perguntas', json_encode(['CODPERGUNTA' => $c])]);
        $linha['sai'][] = "#$c — vínculos de leis: $m1 movidos, $a1 repetidos apagados; respostas de checklist: $m2 movidas, $a2 repetidas apagadas";
    }
    if ($espacos($per[$fica]) !== $per[$fica]) {
        $backup[] = ['banco' => 'glegislacao_dbp', 'tabela' => 'perguntas', 'acao' => 'texto sem espaços repetidos', 'linha' => ['CODPERGUNTA' => $fica, 'DSCPERGUNTA' => $per[$fica]]];
        if ($aplicar) $call($leg, 'CALL sp_crud_update(?, ?, ?)', ['perguntas', json_encode(['CODPERGUNTA' => $fica]), json_encode(['DSCPERGUNTA' => $espacos($per[$fica])], JSON_UNESCAPED_UNICODE)]);
        $linha['texto_corrigido'] = true;
    }
    $rel['perguntas']['detalhe'][] = $linha;
}

// ---------- palavra-chave "cadastro na AMLURB" (#522 → #154)
$pal = []; foreach ($todas($uni, 'palavras_chaves') as $r) $pal[(int)$r['codpalavrachave']] = (string)($r['dscpalavrachave'] ?? '');
[$de, $para] = [522, 154];
if (!isset($pal[$de], $pal[$para]) || $norm($pal[$de]) !== $norm($pal[$para])) {
    $rel['palavra_amlurb'] = 'não aplicada: #522/#154 não são mais a mesma palavra (' . ($pal[$de] ?? '?') . ' / ' . ($pal[$para] ?? '?') . ')';
} else {
    $tabs = [['glegislacao_dbp', 'legislacaoxpalavraschave'], ['glegislacao_dbp', 'tiposxpalavras_chaves']];
    foreach ($bancos as $b => $pdo) {
        if ($b === 'glegislacao_dbp' || $b === 'guniversal_dbp') continue;
        $s = $pdo->query("SELECT t.TABLE_NAME FROM information_schema.TABLES t JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND LOWER(c.COLUMN_NAME) = 'codpalavrachave' WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND t.TABLE_NAME NOT LIKE 'bkp%'");
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $t) $tabs[] = [$b, (string)$t];
    }
    $det = [];
    foreach ($tabs as [$b, $t]) {
        $colReal = 'codpalavrachave';
        $s = $bancos[$b]->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = 'codpalavrachave'"); $s->execute([$t]); $colReal = (string)($s->fetchColumn() ?: 'codpalavrachave');
        try { [$m, $a] = $mover($b, $t, $colReal, $de, $para); if ($m || $a) $det[] = "$b.$t: $m movidos, $a repetidos apagados"; }
        catch (Throwable $e) { $det[] = "$b.$t: ERRO " . $e->getMessage(); }
    }
    $backup[] = ['banco' => 'guniversal_dbp', 'tabela' => 'palavras_chaves', 'acao' => 'excluida (repetida de #154) e #154 com um espaço', 'linha' => ['de' => [$de => $pal[$de]], 'para' => [$para => $pal[$para]]]];
    if ($aplicar) {
        $call($uni, 'CALL sp_crud_update(?, ?, ?)', ['palavras_chaves', json_encode(['codpalavrachave' => $para]), json_encode(['dscpalavrachave' => $espacos($pal[$para])], JSON_UNESCAPED_UNICODE)]);
        $call($uni, 'CALL sp_crud_delete(?, ?)', ['palavras_chaves', json_encode(['codpalavrachave' => $de])]);
    }
    $rel['palavra_amlurb'] = ['fica' => "#$para \"" . $espacos($pal[$para]) . '"', 'sai' => "#$de", 'vinculos' => $det ?: ['nenhum vínculo encontrado']];
}

$rel['linhas_alteradas_ou_apagadas'] = count($backup);
if ($aplicar) $rel['backup'] = $arqBackup;
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

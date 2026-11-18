<?php
declare(strict_types=1);

/*
 * Auditoria de idiomas (só leitura) — 18/11/2026. Mede o que já está em PT/EN/ES e o que ainda está só em português:
 *   banco (guniversal_dbp): textos de Textos e idiomas, nomes dos menus, Ajuda das transações, Guia do usuário;
 *   código: em cada tela (principal), componente, classe e JavaScript, textos em português escritos direto no código
 *   (fora de IdiomaService::t/f/textos, greenT e dos arrays ['pt' => …, 'en' => …]).
 * A contagem do código é uma estimativa (heurística por tokens): serve para priorizar, não é lista exata.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\auditoria-idiomas.php      (grava storage\logs\auditoria_idiomas.json)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
@set_time_limit(600);
ini_set('memory_limit', '1024M');
$raiz = dirname(__DIR__);
$env = GreenPortalDatabase::env();
$out = ['quando' => date('Y-m-d H:i:s')];

// ------------------------------------------------------------------ texto em português?
$ptPalavras = '/\b(não|nao|você|para|com|sem|selecione|nenhum|nenhuma|salvar|excluir|incluir|editar|buscar|carregando|aguarde|erro|atenção|usuário|empresa|unidade|data|responsável|legislação|licença|treinamento|fornecedor|ocorrência|pendência|arquivo|documento|cadastr\w*|informe|clique|marque|ver|abrir|fechar|voltar|próxim\w*|anterior|todas|todos|sim|já|está|são|foi|será|deve|pode|tela|lista|campo|registro|grav\w*)\b/iu';
$ehEs = static function (string $s): bool {
    $t = ' ' . mb_strtolower(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')) . ' ';
    return (bool)preg_match('/[ñ¿¡]|ción\b|ciones\b|\b(el|los|las|del|usted|haga|pestaña|seleccione|guardar|eliminar|cuadrícula|búsqueda|registros|ningún|ninguna|aquí|también|está|sesión|archivo no)\b/u', $t)
        && !preg_match('/[ãõç]|\b(não|você|ção|ções)\b/u', $t);
};
$ehEn = static function (string $s): bool {
    $t = ' ' . mb_strtolower(strip_tags($s)) . ' ';
    return !preg_match('/[ãõçáéíóúâêô]/u', $t) && preg_match_all('/\b(the|and|to|of|for|with|this|you|your|is|are|not|no|select|save|delete|open|click|record|user)\b/', $t) >= 1;
};
$ehPt = static function (string $s) use ($ptPalavras, $ehEs): bool {
    if ($ehEs($s)) return false;
    $t = trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));
    if (mb_strlen($t) < 3 || !preg_match('/\p{L}{3}/u', $t)) return false;
    if (preg_match('/^(Location|Content-Type|Cache-Control)\s*:/i', $t)) return false;
    if (preg_match('/[{};]/', $t) && preg_match('/[:=(]/', $t) && !preg_match('/[.!?]\s*$/u', $t)) return false;   // CSS / código
    if (preg_match('/^[\s\w\-\[\]="\'.#>:,()*]+$/u', $t) && (preg_match('/[\[\]#>]/', $t) || preg_match('/^\./', $t)) && !preg_match('/[!?]$|\w\.$/u', $t)) return false; // seletores
    if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|CALL|SET|CREATE|ALTER|DROP|SHOW|WITH)\b/i', $t)) return false;
    if (preg_match('/^[a-z0-9_.\-\/:#\[\]\$\{\}=]+$/i', $t)) return false;             // chaves, caminhos, classes, ids
    if (preg_match('/^(bi|btn|form|col|d|text|bg|border|table|alert|badge|fw|fs|small|ms|me|mt|mb|p[xytblr]?|m[xytblr]?)[\s\-]/', $t)) return false; // classes CSS
    return (bool)preg_match('/[ãõçáéíóúâêôà]/iu', $t) || preg_match_all($ptPalavras, $t) >= 1 && str_contains($t, ' ');
};

// ------------------------------------------------------------------ 1) banco
$u = null;
try { $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL'); $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
catch (Throwable $e) { $out['banco'] = 'sem conexão: ' . $e->getMessage(); }
$q = static function (string $sql) use (&$u): array { if (!$u) throw new RuntimeException('sem conexão'); return $u->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
$vazio = static fn($v) => trim(strip_tags((string)$v)) === '';
$igual = static fn($a, $b) => trim((string)$a) !== '' && mb_strtolower(trim((string)$a)) === mb_strtolower(trim((string)$b)) && preg_match('/\p{L}{3}/u', (string)$a);
$ptNoTexto = static function (string $html): int { // palavras típicas do português num texto que deveria estar em EN/ES
    $t = ' ' . mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) . ' ';
    return preg_match_all('/\b(não|você|ção|ções|também|então|aqui|está|são|pelo|pela|uma|um|do|da|dos|das|no|na|nos|nas|ao|aos|à|às|seu|sua)\b/u', $t);
};

// 1a) Textos e idiomas
try {
    $txt = $q('SELECT chave, codgrupo, tipo, pt_publicado, en_publicado, es_publicado FROM vw_idioma_textos_admin');
    $r = ['total' => count($txt), 'sem_en' => 0, 'sem_es' => 0, 'en_igual_pt' => 0, 'es_igual_pt' => 0, 'por_grupo' => [], 'exemplos' => []];
    foreach ($txt as $t) {
        $g = &$r['por_grupo'][$t['codgrupo']];
        $g['total'] = ($g['total'] ?? 0) + 1;
        foreach (['en', 'es'] as $lg) {
            if ($vazio($t[$lg . '_publicado'])) { $r['sem_' . $lg]++; $g['sem_' . $lg] = ($g['sem_' . $lg] ?? 0) + 1; if (count($r['exemplos']) < 15) $r['exemplos'][] = $t['chave'] . ' (sem ' . $lg . ')'; }
            elseif ($igual($t[$lg . '_publicado'], $t['pt_publicado'])) { $r[$lg . '_igual_pt']++; $g[$lg . '_igual_pt'] = ($g[$lg . '_igual_pt'] ?? 0) + 1; }
        }
        unset($g);
    }
    uasort($r['por_grupo'], static fn($a, $b) => (($b['sem_en'] ?? 0) + ($b['en_igual_pt'] ?? 0)) <=> (($a['sem_en'] ?? 0) + ($a['en_igual_pt'] ?? 0)));
    $r['por_grupo'] = array_slice($r['por_grupo'], 0, 40, true);
    $out['textos_e_idiomas'] = $r;
} catch (Throwable $e) { $out['textos_e_idiomas'] = ['erro' => $e->getMessage()]; }

// 1b) Menus
$menus = [];
try {
    $menus = $q('SELECT codmenu, codigo, label_pt, label_en, label_es, url, ativo FROM menus');
    $r = ['total' => count($menus), 'sem_en' => [], 'sem_es' => [], 'en_igual_pt' => [], 'es_igual_pt' => []];
    foreach ($menus as $m) {
        if ((int)($m['ativo'] ?? 1) !== 1) continue;
        foreach (['en', 'es'] as $lg) {
            if ($vazio($m['label_' . $lg])) $r['sem_' . $lg][] = $m['codigo'] . ' — ' . $m['label_pt'];
            elseif ($igual($m['label_' . $lg], $m['label_pt'])) $r[$lg . '_igual_pt'][] = $m['codigo'] . ' — ' . $m['label_pt'];
        }
    }
    foreach (['sem_en', 'sem_es', 'en_igual_pt', 'es_igual_pt'] as $k) $r[$k] = ['qtd' => count($r[$k]), 'lista' => array_slice($r[$k], 0, 60)];
    $out['menus'] = $r;
} catch (Throwable $e) { $out['menus'] = ['erro' => $e->getMessage()]; }

// 1c) Ajuda das transações
$ajudas = [];
try {
    foreach ($q('SELECT transacao, titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo FROM ajuda_transacoes') as $a) $ajudas[strtolower($a['transacao'])] = $a;
    $r = ['total' => count($ajudas), 'sem_en' => [], 'sem_es' => [], 'en_com_portugues' => [], 'es_com_portugues' => [], 'en_incompleto' => [], 'es_incompleto' => [], 'blocos_so_pt' => []];
    foreach ($ajudas as $tr => $a) {
        $lenPt = mb_strlen(strip_tags((string)$a['texto_pt']));
        foreach (['en', 'es'] as $lg) {
            $tx = (string)$a['texto_' . $lg];
            if ($vazio($tx)) { $r['sem_' . $lg][] = $tr; continue; }
            if ($lg === 'en' && $ptNoTexto($tx) >= 8) $r['en_com_portugues'][] = $tr . ' (' . $ptNoTexto($tx) . ' palavras PT)';
            if ($lenPt > 200 && mb_strlen(strip_tags($tx)) < $lenPt * 0.5) $r[$lg . '_incompleto'][] = $tr . ' (' . mb_strlen(strip_tags($tx)) . ' de ' . $lenPt . ' caracteres)';
        }
        // trechos de migração (<!-- mlNNN -->) que existem no PT e faltam no EN/ES
        preg_match_all('/<!-- (ml\d+) -->/', (string)$a['texto_pt'], $m);
        foreach (array_unique($m[1]) as $bl) foreach (['en', 'es'] as $lg) if (!str_contains((string)$a['texto_' . $lg], '<!-- ' . $bl . ' -->')) $r['blocos_so_pt'][] = "$tr: $bl sem $lg";
    }
    // ES: texto em português no lugar do espanhol (palavras que não existem em espanhol)
    foreach ($ajudas as $tr => $a) { $t = mb_strtolower(strip_tags((string)$a['texto_es'])); if (preg_match_all('/\b(não|você|também|então|ção|ções|está disponível|são)\b/u', $t) >= 4) $r['es_com_portugues'][] = $tr; }
    foreach ($r as $k => $v) if (is_array($v)) $r[$k] = ['qtd' => count($v), 'lista' => array_slice($v, 0, 80)];
    $out['ajuda'] = $r;
} catch (Throwable $e) { $out['ajuda'] = ['erro' => $e->getMessage()]; }

// 1d) Guia do usuário (JSON publicado): os textos têm pt/en/es?
try {
    $g = $q('SELECT numero, conteudo FROM guia_usuario_versoes ORDER BY numero DESC LIMIT 1');
    if ($g) {
        $doc = json_decode((string)$g[0]['conteudo'], true) ?: [];
        $cont = ['textos' => 0, 'com_en' => 0, 'com_es' => 0, 'chaves_de_idioma' => []];
        $varre = function ($n) use (&$varre, &$cont) {
            if (!is_array($n)) return;
            $tem = array_intersect(array_keys($n), ['pt', 'en', 'es', 'texto_pt', 'texto_en', 'titulo_pt', 'titulo_en', 'nome_pt', 'nome_en']);
            if ($tem) { $cont['textos']++; if (array_intersect(array_keys($n), ['en', 'texto_en', 'titulo_en', 'nome_en'])) $cont['com_en']++; if (array_intersect(array_keys($n), ['es', 'texto_es', 'titulo_es', 'nome_es'])) $cont['com_es']++; foreach ($tem as $k) $cont['chaves_de_idioma'][$k] = true; }
            foreach ($n as $v) $varre($v);
        };
        $varre($doc);
        $cont['chaves_de_idioma'] = array_keys($cont['chaves_de_idioma']);
        $amostra = $doc['modulos'][0]['guias'][0] ?? ($doc['modulos'][0] ?? []);
        $out['guia_usuario'] = ['versao' => (int)$g[0]['numero'], 'modulos' => count($doc['modulos'] ?? []), 'contagem' => $cont,
            'chaves_amostra' => is_array($amostra) ? array_keys($amostra) : [], 'tamanho_json' => strlen((string)$g[0]['conteudo'])];
    } else $out['guia_usuario'] = 'sem versão publicada';
} catch (Throwable $e) { $out['guia_usuario'] = ['erro' => $e->getMessage()]; }

// ------------------------------------------------------------------ 2) código
$transOk = ['t', 'f', 'textos', 'erro', 'greenT', 'mensagemBanco', 'texto'];
$analisarPhp = static function (string $codigo) use ($ehPt, $ehEs, $ehEn, $transOk): array {
    $tk = @token_get_all($codigo);
    $n = count($tk); $sus = []; $trad = 0;
    $sig = []; // índices dos tokens significativos (sem espaço/comentário)
    for ($i = 0; $i < $n; $i++) { $x = $tk[$i]; if (is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue; $sig[] = $i; }
    $txt = static fn($x) => is_array($x) ? $x[1] : $x;
    foreach ($sig as $p => $i) {
        $x = $tk[$i];
        if (!is_array($x)) continue;
        if ($x[0] === T_STRING && in_array($x[1], ['t', 'f', 'textos'], true) && $p > 1 && $txt($tk[$sig[$p - 1]]) === '::') $trad++;
        if ($x[0] === T_STRING && in_array($x[1], ['ncT'], true)) $trad++;
        if ($x[0] === T_INLINE_HTML) {
            foreach (preg_split('/<[^>]+>/', $x[1]) as $nodo) { $nodo = trim($nodo); if ($nodo !== '' && $ehPt($nodo)) $sus[] = [$x[2], mb_substr(preg_replace('/\s+/', ' ', $nodo), 0, 90)]; }
            // atributos visíveis: title, placeholder, aria-label
            if (preg_match_all('/\b(title|placeholder|aria-label)="([^"<]{3,})"/u', $x[1], $m)) foreach ($m[2] as $v) if ($ehPt($v)) $sus[] = [$x[2], mb_substr($v, 0, 90)];
            continue;
        }
        if ($x[0] !== T_CONSTANT_ENCAPSED_STRING && $x[0] !== T_ENCAPSED_AND_WHITESPACE) continue;
        $s = stripcslashes(substr($x[1], 1, -1));
        if ($x[0] === T_ENCAPSED_AND_WHITESPACE) $s = $x[1];
        if (!$ehPt($s)) continue;
        // pedaço de HTML só com atributos data-* (data-sub="salvar"): não é texto da tela
        if (preg_match('/(^|[\s"])data-[\w-]+="/', $s) && !preg_match('/>[^<]*\pL/u', $s) && !preg_match('/\b(title|placeholder|aria-label)="[^"]*\pL/u', $s)) continue;
        // contexto: 'pt' => '...', 'en' => ..., 'es' => ... ou chave de array
        $ant1 = $p > 0 ? $txt($tk[$sig[$p - 1]]) : ''; $ant2 = $p > 1 ? $txt($tk[$sig[$p - 2]]) : '';
        $prox = $p + 1 < count($sig) ? $txt($tk[$sig[$p + 1]]) : '';
        if ($prox === '=>') continue;                                       // é chave de array
        if ($ant1 === '=>' && in_array(trim($ant2, "'\""), ['pt', 'en', 'es', 'pt_BR'], true)) { $trad++; continue; }
        // argumento de IdiomaService::t/f/textos, greenT(...) ou error_log/RuntimeException interna?
        $dentro = false; $prof = 0;
        for ($k = $p - 1; $k >= max(0, $p - 60); $k--) {
            $v = $txt($tk[$sig[$k]]);
            if ($v === ')' || $v === ']') $prof++;
            elseif ($v === '(' || $v === '[') { if ($prof === 0) { $fn = $k > 0 ? $txt($tk[$sig[$k - 1]]) : ''; if (in_array($fn, $transOk, true)) $dentro = true;
                // 'pt' => $u('Texto') (decodificador de acentos das telas de Homologação)
                if ($fn === '$u' && $k > 2 && $txt($tk[$sig[$k - 2]]) === '=>' && in_array(trim($txt($tk[$sig[$k - 3]]), "'\""), ['pt', 'en', 'es'], true)) $dentro = true; if (in_array($fn, ['error_log', 'define', 'preg_match', 'preg_replace', 'str_contains', 'in_array'], true)) $dentro = true; break; } $prof--; }
            elseif ($v === ';' || $v === '{' || $v === '}') break;
        }
        if ($dentro) { $trad++; continue; }
        // trinca pt/en/es em sequência: 'pt', 'en', 'es' (ex.: $T('chave', 'Texto', 'Text', 'Texto'))
        $seq = 1;
        for ($k = $p - 2; $k >= 0 && $txt($tk[$sig[$k + 1]]) === ',' && is_array($tk[$sig[$k]]) && $tk[$sig[$k]][0] === T_CONSTANT_ENCAPSED_STRING; $k -= 2) $seq++;
        for ($k = $p + 2; $k < count($sig) && $txt($tk[$sig[$k - 1]]) === ',' && is_array($tk[$sig[$k]]) && $tk[$sig[$k]][0] === T_CONSTANT_ENCAPSED_STRING; $k += 2) $seq++;
        if ($seq >= 3) { $trad++; continue; }
        // par de tradução: a próxima string do mesmo trecho está em espanhol ou inglês (ex.: ['Texto', 'Text', 'Texto'])
        $par = false;
        for ($k = $p + 1; $k < min(count($sig), $p + 8); $k++) {
            $y = $tk[$sig[$k]];
            if (!is_array($y)) { if (in_array($y, [';', '{', '}'], true)) break; continue; }
            if ($y[0] === T_CONSTANT_ENCAPSED_STRING) { $s2 = stripcslashes(substr($y[1], 1, -1)); if ($ehEs($s2) || $ehEn($s2)) $par = true; break; }
        }
        if ($par) { $trad++; continue; }
        $sus[] = [$x[2], mb_substr(preg_replace('/\s+/', ' ', strip_tags($s)), 0, 90)];
    }
    return [$trad, $sus];
};
$analisarJs = static function (string $codigo) use ($ehPt): array {
    $sus = []; $trad = substr_count($codigo, 'greenT(');
    foreach (preg_split('/\R/', $codigo) as $nl => $linha) {
        $l = trim($linha);
        if ($l === '' || str_starts_with($l, '//') || str_starts_with($l, '*') || str_starts_with($l, '/*')) continue;
        if (!preg_match_all("/(['\"`])((?:(?!\\1).|\\\\\\1){3,}?)\\1/u", $linha, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[2] as [$s, $pos]) {
            if (!$ehPt($s)) continue;
            $antes = substr($linha, max(0, $pos - 100), min($pos, 100));
            if (preg_match('/(greenT|\bT|\btx|\bL|\bX)\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"`]?$/', $antes) || preg_match('/(pt|en|es)\s*:\s*[\'"`]?$/', $antes)) continue;
            $sus[] = [$nl + 1, mb_substr(preg_replace('/\s+/', ' ', strip_tags($s)), 0, 90)];
        }
    }
    return [$trad, $sus];
};
// --arquivo=caminho1,caminho2: lista todos os textos só em português desses arquivos e sai (para traduzir um arquivo)
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--arquivo=')) {
    foreach (explode(',', substr($a, 10)) as $rel) {
        $codigo = (string)@file_get_contents($raiz . '/' . $rel);
        [$trad, $sus] = str_ends_with($rel, '.js') ? $analisarJs($codigo) : $analisarPhp($codigo);
        echo "== $rel: $trad traduzíveis, " . count($sus) . " só em PT\n";
        foreach ($sus as $x) echo '  ' . $x[0] . ': ' . $x[1] . "\n";
    }
    exit(0);
}
$arquivos = [];
foreach (['principal', 'components', 'classes', 'assets/js', 'principal/setup', 'principal/api'] as $dir) {
    foreach (glob($raiz . '/' . $dir . '/*.{php,js}', GLOB_BRACE) ?: [] as $f) {
        $rel = str_replace('\\', '/', substr($f, strlen($raiz) + 1));
        if (preg_match('/(diagnostico_|_temporario|\.min\.js$|vendor)/', $rel)) continue;
        $codigo = (string)file_get_contents($f);
        [$trad, $sus] = str_ends_with($f, '.js') ? $analisarJs($codigo) : $analisarPhp($codigo);
        $arquivos[$rel] = ['traduzidos' => $trad, 'so_portugues' => count($sus), 'exemplos' => array_map(static fn($e) => 'linha ' . $e[0] . ': ' . $e[1], array_slice($sus, 0, 6))];
    }
}
// telas do menu: arquivo → menu e ajuda
$telas = [];
foreach ($menus as $m) {
    if ((int)($m['ativo'] ?? 1) !== 1 || trim((string)$m['url']) === '') continue;
    $arq = ltrim((string)parse_url((string)$m['url'], PHP_URL_PATH), '/');
    if (!isset($arquivos[$arq])) continue;
    $transacao = strtolower(basename($arq, '.php'));
    $telas[$arq] = ['menu' => $m['label_pt'], 'codigo' => $m['codigo'], 'ajuda' => isset($ajudas[$transacao]) ? 'sim' : 'NÃO'] + $arquivos[$arq];
}
uasort($telas, static fn($a, $b) => $b['so_portugues'] <=> $a['so_portugues']);
uasort($arquivos, static fn($a, $b) => $b['so_portugues'] <=> $a['so_portugues']);
$faixa = ['sem textos fixos em PT' => 0, '1 a 10' => 0, '11 a 50' => 0, 'mais de 50' => 0];
foreach ($telas as $t) $faixa[$t['so_portugues'] === 0 ? 'sem textos fixos em PT' : ($t['so_portugues'] <= 10 ? '1 a 10' : ($t['so_portugues'] <= 50 ? '11 a 50' : 'mais de 50'))]++;
$out['codigo'] = [
    'arquivos_analisados' => count($arquivos),
    'textos_traduziveis' => array_sum(array_column($arquivos, 'traduzidos')),
    'textos_so_portugues' => array_sum(array_column($arquivos, 'so_portugues')),
    'telas_do_menu' => count($telas), 'telas_por_faixa' => $faixa,
    'telas_sem_ajuda' => array_values(array_map(static fn($t) => $t['menu'] . ' (' . $t['codigo'] . ')', array_filter($telas, static fn($t) => $t['ajuda'] === 'NÃO'))),
    'por_pasta' => [],
    'telas' => $telas,
    'demais_arquivos_top' => array_slice(array_diff_key($arquivos, $telas), 0, 80, true),
];
foreach ($arquivos as $rel => $a) { $p = dirname($rel); $out['codigo']['por_pasta'][$p]['arquivos'] = ($out['codigo']['por_pasta'][$p]['arquivos'] ?? 0) + 1; $out['codigo']['por_pasta'][$p]['so_portugues'] = ($out['codigo']['por_pasta'][$p]['so_portugues'] ?? 0) + $a['so_portugues']; $out['codigo']['por_pasta'][$p]['traduzidos'] = ($out['codigo']['por_pasta'][$p]['traduzidos'] ?? 0) + $a['traduzidos']; }

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents($raiz . '/storage/logs/auditoria_idiomas.json', $json);
if (PHP_SAPI === 'cli') {
    echo "Auditoria de idiomas gravada em storage\\logs\\auditoria_idiomas.json\n";
    echo 'Textos e idiomas: ' . json_encode(array_diff_key($out['textos_e_idiomas'], ['por_grupo' => 1, 'exemplos' => 1]), JSON_UNESCAPED_UNICODE) . "\n";
    echo 'Código: ' . json_encode(array_diff_key($out['codigo'], ['telas' => 1, 'demais_arquivos_top' => 1, 'por_pasta' => 1, 'telas_sem_ajuda' => 1]), JSON_UNESCAPED_UNICODE) . "\n";
} else { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'arquivo' => 'storage/logs/auditoria_idiomas.json'], JSON_UNESCAPED_UNICODE); }

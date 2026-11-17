<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — cobertura legado × greenportal de uma empresa: TODAS as tabelas do banco legado
 * (e do banco de segurança dele), com linhas, se existem no banco novo, colunas que só o legado tem (informação que
 * se perderia) e qual processo leva os dados hoje:
 *   SINCRONISMO_FO/_NC   tabelas fo_* e nc_* do scripts\sincronizar-fo-legado.php (classes/SincronismoLegado.php)
 *   DESCONSIDERADA       descartada pelo usuário (SincronismoLegado::DESCONSIDERADAS): não é migrada nem sincronizada
 *   MANTIDA_NO_NOVO_NESTE_BANCO  prefixo que, neste banco, ainda é mantido no greenportal
 *   MANTIDA_NO_NOVO      fo_* que o sincronismo NÃO traz (SINC_MESTRE_NOVO: mantidas no greenportal)
 *   SINCRONISMO_LEGAL    lista do Módulo Legal no sincronismo (também carregada pela migração)
 *   SEGURANCA            banco *_seguranca (migrar-seguranca-generico.php)
 *   SEM_PROCESSO         nenhum processo copia: é o que falta para "migrar todas as tabelas"
 * As listas são lidas do próprio sincronizar-fo-legado.php, para não ficarem desatualizadas.
 * Lê só information_schema (linhas = TABLE_ROWS atual, estimativa do InnoDB).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-cobertura-legado.php --origem=green_crown --destino=gcrown_dbp [--detalhe] [--prefixo=nc_]
 * --prefixo: só as tabelas que começam assim (ex.: nc_), com todas as colunas que só existem no legado ou só no greenportal.
 * Relatório também em storage\logs\cobertura_<destino>.json.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/SincronismoLegado.php';

$opc = static function (string $n) use ($argv): ?string { foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$n=")) return substr($a, strlen($n) + 3); return null; };
$origem = (string)$opc('origem'); $destino = (string)$opc('destino'); $detalhe = in_array('--detalhe', $argv ?? [], true);
$prefixo = strtolower((string)($opc('prefixo') ?? ''));
if ($prefixo !== '') $detalhe = true;
if (!preg_match('/^[A-Za-z0-9_]+$/', $origem) || !preg_match('/^g[a-z0-9_]+_dbp$/', $destino)) { fwrite(STDERR, "Uso: --origem=green_crown --destino=gcrown_dbp\n"); exit(1); }

$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->exec('SET SESSION information_schema_stats_expiry = 0');
$q = static function (string $sql, array $p = []) use ($is): array { $s = $is->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };

// Listas do sincronismo
$fonte = (string)file_get_contents(__DIR__ . '/sincronizar-fo-legado.php');
$lista = static function (string $padrao) use ($fonte): array { return preg_match($padrao, $fonte, $m) ? array_map('strtolower', preg_match_all("/'([a-z0-9_]+)'/i", $m[1], $mm) ? $mm[1] : []) : []; };
$legal = array_map('strtolower', SincronismoLegado::TABELAS_LEGAL);
$mestreNovo = $lista('/const SINC_MESTRE_NOVO = \[(.*?)\];/s');

$tabelas = static function (string $b) use ($q): array {
    $out = [];
    foreach ($q("SELECT LOWER(TABLE_NAME) n, COALESCE(TABLE_ROWS,0) r FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$b]) as $l) $out[$l['n']] = (int)$l['r'];
    return $out;
};
$colunas = static function (string $b) use ($q): array {
    $out = [];
    foreach ($q('SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c FROM COLUMNS WHERE TABLE_SCHEMA = ?', [$b]) as $l) $out[$l['t']][$l['c']] = true;
    return $out;
};
$existe = static fn(string $b): bool => (bool)$q('SELECT 1 FROM SCHEMATA WHERE SCHEMA_NAME = ?', [$b]);
if (!$existe($origem) || !$existe($destino)) { fwrite(STDERR, "Banco de origem ou destino não existe.\n"); exit(1); }

$novo = $tabelas($destino); $colNovo = $colunas($destino);
$rel = ['diagnostico' => 'cobertura legado x greenportal', 'origem' => $origem, 'destino' => $destino, 'gerado_em' => date('c'),
        'obs' => 'linhas = estimativa atual do InnoDB; SEM_PROCESSO = nenhum processo copia hoje', 'resumo' => [], 'por_modulo' => [], 'tabelas' => []];
foreach (array_filter([$origem, $origem . '_seguranca'], $existe) as $banco) {
    $seg = $banco !== $origem;
    $colLeg = $colunas($banco);
    foreach ($tabelas($banco) as $t => $linhas) {
        if ($prefixo !== '' && !str_starts_with($t, $prefixo)) continue;
        $prefixoSinc = null; foreach (SincronismoLegado::PREFIXOS as $p) if (str_starts_with($t, $p)) $prefixoSinc = $p;
        $processo = $seg ? 'SEGURANCA' : (SincronismoLegado::desconsiderada($t) !== null ? 'DESCONSIDERADA'
            : ($prefixoSinc !== null ? (in_array($destino, SincronismoLegado::PREFIXOS_MANTIDOS_NO_NOVO[$prefixoSinc] ?? [], true) ? 'MANTIDA_NO_NOVO_NESTE_BANCO'
                : ($prefixoSinc === 'fo_' && in_array($t, $mestreNovo, true) && in_array($destino, ['gmodelo_dbp', 'gatlas_dbp', 'gmodelo2_dbp'], true) ? 'MANTIDA_NO_NOVO' : 'SINCRONISMO_' . strtoupper(rtrim($prefixoSinc, '_'))))
            : (in_array($t, $legal, true) ? 'SINCRONISMO_LEGAL' : 'SEM_PROCESSO')));
        $noNovo = isset($novo[$t]);
        $soLegado = $noNovo ? array_values(array_filter(array_keys(array_diff_key($colLeg[$t] ?? [], $colNovo[$t] ?? [])), static fn($c) => SincronismoLegado::colunaDesconsiderada((string)$c) === null)) : []; // *_en: desconsideradas
        $modulo = $seg ? 'seguranca' : (preg_match('/^([a-z]+)_/', $t, $m) ? $m[1] : (str_starts_with($t, 'legislacao') ? 'legislacao' : 'outros'));
        $item = ['banco' => $banco, 'modulo' => $modulo, 'processo' => $processo, 'linhas_legado' => $linhas, 'no_novo' => $noNovo, 'linhas_novo' => $noNovo ? $novo[$t] : null];
        if ($soLegado) $item['colunas_so_no_legado'] = $soLegado;
        if ($prefixo !== '' && $noNovo && ($soNovo = array_keys(array_diff_key($colNovo[$t] ?? [], $colLeg[$t] ?? [])))) $item['colunas_so_no_greenportal'] = $soNovo;
        $rel['tabelas'][($seg ? 'seguranca.' : '') . $t] = $item;
        $k = $processo; $rel['resumo'][$k]['tabelas'] = ($rel['resumo'][$k]['tabelas'] ?? 0) + 1;
        $rel['resumo'][$k]['linhas_legado'] = ($rel['resumo'][$k]['linhas_legado'] ?? 0) + $linhas;
        if ($linhas > 0) $rel['resumo'][$k]['com_dados'] = ($rel['resumo'][$k]['com_dados'] ?? 0) + 1;
        if (!$noNovo) $rel['resumo'][$k]['nao_existem_no_novo'] = ($rel['resumo'][$k]['nao_existem_no_novo'] ?? 0) + 1;
        if ($processo === 'SEM_PROCESSO' && $linhas > 0) {
            $rel['por_modulo'][$modulo]['tabelas'] = ($rel['por_modulo'][$modulo]['tabelas'] ?? 0) + 1;
            $rel['por_modulo'][$modulo]['linhas'] = ($rel['por_modulo'][$modulo]['linhas'] ?? 0) + $linhas;
            $rel['por_modulo'][$modulo]['exemplos'][] = "$t ($linhas)" . ($noNovo ? '' : ' [não existe no novo]');
        }
    }
}
if ($prefixo !== '') $rel['so_no_greenportal'] = array_values(array_filter(array_keys($novo), static fn($t) => str_starts_with($t, $prefixo) && !isset($rel['tabelas'][$t])));
foreach ($rel['por_modulo'] as &$m) { usort($m['exemplos'], static fn($a, $b) => (int)preg_replace('/\D/', '', $b) <=> (int)preg_replace('/\D/', '', $a)); if (!$detalhe) $m['exemplos'] = array_slice($m['exemplos'], 0, 8); }
unset($m);
uasort($rel['por_modulo'], static fn($a, $b) => $b['linhas'] <=> $a['linhas']);
if (!$detalhe) { // sem --detalhe: só as tabelas com dados que têm algum problema
    $rel['tabelas'] = array_filter($rel['tabelas'], static fn($i) => $i['linhas_legado'] > 0 && ($i['processo'] === 'SEM_PROCESSO' || $i['processo'] === 'MANTIDA_NO_NOVO' || !$i['no_novo'] || !empty($i['colunas_so_no_legado'])) && $i['processo'] !== 'SEM_PROCESSO');
    $rel['obs_tabelas'] = 'sem --detalhe: só tabelas com dados mantidas no novo, ausentes no novo ou com colunas só no legado (as SEM_PROCESSO estão em por_modulo)';
}
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'cobertura_' . $destino . ($prefixo !== '' ? '_' . rtrim($prefixo, '_') : '') . '.json', $json);
echo $json . PHP_EOL;

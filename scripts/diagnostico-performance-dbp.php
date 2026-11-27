<?php
declare(strict_types=1);

/*
 * Diagnóstico de performance dos bancos *_dbp (26/11/2026). SOMENTE LEITURA: não altera nada.
 *
 * Servidor: versão, memória do InnoDB × tamanho dos dados, cache, tabelas temporárias em disco, log de consultas lentas,
 *   taxa de acerto do buffer pool e, se o performance_schema estiver ligado, as 25 consultas que mais gastaram tempo nos *_dbp.
 * Cada banco *_dbp: tamanho, tabelas sem chave primária, tabelas MyISAM, espaço livre (fragmentação), índices repetidos,
 *   colunas cod* sem índice em tabelas grandes (candidatas a índice), e o tempo das views mais usadas pelas telas
 *   (SELECT COUNT(*), com limite de 30 s) e o plano delas (EXPLAIN: varreduras completas em tabelas grandes).
 * Saída: JSON na tela e em storage/logs/performance_dbp_<data>.json. Não mostra senhas nem dados das tabelas.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-performance-dbp.php [--banco=gcrown_dbp] [--sem-views]
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

@set_time_limit(0);
$args = $argv ?? [];
$soBanco = null; $semViews = in_array('--sem-views', $args, true);
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$leg = (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp');
$uni = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$conectar = static function (string $banco) use ($env, $leg, $uni): PDO {
    $p = GreenPortalDatabase::connect($env, $banco, $banco === $leg ? 'GLEGISLACAO' : ($banco === $uni ? 'GUNIVERSAL' : 'GPT'));
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
};
$q = static function (PDO $p, string $sql, array $par = []): array { $s = $p->prepare($sql); $s->execute($par); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$mb = static fn($b): float => round(((float)$b) / 1048576, 1);
$rel = ['gerado_em' => date('Y-m-d H:i:s'), 'servidor' => [], 'bancos' => [], 'resumo' => []];

// Views mais usadas pelas telas (grids, Home, pendências, permissões)
$VIEWS = ['vw_modulolegal_requisitos_situacao_grid', 'vw_modulolegal_requisitos_grid', 'vw_modulolegal_requisitos_filtro_grid', 'vw_ml_requisito_responsaveis',
    'vw_licencas_grid', 'vw_licencas_situacao', 'vw_resp_acesso', 'vw_resp_regras_itens', 'vw_alertas_grid', 'vw_painel_aprovacoes', 'vw_minhas_licencas_grid',
    'vw_meus_controles_grid', 'vw_minhas_treinamentos_grid', 'vw_minhas_infraestrutura_grid', 'vw_dash_legal_unidade', 'vw_dash_equipe', 'vw_usuarios_grid',
    'vw_homologacao_fornecedores_grid', 'vw_homologacao_criterios_fornecedores_grid', 'vw_nc_ocorrencia', 'vlistadeocorrenciasnc', 'vw_minhas_leis_nao_avaliadas',
    'vw_legislacao_grid', 'vw_tipos_palavras_chaves_grid'];

try {
    $base = $conectar($soBanco ?? $uni);
    $ver = (string)$base->query('SELECT VERSION()')->fetchColumn();
    $maria = stripos($ver, 'mariadb') !== false;
    $vars = [];
    foreach ($q($base, "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','innodb_log_file_size','innodb_redo_log_capacity','innodb_flush_log_at_trx_commit','innodb_file_per_table','max_connections','table_open_cache','tmp_table_size','max_heap_table_size','slow_query_log','long_query_time','slow_query_log_file','query_cache_type','query_cache_size','performance_schema','sort_buffer_size','join_buffer_size','key_buffer_size','innodb_io_capacity','innodb_buffer_pool_instances','sql_mode','optimizer_switch')") as $r) $vars[$r['Variable_name']] = $r['Value'];
    $st = [];
    foreach ($q($base, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Max_used_connections','Slow_queries','Questions','Created_tmp_tables','Created_tmp_disk_tables','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Handler_read_rnd_next','Handler_read_key','Select_full_join','Select_scan','Sort_merge_passes','Table_open_cache_misses','Opened_tables','Innodb_row_lock_waits','Innodb_row_lock_time_avg')") as $r) $st[$r['Variable_name']] = $r['Value'];
    $dados = $q($base, "SELECT SUM(DATA_LENGTH + INDEX_LENGTH) t FROM information_schema.TABLES WHERE TABLE_SCHEMA LIKE '%\\_dbp'")[0]['t'] ?? 0;
    $hit = (float)($st['Innodb_buffer_pool_read_requests'] ?? 0) > 0 ? round(100 * (1 - (float)$st['Innodb_buffer_pool_reads'] / (float)$st['Innodb_buffer_pool_read_requests']), 3) : null;
    $rel['servidor'] = [
        'versao' => $ver, 'uptime_horas' => round((int)($st['Uptime'] ?? 0) / 3600, 1),
        'dados_dbp_mb' => $mb($dados), 'innodb_buffer_pool_mb' => $mb($vars['innodb_buffer_pool_size'] ?? 0), 'buffer_pool_acerto_pct' => $hit,
        'tmp_disco_pct' => (int)($st['Created_tmp_tables'] ?? 0) > 0 ? round(100 * (int)$st['Created_tmp_disk_tables'] / (int)$st['Created_tmp_tables'], 1) : null,
        'consultas_lentas' => (int)($st['Slow_queries'] ?? 0), 'select_full_join' => (int)($st['Select_full_join'] ?? 0), 'select_scan' => (int)($st['Select_scan'] ?? 0),
        'conexoes' => ['agora' => (int)($st['Threads_connected'] ?? 0), 'max_usado' => (int)($st['Max_used_connections'] ?? 0), 'max' => (int)($vars['max_connections'] ?? 0)],
        'variaveis' => array_diff_key($vars, ['optimizer_switch' => 1]), 'status' => $st,
    ];
    $rec = [];
    if ((float)($vars['innodb_buffer_pool_size'] ?? 0) < (float)$dados * 0.8) $rec[] = 'innodb_buffer_pool_size (' . $mb($vars['innodb_buffer_pool_size'] ?? 0) . ' MB) é menor que os dados dos *_dbp (' . $mb($dados) . ' MB): aumentar no my.ini (ideal: dados + 20%, até ~60% da RAM do servidor).';
    if ($hit !== null && $hit < 99) $rec[] = "Acerto do buffer pool $hit% (ideal ≥ 99%): falta memória para o InnoDB.";
    if (strtoupper((string)($vars['slow_query_log'] ?? 'OFF')) !== 'ON') $rec[] = 'slow_query_log desligado: ligar (long_query_time = 2) para registrar as consultas lentas.';
    if (($rel['servidor']['tmp_disco_pct'] ?? 0) > 25) $rec[] = 'Muitas tabelas temporárias em disco (' . $rel['servidor']['tmp_disco_pct'] . '%): aumentar tmp_table_size e max_heap_table_size juntos (ex.: 64M).';
    if ((int)($vars['innodb_flush_log_at_trx_commit'] ?? 1) === 1) $rec[] = 'innodb_flush_log_at_trx_commit = 1 (mais seguro, mais lento em gravações em lote). Avaliar 2 se as rotinas de sincronismo/importação forem lentas.';
    if ((int)($vars['query_cache_size'] ?? 0) > 0 && strtoupper((string)($vars['query_cache_type'] ?? 'OFF')) !== 'OFF') $rec[] = 'Query cache ligado: em MySQL/MariaDB com muitas gravações costuma atrapalhar; avaliar desligar.';
    $rel['servidor']['recomendacoes'] = $rec;
    // Consultas que mais gastaram tempo (performance_schema)
    if (strtoupper((string)($vars['performance_schema'] ?? 'OFF')) === 'ON') {
        try {
            $rel['servidor']['top_consultas'] = array_map(static fn($r) => ['banco' => $r['SCHEMA_NAME'], 'execucoes' => (int)$r['COUNT_STAR'], 'tempo_total_s' => round((float)$r['SUM_TIMER_WAIT'] / 1e12, 1),
                'tempo_medio_ms' => round((float)$r['AVG_TIMER_WAIT'] / 1e9, 1), 'linhas_lidas_media' => (int)round((float)$r['SUM_ROWS_EXAMINED'] / max(1, (int)$r['COUNT_STAR'])), 'sem_indice' => (int)$r['SUM_NO_INDEX_USED'],
                'sql' => mb_substr(preg_replace('/\s+/', ' ', (string)$r['DIGEST_TEXT']), 0, 400)],
                $q($base, "SELECT SCHEMA_NAME, COUNT_STAR, SUM_TIMER_WAIT, AVG_TIMER_WAIT, SUM_ROWS_EXAMINED, SUM_NO_INDEX_USED, DIGEST_TEXT FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME LIKE '%\\_dbp' ORDER BY SUM_TIMER_WAIT DESC LIMIT 25"));
        } catch (Throwable $e) { $rel['servidor']['top_consultas'] = 'indisponível: ' . $e->getMessage(); }
    } else $rel['servidor']['top_consultas'] = 'performance_schema desligado';
    $bancos = array_column($q($base, "SELECT SCHEMA_NAME s FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '%\\_dbp' ORDER BY 1"), 's');
} catch (Throwable $e) { echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(1); }

foreach ($bancos as $banco) {
    if ($soBanco !== null && $banco !== $soBanco) continue;
    $info = [];
    try {
        $p = $conectar($banco);
        $tabs = $q($p, "SELECT TABLE_NAME n, ENGINE e, TABLE_ROWS r, DATA_LENGTH d, INDEX_LENGTH i, DATA_FREE f, TABLE_TYPE t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        $base_t = array_filter($tabs, static fn($t) => $t['t'] === 'BASE TABLE');
        $info['tabelas'] = count($base_t); $info['views'] = count($tabs) - count($base_t);
        $info['tamanho_mb'] = $mb(array_sum(array_map(static fn($t) => (float)$t['d'] + (float)$t['i'], $base_t)));
        usort($base_t, static fn($a, $b) => ((float)$b['d'] + (float)$b['i']) <=> ((float)$a['d'] + (float)$a['i']));
        $info['maiores'] = array_map(static fn($t) => $t['n'] . ': ' . number_format((int)$t['r'], 0, ',', '.') . ' linhas, ' . $mb((float)$t['d'] + (float)$t['i']) . ' MB', array_slice($base_t, 0, 12));
        $info['myisam'] = array_values(array_map(static fn($t) => $t['n'] . ' (' . (int)$t['r'] . ' linhas)', array_filter($base_t, static fn($t) => strcasecmp((string)$t['e'], 'MyISAM') === 0)));
        $info['fragmentadas'] = array_values(array_map(static fn($t) => $t['n'] . ': ' . $mb($t['f']) . ' MB livres', array_filter($base_t, static fn($t) => (float)$t['f'] > 50 * 1048576 && (float)$t['f'] > 0.2 * ((float)$t['d'] + 1))));
        $pk = array_column($q($p, "SELECT DISTINCT TABLE_NAME n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'PRIMARY'"), 'n');
        $info['sem_chave_primaria'] = array_values(array_map(static fn($t) => $t['n'] . ' (' . (int)$t['r'] . ' linhas)', array_filter($base_t, static fn($t) => !in_array($t['n'], $pk, true) && (int)$t['r'] > 0)));
        // índices: colunas por índice
        $idx = [];
        foreach ($q($p, "SELECT TABLE_NAME t, INDEX_NAME i, SEQ_IN_INDEX s, COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1, 2, 3") as $r) $idx[$r['t']][$r['i']][] = strtolower((string)$r['c']);
        $repetidos = [];
        foreach ($idx as $t => $lista) foreach ($lista as $i => $cols) foreach ($lista as $j => $outras) {
            if ($i === $j || $i === 'PRIMARY') continue;
            if (count($cols) <= count($outras) && array_slice($outras, 0, count($cols)) === $cols && ($cols !== $outras || strcmp($i, $j) > 0)) { $repetidos[] = "$t.$i (" . implode(',', $cols) . ") coberto por $j"; break; }
        }
        $info['indices_repetidos'] = array_slice($repetidos, 0, 40);
        // colunas cod*/id* sem índice que comece por elas, em tabelas com mais de 2.000 linhas
        $grandes = []; foreach ($base_t as $t) if ((int)$t['r'] > 2000) $grandes[$t['n']] = (int)$t['r'];
        $sem = [];
        if ($grandes) foreach ($q($p, "SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (LOWER(COLUMN_NAME) LIKE 'cod%' OR LOWER(COLUMN_NAME) LIKE 'id\\_%' OR LOWER(COLUMN_NAME) LIKE '%\\_id' OR LOWER(COLUMN_NAME) IN ('registro_id','tabela','area','situacao','status','ativo','lido','codigo'))") as $r) {
            if (!isset($grandes[$r['t']]) || !in_array(strtolower((string)$r['d']), ['int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'varchar', 'char'], true)) continue;
            $c = strtolower((string)$r['c']); $tem = false;
            foreach ($idx[$r['t']] ?? [] as $cols) if (($cols[0] ?? '') === $c) { $tem = true; break; }
            if (!$tem) $sem[] = ['tabela' => $r['t'], 'coluna' => $r['c'], 'linhas' => $grandes[$r['t']]];
        }
        usort($sem, static fn($a, $b) => $b['linhas'] <=> $a['linhas']);
        $info['colunas_sem_indice'] = array_map(static fn($s) => $s['tabela'] . '.' . $s['coluna'] . ' (' . number_format($s['linhas'], 0, ',', '.') . ' linhas)', array_slice($sem, 0, 60));
        // views: tempo e plano
        if (!$semViews) {
            $nomesViews = array_column(array_filter($tabs, static fn($t) => $t['t'] === 'VIEW'), 'n');
            try { $p->exec($maria ? 'SET SESSION max_statement_time = 30' : 'SET SESSION MAX_EXECUTION_TIME = 30000'); } catch (Throwable) {}
            foreach ($VIEWS as $v) {
                if (!in_array($v, $nomesViews, true)) continue;
                $t0 = microtime(true);
                try { $n = (int)$p->query("SELECT COUNT(*) FROM `$v`")->fetchColumn(); $seg = round(microtime(true) - $t0, 2); $res = ['linhas' => $n, 'segundos' => $seg]; }
                catch (Throwable $e) { $res = ['segundos' => round(microtime(true) - $t0, 2), 'erro' => mb_substr($e->getMessage(), 0, 160)]; }
                if (($res['segundos'] ?? 0) >= 1) {
                    try {
                        $plano = $q($p, "EXPLAIN SELECT * FROM `$v` LIMIT 50");
                        $res['varreduras'] = array_values(array_map(static fn($r) => ($r['table'] ?? '?') . ' (' . ($r['type'] ?? '') . ', ~' . ($r['rows'] ?? '?') . ' linhas' . (!empty($r['Extra']) ? ', ' . mb_substr((string)$r['Extra'], 0, 60) : '') . ')',
                            array_filter($plano, static fn($r) => in_array(strtoupper((string)($r['type'] ?? '')), ['ALL', 'INDEX'], true) && (int)($r['rows'] ?? 0) > 1000)));
                    } catch (Throwable) {}
                }
                $info['views_tempo'][$v] = $res;
            }
            try { $p->exec($maria ? 'SET SESSION max_statement_time = 0' : 'SET SESSION MAX_EXECUTION_TIME = 0'); } catch (Throwable) {}
        }
    } catch (Throwable $e) { $info['erro'] = mb_substr($e->getMessage(), 0, 200); }
    $rel['bancos'][$banco] = $info;
    $lentas = array_filter($info['views_tempo'] ?? [], static fn($r) => ($r['segundos'] ?? 0) >= 2);
    if ($lentas || ($info['sem_chave_primaria'] ?? []) || ($info['myisam'] ?? []))
        $rel['resumo'][$banco] = ['views_lentas_2s' => array_map(static fn($r) => $r['segundos'] . ' s', $lentas), 'sem_pk' => count($info['sem_chave_primaria'] ?? []), 'myisam' => count($info['myisam'] ?? []), 'colunas_sem_indice' => count($info['colunas_sem_indice'] ?? [])];
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($dir)) { $arq = $dir . DIRECTORY_SEPARATOR . 'performance_dbp_' . date('Ymd_His') . '.json'; @file_put_contents($arq, $json); fwrite(STDERR, "Relatório salvo em $arq\n"); }
echo $json . PHP_EOL;

<?php
declare(strict_types=1);

/*
 * Diagnóstico de desempenho das Não Conformidades (21/10/2026) — SOMENTE LEITURA.
 * Para cada banco com NC: tabelas nc_* (motor, collation, linhas, tamanho, índices, chaves estrangeiras), views e funções
 * que leem nc_*, variáveis do servidor e o tempo das consultas que as telas fazem (Lista geral, Minhas ocorrências,
 * listas de filtro, painel de pendências, tela da ocorrência). Consultas lentas (≥ 300 ms) também saem com EXPLAIN ANALYZE.
 * Cada consulta tem limite de 60 s (max_execution_time). Nada é gravado no banco.
 * Saída: resumo na tela e completo em storage/logs/diagnostico_nc_performance.json.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-performance.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$is->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$todas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(); $s->closeCursor(); return $r; };

$completo = ['gerado_em' => date('c'), 'servidor' => [], 'bancos' => []];
$resumo = ['bancos' => []];
foreach ($todas($is, "SHOW VARIABLES WHERE Variable_name IN ('version','innodb_buffer_pool_size','tmp_table_size','max_heap_table_size','sort_buffer_size','join_buffer_size','optimizer_switch','internal_tmp_mem_storage_engine')") as $v)
    $completo['servidor'][$v['Variable_name']] = $v['Value'];
$resumo['servidor'] = array_diff_key($completo['servidor'], ['optimizer_switch' => 1]);
$st = $todas($is, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads','Created_tmp_disk_tables','Created_tmp_tables','Uptime')");
foreach ($st as $v) $completo['servidor']['status'][$v['Variable_name']] = $v['Value'];

foreach ($alvos as $banco) {
    $tabelas = $todas($is, "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, ROUND(DATA_LENGTH/1048576, 2) AS dados_mb, ROUND(INDEX_LENGTH/1048576, 2) AS indices_mb
        FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND (TABLE_NAME LIKE 'nc\\_%' OR TABLE_NAME IN ('usuarios','unidades','areas','alertas')) ORDER BY TABLE_NAME", [$banco]);
    if (!array_filter($tabelas, static fn($t) => $t['TABLE_NAME'] === 'nc_indicacao_ocorrencia')) { $resumo['bancos'][$banco] = 'sem Não Conformidades'; continue; }
    $b = ['tabelas' => [], 'views' => [], 'funcoes' => [], 'consultas' => []];
    $rb = ['tabelas' => [], 'consultas' => [], 'erros' => []];
    $idx = [];
    foreach ($todas($is, "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE", [$banco]) as $i)
        $idx[$i['TABLE_NAME']][] = ($i['INDEX_NAME'] === 'PRIMARY' ? 'PK' : ($i['NON_UNIQUE'] ? 'IX' : 'UQ')) . '(' . $i['colunas'] . ')';
    $fks = [];
    foreach ($todas($is, "SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME LIKE 'nc\\_%'", [$banco]) as $f)
        $fks[$f['TABLE_NAME']][] = $f['COLUMN_NAME'] . '→' . $f['REFERENCED_TABLE_NAME'] . '.' . $f['REFERENCED_COLUMN_NAME'];
    $colunas = [];
    foreach ($todas($is, "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND (TABLE_NAME LIKE 'nc\\_%' OR TABLE_NAME IN ('usuarios','unidades','areas'))
        AND (COLUMN_NAME LIKE 'cod%' OR COLUMN_NAME IN ('ETAPA','SITUACAO','listadedistribuicao','NRANO','ATIVO'))", [$banco]) as $c)
        $colunas[$c['TABLE_NAME']][$c['COLUMN_NAME']] = $c['COLUMN_TYPE'] . ($c['COLLATION_NAME'] ? ' ' . $c['COLLATION_NAME'] : '');
    foreach ($tabelas as $t) {
        $n = $t['TABLE_NAME'];
        $b['tabelas'][$n] = $t + ['indices' => $idx[$n] ?? [], 'fks' => $fks[$n] ?? [], 'colunas_chave' => $colunas[$n] ?? []];
        $rb['tabelas'][$n] = sprintf('%s %s, ~%d linhas, %.1f+%.1f MB, %d índices', $t['ENGINE'], $t['TABLE_COLLATION'], (int)$t['TABLE_ROWS'], (float)$t['dados_mb'], (float)$t['indices_mb'], count($idx[$n] ?? []));
    }
    foreach ($todas($is, "SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND (VIEW_DEFINITION LIKE '%nc\\_%' OR TABLE_NAME LIKE '%nc%')", [$banco]) as $v)
        $b['views'][$v['TABLE_NAME']] = $v['VIEW_DEFINITION'];
    foreach ($todas($is, "SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'FUNCTION' AND (ROUTINE_DEFINITION LIKE '%nc\\_%' OR ROUTINE_NAME LIKE '%NC%')", [$banco]) as $f)
        $b['funcoes'][$f['ROUTINE_NAME']] = $f['ROUTINE_DEFINITION'];
    $rb['views_com_nc'] = count($b['views']);
    $rb['funcoes_com_nc'] = array_keys($b['funcoes']);

    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $pdo->exec('SET SESSION max_execution_time = 60000');
    } catch (Throwable $e) { $rb['erros'][] = 'conexão: ' . $e->getMessage(); $resumo['bancos'][$banco] = $rb; $completo['bancos'][$banco] = $b; continue; }
    // Amostras: a ocorrência mais recente do legado e o usuário mais envolvido (gestor com mais ocorrências abertas)
    $cod = (int)($todas($pdo, 'SELECT MAX(CODOCORRENCIA) AS c FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA < 50001')[0]['c'] ?? 0);
    $uid = (int)($todas($pdo, "SELECT CODUSUARIO_GESTOR AS u FROM nc_indicacao_ocorrencia WHERE CODUSUARIO_GESTOR > 0 AND COALESCE(ETAPA,'') <> 'Finalizado' GROUP BY CODUSUARIO_GESTOR ORDER BY COUNT(*) DESC LIMIT 1")[0]['u'] ?? 0);
    $rb['amostra'] = ['codocorrencia' => $cod, 'codusuario' => $uid];
    $env1 = '(o.CODUSUARIO_EMISSOR = :u1 OR o.CODUSUARIO_GESTOR = :u2 OR o.CODUSUARIO_RESPONSAVEL = :u3 OR EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA AND p.CODUSUARIO = :u4))';
    $consultas = [
        'lista_contagem' => ["SELECT COUNT(*) AS n FROM vlistadeocorrenciasnc WHERE ETAPA <> 'Finalizado'", []],
        'lista_pagina_25' => ["SELECT * FROM vlistadeocorrenciasnc WHERE ETAPA <> 'Finalizado' ORDER BY CODOCORRENCIA DESC LIMIT 25", []],
        'lista_pagina_25_sem_filtro' => ['SELECT * FROM vlistadeocorrenciasnc ORDER BY CODOCORRENCIA DESC LIMIT 25', []],
        'lista_busca_texto' => ["SELECT COUNT(*) AS n FROM vlistadeocorrenciasnc WHERE DESCRICAO LIKE '%vazamento%'", []],
        'lista_opcoes_gestor' => ["SELECT DISTINCT NOME_GESTOR FROM vlistadeocorrenciasnc WHERE NOME_GESTOR IS NOT NULL AND LENGTH(TRIM(NOME_GESTOR)) > 0", []],
        'lista_opcoes_responsavel' => ["SELECT DISTINCT NOME_RESPONSAVEL FROM vlistadeocorrenciasnc WHERE NOME_RESPONSAVEL IS NOT NULL AND LENGTH(TRIM(NOME_RESPONSAVEL)) > 0", []],
        'lista_opcoes_emissor' => ["SELECT DISTINCT NOME_EMISSOR FROM vlistadeocorrenciasnc WHERE NOME_EMISSOR IS NOT NULL AND LENGTH(TRIM(NOME_EMISSOR)) > 0", []],
        'lista_opcoes_encerrado' => ["SELECT DISTINCT ENCERRADO FROM vlistadeocorrenciasnc WHERE ENCERRADO IS NOT NULL AND LENGTH(TRIM(ENCERRADO)) > 0", []],
        'minhas_contagem' => ['SELECT COUNT(*) AS n FROM vminhasocorrenciasnc WHERE CODUSUARIO_ENVOLVIDO = :u', ['u' => $uid]],
        'minhas_pagina_25' => ['SELECT * FROM vminhasocorrenciasnc WHERE CODUSUARIO_ENVOLVIDO = :u ORDER BY CODOCORRENCIA DESC LIMIT 25', ['u' => $uid]],
        'minhas_opcoes_gestor' => ["SELECT DISTINCT NOME_GESTOR FROM vminhasocorrenciasnc WHERE NOME_GESTOR IS NOT NULL AND LENGTH(TRIM(NOME_GESTOR)) > 0", []],
        'painel_pendencias' => ["SELECT COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $env1 AND COALESCE(o.ETAPA, '') <> 'Finalizado'", ['u1' => $uid, 'u2' => $uid, 'u3' => $uid, 'u4' => $uid]],
        'ocorrencia_view' => ['SELECT * FROM vw_nc_ocorrencia WHERE CODOCORRENCIA = :c', ['c' => $cod]],
        'ocorrencia_envolvido' => ['SELECT 1 FROM vw_nc_envolvidos WHERE CODOCORRENCIA = :c AND CODUSUARIO = :u LIMIT 1', ['c' => $cod, 'u' => $uid]],
        'ocorrencia_causas' => ['SELECT * FROM vw_nc_causas WHERE CODOCORRENCIA = :c', ['c' => $cod]],
        'ocorrencia_planos' => ['SELECT * FROM vw_nc_planodeacao WHERE CODOCORRENCIA = :c', ['c' => $cod]],
        'ocorrencia_historico' => ['SELECT * FROM vw_nc_historico WHERE CODOCORRENCIA = :c', ['c' => $cod]],
        'ocorrencia_historico_planos' => ['SELECT * FROM vw_nc_planodeacao_historico WHERE CODOCORRENCIA = :c', ['c' => $cod]],
        'ocorrencia_opcoes' => ['SELECT COUNT(*) AS n FROM vw_nc_opcoes', []],
        'ocorrencia_usuarios' => ['SELECT COUNT(*) AS n FROM vw_nc_usuarios', []],
        'status_plano_funcao' => ['SELECT getStatusPlanoAcaoNC(:c) AS r', ['c' => $cod]],
        'status_plano_100_abertas' => ["SELECT COUNT(getStatusPlanoAcaoNC(CODOCORRENCIA)) AS n FROM (SELECT CODOCORRENCIA FROM nc_indicacao_ocorrencia WHERE COALESCE(ETAPA,'') <> 'Finalizado' ORDER BY CODOCORRENCIA DESC LIMIT 100) x", []],
    ];
    foreach ($consultas as $nome => [$sql, $p]) {
        $item = ['sql' => $sql];
        try {
            $t0 = hrtime(true); $linhas = $todas($pdo, $sql, $p); $ms = (hrtime(true) - $t0) / 1e6;
            $item += ['ms' => round($ms, 1), 'linhas' => count($linhas)];
            try { $item['explain'] = implode("\n", array_map(static fn($r) => (string)reset($r), $todas($pdo, 'EXPLAIN FORMAT=TREE ' . $sql, $p))); } catch (Throwable $e) { $item['explain'] = 'erro: ' . $e->getMessage(); }
            if ($ms >= 300) {
                try { $item['explain_analyze'] = implode("\n", array_map(static fn($r) => (string)reset($r), $todas($pdo, 'EXPLAIN ANALYZE ' . $sql, $p))); } catch (Throwable $e) { $item['explain_analyze'] = 'erro: ' . $e->getMessage(); }
            }
            $rb['consultas'][$nome] = round($ms) . ' ms (' . count($linhas) . ' linhas)';
        } catch (Throwable $e) {
            $item['erro'] = $e->getMessage();
            $rb['consultas'][$nome] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 160);
        }
        $b['consultas'][$nome] = $item;
    }
    $completo['bancos'][$banco] = $b;
    $resumo['bancos'][$banco] = $rb;
}

$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$arquivo = $dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_performance.json';
@file_put_contents($arquivo, json_encode($completo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
$resumo['arquivo_completo'] = $arquivo;
echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;

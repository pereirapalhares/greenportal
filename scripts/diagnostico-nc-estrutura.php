<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — Não Conformidades: estrutura para a reconstrução do módulo (20/10/2026).
 * Ocorrências por tipo (CODTIPONAOCONFORMIDADE) × etapa; campos de cada tipo (nc_tipo_de_naoconformidadexcampos ×
 * green_universal.md_tabelasxcampos); views, procedimentos e gatilhos de NC no greenportal; colunas das tabelas de apoio;
 * funções do legado usadas pelo fluxo (getStatusPlanoAcaoNC e outras getNC*).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-estrutura.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$leg = (string)HomologacaoBancos::legado($banco);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 220)]; } };
$r = ['banco' => $banco, 'legado' => $leg];

$r['tipos'] = $tenta(fn() => $q('SELECT t.CODTIPONAOCONFORMIDADE AS cod, t.DSCTIPONAOCONFORMIDADE AS tipo, COUNT(o.CODOCORRENCIA) AS total,
        SUM(o.ETAPA <> \'Finalizado\') AS em_aberto, SUM(YEAR(o.data_ocorrencia) = 2026) AS em_2026, SUM(YEAR(o.data_ocorrencia) = 2025) AS em_2025
      FROM nc_tipo_de_naoconformidade t LEFT JOIN nc_indicacao_ocorrencia o ON o.CODTIPONAOCONFORMIDADE = t.CODTIPONAOCONFORMIDADE GROUP BY 1, 2 ORDER BY total DESC'));
$r['sem_tipo'] = $tenta(fn() => $q('SELECT COALESCE(CODTIPONAOCONFORMIDADE, -1) AS cod, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE NOT EXISTS (SELECT 1 FROM nc_tipo_de_naoconformidade t WHERE t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE) GROUP BY 1'));
$r['abertas_por_tipo_e_etapa'] = $tenta(fn() => $q("SELECT CODTIPONAOCONFORMIDADE AS tipo, ETAPA AS etapa, COUNT(*) AS n FROM nc_indicacao_ocorrencia WHERE ETAPA <> 'Finalizado' GROUP BY 1, 2 ORDER BY 1, 3 DESC"));
$r['campos_por_tipo'] = $tenta(function () use ($q) {
    $out = [];
    foreach ($q('SELECT x.CODTIPONAOCONFORMIDADE AS tipo, c.DSCCAMPO AS campo, c.LABEL AS rotulo FROM nc_tipo_de_naoconformidadexcampos x JOIN green_universal.md_tabelasxcampos c ON c.codcampo = x.codcampo ORDER BY 1, 3') as $l)
        $out[$l['tipo']][] = $l['campo'] . ' = ' . $l['rotulo'];
    return $out;
});
$r['objetos_nc_greenportal'] = $tenta(fn() => array_merge(
    array_map(static fn($l) => $l['TABLE_TYPE'] . ' ' . $l['TABLE_NAME'], $q("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW' AND (TABLE_NAME LIKE '%nc%' OR TABLE_NAME LIKE '%ocorrencia%') ORDER BY 1")),
    array_map(static fn($l) => $l['ROUTINE_TYPE'] . ' ' . $l['ROUTINE_NAME'], $q("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND (ROUTINE_NAME LIKE '%nc%' OR ROUTINE_NAME LIKE '%ocorrencia%') ORDER BY 1")),
    array_map(static fn($l) => 'TRIGGER ' . $l['TRIGGER_NAME'] . ' (' . $l['EVENT_OBJECT_TABLE'] . ')', $q("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE 'nc\\_%'"))
));
foreach (['nc_causas', 'nc_tipos_causas', 'nc_planodeacao', 'nc_planodeacao_historico', 'nc_listadistribuicao', 'nc_listadistribuicaoxusuarios', 'nc_workflow_historico', 'nc_tipo_de_naoconformidadexcampos'] as $t)
    $r['colunas'][$t] = $tenta(fn() => implode(', ', array_map(static fn($l) => $l['COLUMN_NAME'] . ' ' . $l['COLUMN_TYPE'], $q('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]))));
$r['colunas_ocorrencia_tipos'] = $tenta(fn() => implode(', ', array_map(static fn($l) => $l['COLUMN_NAME'] . ' ' . $l['COLUMN_TYPE'], $q("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_indicacao_ocorrencia' ORDER BY ORDINAL_POSITION"))));
$r['funcoes_legado'] = $tenta(function () use ($q, $leg, $pdo) {
    $out = [];
    foreach ($q("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND (ROUTINE_NAME LIKE 'getNC%' OR ROUTINE_NAME LIKE '%PlanoAcaoNC%')", [$leg]) as $f) {
        try { $s = $pdo->query('SHOW CREATE FUNCTION `' . $leg . '`.`' . $f['ROUTINE_NAME'] . '`'); $d = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor(); $out[$f['ROUTINE_NAME']] = $d['Create Function'] ?? null; }
        catch (Throwable $e) { $out[$f['ROUTINE_NAME']] = 'sem permissão para ler: ' . mb_substr($e->getMessage(), 0, 120); }
    }
    return $out;
});
$r['gestores_regra_atual'] = $tenta(fn() => $q("SELECT COUNT(*) AS n FROM usuarios u INNER JOIN perfis p ON p.codperfil=u.codperfil INNER JOIN grupos g ON g.codgrupo=u.codgrupo WHERE u.ativo=1 AND p.ativo=1 AND g.ativo=1 AND TRIM(UPPER(p.codigo))='GESTOR' AND TRIM(UPPER(g.nome))='GESTORES'")[0]['n'] ?? null);
$r['gestores_legado_usados'] = $tenta(fn() => $q("SELECT COUNT(DISTINCT CODUSUARIO_GESTOR) AS distintos, SUM(ETAPA = 'Com o Gestor') AS aguardando_gestor FROM nc_indicacao_ocorrencia WHERE data_ocorrencia >= '2025-01-01'")[0] ?? null);
$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_estrutura_' . $banco . '.json', $json);
echo $json . PHP_EOL;

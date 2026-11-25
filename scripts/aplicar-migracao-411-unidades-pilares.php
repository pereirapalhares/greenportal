<?php
declare(strict_types=1);

/*
 * Migração 411 — Onda 3 da Revisão G (25/11/2026): Unidade em Treinamento (TRE-104) e Infraestrutura (INF-119),
 * como já existe em Controle (documentosxunidades + botão Unidades).
 *   Tabelas de ligação ml_treinamentosxunidades (CODTREINAMENTO, CODUNIDADE) e ml_infraestruturaxunidades
 *   (CODINFRAESTRUTURA, CODUNIDADE); view vw_ml_rel_unidades (RELATION_ID/RELATION_LABEL, lista do botão Unidades).
 *   Views das telas refeitas (sobre <view>_base408): tipo (408) + campos (409) + [treinamento: resumo dos treinados (410)] + UNIDADES.
 *   A unidade vale para a regra Tipo + Unidade de Responsáveis e avisos (ResponsaveisRegras: ligações novas; rodar a 407 depois).
 * Com a 412 (plano de manutenção), a Infraestrutura ganha MANUTENCOES, MANUTENCOES_ATRASADAS e PROXIMA_MANUTENCAO_PREVISTA.
 * Só tabelas novas e views; nada é apagado. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-411-unidades-pilares.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/ResponsaveisRegras.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$rel = ['ok' => true, 'migracao' => '411', 'modo' => 'APLICAR' . ($simular ? ' (SIMULACAO)' : ''), 'bancos' => []];

// módulo => [tabela, chave, ligação, coluna do tipo, catálogo, descrição do tipo, views, campos 409, com resumo dos treinados]
$MOD = [
    'TREINAMENTOS' => ['ml_treinamentos', 'CODTREINAMENTO', 'ml_treinamentosxunidades', 'CODTIPOTREINAMENTO', 'ml_tipos_treinamento', 'DSCTIPOTREINAMENTO',
        ['vw_modulolegal_treinamentos_grid', 'vw_minhas_treinamentos_grid'], ['CARGA_HORARIA', 'PERIODO_RECICLAGEM', 'OBSERVACAO', 'CONTROLE_INFLUENCIA'], true],
    'INFRAESTRUTURA' => ['ml_infraestrutura', 'CODINFRAESTRUTURA', 'ml_infraestruturaxunidades', 'CODTIPOINFRAESTRUTURA', 'ml_tipos_infraestrutura', 'DSCTIPOINFRAESTRUTURA',
        ['vw_modulolegal_infraestrutura_grid', 'vw_minhas_infraestrutura_grid'], ['SETOR', 'CONDICOES', 'CLASSE', 'PROPRIEDADE', 'SITUACAO_INFRA', 'OBSERVACAO', 'CONTROLE_INFLUENCIA'], false],
];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        ResponsaveisRegras::limparCache();
        $cu = ResponsaveisRegras::colunas($pdo, 'unidades');
        if (!isset($cu['codunidade'], $cu['dscunidade'])) { $rel['bancos'][$banco] = 'ignorado: sem a tabela unidades'; continue; }

        // lista do botão Unidades
        $ativo = isset($cu['ativo']) ? ' WHERE COALESCE(u.`' . $cu['ativo'] . "`, 1) IN (1, '1', 'S', 'Sim')" : '';
        if (!$simular) $pdo->exec("CREATE OR REPLACE VIEW `vw_ml_rel_unidades` AS SELECT u.`CODUNIDADE` AS `RELATION_ID`, u.`DSCUNIDADE` AS `RELATION_LABEL` FROM `unidades` u$ativo");
        $info['vw_ml_rel_unidades'] = $simular ? 'será criada' : 'ok (' . (int)$pdo->query('SELECT COUNT(*) FROM `vw_ml_rel_unidades`')->fetchColumn() . ' unidade(s))';

        foreach ($MOD as $area => [$tab, $pk, $lig, $col, $catalogo, $dsc, $views, $campos, $treinados]) {
            $r = [];
            $ct = ResponsaveisRegras::colunas($pdo, $tab);
            if (!isset($ct[strtolower($pk)])) { $info[$area] = 'ignorado: sem ' . $tab; continue; }
            if (!isset($ct[strtolower($col)])) { $info[$area] = 'ignorado: rode antes a 408 e a 409'; continue; }
            if ($treinados && !ResponsaveisRegras::existe($pdo, 'vw_ml_treinamentos_participantes_grid')) { $info[$area] = 'ignorado: rode antes a 410'; continue; }
            // 1. ligação com unidades (mesma colação da tabela do módulo)
            if (!ResponsaveisRegras::existe($pdo, $lig)) {
                $s = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $s->execute([$tab]); $colacao = (string)$s->fetchColumn(); $s->closeCursor();
                if (!$simular) $pdo->exec("CREATE TABLE `$lig` (`$pk` INT NOT NULL, `CODUNIDADE` INT NOT NULL, PRIMARY KEY (`$pk`, `CODUNIDADE`), KEY `ix_{$lig}_unidade` (`CODUNIDADE`))
  ENGINE=InnoDB DEFAULT CHARSET=" . ($colacao !== '' ? explode('_', $colacao)[0] . " COLLATE=$colacao" : 'utf8mb4'));
                $r['ligacao'] = $simular ? "$lig será criada" : "$lig criada";
            } else $r['ligacao'] = "$lig já existe (" . (int)$pdo->query("SELECT COUNT(*) FROM `$lig`")->fetchColumn() . ' vínculo(s))';
            ResponsaveisRegras::limparCache();
            // 2. views das telas
            foreach ($views as $v) {
                $base = $v . '_base408';
                if (!ResponsaveisRegras::existe($pdo, $base)) { $r['views'][$v] = 'sem ' . $base . ' (rode a 408)'; continue; }
                if ($simular) { $r['views'][$v] = 'será refeita com UNIDADES'; continue; }
                $bc = ResponsaveisRegras::colunas($pdo, $base);
                $bpk = $bc[strtolower($pk)] ?? $pk;
                $sel = ["x.`$col` AS `$col`", "t.`$dsc` AS `$dsc`"];
                foreach ($campos as $n) if (!isset($bc[strtolower($n)])) $sel[] = "x.`$n` AS `$n`";
                $join = '';
                if ($treinados) {
                    $sel[] = 'COALESCE(r.`TREINADOS`, 0) AS `TREINADOS`';
                    $sel[] = 'COALESCE(r.`TREINADOS_VENCIDOS`, 0) AS `TREINADOS_VENCIDOS`';
                    $sel[] = 'COALESCE(r.`TREINADOS_A_VENCER`, 0) AS `TREINADOS_A_VENCER`';
                    $join = "\n  LEFT JOIN (SELECT `CODTREINAMENTO`, COUNT(*) AS `TREINADOS`, SUM(`STATUS_TREINADO` = 'Vencido') AS `TREINADOS_VENCIDOS`,
               SUM(`STATUS_TREINADO` = 'A vencer') AS `TREINADOS_A_VENCER` FROM `vw_ml_treinamentos_participantes_grid` GROUP BY `CODTREINAMENTO`) r ON r.`CODTREINAMENTO` = b.`$bpk`";
                }
                // Migração 412: resumo do plano de manutenção da infraestrutura (quando a 412 já rodou)
                if ($area === 'INFRAESTRUTURA' && ResponsaveisRegras::existe($pdo, 'vw_ml_infraestrutura_manutencoes_grid')) {
                    $sel[] = 'COALESCE(mm.`MANUTENCOES`, 0) AS `MANUTENCOES`';
                    $sel[] = 'COALESCE(mm.`MANUTENCOES_ATRASADAS`, 0) AS `MANUTENCOES_ATRASADAS`';
                    $sel[] = 'mm.`PROXIMA_MANUTENCAO_PREVISTA` AS `PROXIMA_MANUTENCAO_PREVISTA`';
                    $join .= "\n  LEFT JOIN (SELECT `CODINFRAESTRUTURA`, COUNT(*) AS `MANUTENCOES`, SUM(`STATUS_MANUTENCAO` = 'Atrasada') AS `MANUTENCOES_ATRASADAS`,
               MIN(CASE WHEN `DATA_REALIZADA` IS NULL THEN `DATA_PREVISTA` END) AS `PROXIMA_MANUTENCAO_PREVISTA` FROM `vw_ml_infraestrutura_manutencoes_grid` GROUP BY `CODINFRAESTRUTURA`) mm ON mm.`CODINFRAESTRUTURA` = b.`$bpk`";
                }
                if (!isset($bc['unidades'])) $sel[] = "(SELECT GROUP_CONCAT(DISTINCT u.`DSCUNIDADE` ORDER BY u.`DSCUNIDADE` SEPARATOR '; ') FROM `$lig` l JOIN `unidades` u ON u.`CODUNIDADE` = l.`CODUNIDADE` WHERE l.`$pk` = b.`$bpk`) AS `UNIDADES`";
                $pdo->exec("CREATE OR REPLACE VIEW `$v` AS SELECT b.*, " . implode(', ', $sel) . "
  FROM `$base` b LEFT JOIN `$tab` x ON x.`$pk` = b.`$bpk` LEFT JOIN `$catalogo` t ON t.`$col` = x.`$col`$join");
                $t0 = microtime(true); $q = (int)$pdo->query("SELECT COUNT(*) FROM `$v`")->fetchColumn();
                $r['views'][$v] = sprintf('ok (%d linha(s), %.2fs)', $q, microtime(true) - $t0);
            }
            $info[$area] = $r;
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$rel['proximo_passo'] = 'rodar scripts\\aplicar-migracao-407-responsaveis-legal.php (regra Tipo + Unidade passa a ler as unidades novas; refaz as telas "Minhas")';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_411_unidades_pilares' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

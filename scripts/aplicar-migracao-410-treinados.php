<?php
declare(strict_types=1);

/*
 * Migração 410 — Onda 3 da Revisão G (25/11/2026): lista de trabalhadores treinados de cada treinamento (item TRE-104).
 *   Tabela ml_treinamentos_participantes: matrícula, nome, cargo, setor, responsável (usuário), data de realização, dias de
 *   reação, classificação (Aprovado / Reprovado / Participou) e observação. Certificados no GED (tipo treinamentoparticipantes).
 *   View vw_ml_treinamentos_participantes_grid: + reciclagem do treinamento (meses), validade (realização + reciclagem),
 *   data de reação (validade − dias de reação; sem dias, 30) e status:
 *     Não realizado (sem data) · Reprovado · Realizado (treinamento sem reciclagem) · Vencido · A vencer (desde a reação) · Válido.
 *   Views dos treinamentos (as 2 da 408/409) refeitas com o resumo: TREINADOS, TREINADOS_VENCIDOS, TREINADOS_A_VENCER.
 * Só tabela nova e views; nada é apagado. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-410-treinados.php [--simular] [--banco=gcrown_dbp]
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
$rel = ['ok' => true, 'migracao' => '410', 'modo' => 'APLICAR' . ($simular ? ' (SIMULACAO)' : ''), 'bancos' => []];

$TAB = 'ml_treinamentos_participantes';
$VIEW = 'vw_ml_treinamentos_participantes_grid';
$VIEWS_TREIN = ['vw_modulolegal_treinamentos_grid', 'vw_minhas_treinamentos_grid'];
// campos do treinamento (409) que entram nas views refeitas, além do tipo (408)
$CAMPOS_409 = ['CARGA_HORARIA', 'PERIODO_RECICLAGEM', 'OBSERVACAO', 'CONTROLE_INFLUENCIA'];

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
        $ct = ResponsaveisRegras::colunas($pdo, 'ml_treinamentos');
        if (!isset($ct['codtreinamento'])) { $rel['bancos'][$banco] = 'ignorado: sem ml_treinamentos'; continue; }
        if (!isset($ct['periodo_reciclagem'])) { $rel['bancos'][$banco] = 'ignorado: rode antes a migração 409'; continue; }

        // 1. tabela (mesmo charset/colação de ml_treinamentos)
        if (!ResponsaveisRegras::existe($pdo, $TAB)) {
            $s = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $s->execute(['ml_treinamentos']); $col = (string)$s->fetchColumn(); $s->closeCursor();
            $charset = $col !== '' ? explode('_', $col)[0] : 'utf8mb4';
            if (!$simular) $pdo->exec("CREATE TABLE `$TAB` (
  `CODPARTICIPANTE` INT NOT NULL AUTO_INCREMENT,
  `CODTREINAMENTO` INT NOT NULL,
  `MATRICULA` VARCHAR(30) NULL,
  `NOME` VARCHAR(150) NULL,
  `CARGO` VARCHAR(100) NULL,
  `SETOR` VARCHAR(100) NULL,
  `CODUSUARIO` INT NULL,
  `DATA_REALIZACAO` DATE NULL,
  `DIAS_REACAO` INT NULL,
  `CLASSIFICACAO` VARCHAR(20) NULL,
  `OBSERVACAO` TEXT NULL,
  PRIMARY KEY (`CODPARTICIPANTE`),
  KEY `ix_mltp_treinamento` (`CODTREINAMENTO`),
  KEY `ix_mltp_realizacao` (`DATA_REALIZACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=$charset" . ($col !== '' ? " COLLATE=$col" : ''));
            $info['tabela'] = $simular ? "$TAB será criada" : "$TAB criada";
        } else $info['tabela'] = "$TAB já existe";
        ResponsaveisRegras::limparCache();

        // 2. view do grid dos treinados
        $sqlView = "CREATE OR REPLACE VIEW `$VIEW` AS
SELECT x.*,
  CASE WHEN x.`DATA_REALIZACAO` IS NULL THEN 'Não realizado'
       WHEN x.`CLASSIFICACAO` = 'Reprovado' THEN 'Reprovado'
       WHEN x.`VALIDADE_TREINADO` IS NULL THEN 'Realizado'
       WHEN x.`VALIDADE_TREINADO` < CURDATE() THEN 'Vencido'
       WHEN x.`REACAO_TREINADO` <= CURDATE() THEN 'A vencer'
       ELSE 'Válido' END AS `STATUS_TREINADO`,
  '' AS `ARQUIVO`
FROM (
  SELECT y.*, CASE WHEN y.`VALIDADE_TREINADO` IS NOT NULL THEN DATE_SUB(y.`VALIDADE_TREINADO`, INTERVAL COALESCE(y.`DIAS_REACAO`, 30) DAY) END AS `REACAO_TREINADO`
  FROM (
    SELECT p.`CODPARTICIPANTE`, p.`CODTREINAMENTO`, p.`MATRICULA`, p.`NOME`, p.`CARGO`, p.`SETOR`, p.`CODUSUARIO`, p.`DATA_REALIZACAO`,
      p.`DIAS_REACAO`, p.`CLASSIFICACAO`, p.`OBSERVACAO`, t.`DSCTREINAMENTO`, t.`PERIODO_RECICLAGEM`,
      CASE WHEN p.`DATA_REALIZACAO` IS NOT NULL AND t.`PERIODO_RECICLAGEM` > 0 THEN DATE_ADD(p.`DATA_REALIZACAO`, INTERVAL t.`PERIODO_RECICLAGEM` MONTH) END AS `VALIDADE_TREINADO`
    FROM `$TAB` p LEFT JOIN `ml_treinamentos` t ON t.`CODTREINAMENTO` = p.`CODTREINAMENTO`
  ) y
) x";
        if ($simular) $info['view'] = "$VIEW será criada";
        else {
            $pdo->exec($sqlView);
            $t0 = microtime(true); $q = (int)$pdo->query("SELECT COUNT(*) FROM `$VIEW`")->fetchColumn();
            $info['view'] = sprintf('%s ok (%d linha(s), %.2fs)', $VIEW, $q, microtime(true) - $t0);
        }

        // 3. views dos treinamentos: base408 + tipo + campos 409 + resumo dos treinados
        foreach ($VIEWS_TREIN as $v) {
            $base = $v . '_base408';
            if (!ResponsaveisRegras::existe($pdo, $base)) { $info['treinamentos'][$v] = 'sem ' . $base . ' (rode a 408)'; continue; }
            if ($simular) { $info['treinamentos'][$v] = 'será refeita com o resumo dos treinados'; continue; }
            $bc = ResponsaveisRegras::colunas($pdo, $base);
            $sel = ['x.`CODTIPOTREINAMENTO` AS `CODTIPOTREINAMENTO`', 't.`DSCTIPOTREINAMENTO` AS `DSCTIPOTREINAMENTO`'];
            foreach ($CAMPOS_409 as $n) if (!isset($bc[strtolower($n)])) $sel[] = "x.`$n` AS `$n`";
            $sel[] = 'COALESCE(r.`TREINADOS`, 0) AS `TREINADOS`';
            $sel[] = 'COALESCE(r.`TREINADOS_VENCIDOS`, 0) AS `TREINADOS_VENCIDOS`';
            $sel[] = 'COALESCE(r.`TREINADOS_A_VENCER`, 0) AS `TREINADOS_A_VENCER`';
            $pk = $bc['codtreinamento'] ?? 'CODTREINAMENTO';
            $pdo->exec("CREATE OR REPLACE VIEW `$v` AS SELECT b.*, " . implode(', ', $sel) . "
  FROM `$base` b LEFT JOIN `ml_treinamentos` x ON x.`CODTREINAMENTO` = b.`$pk`
  LEFT JOIN `ml_tipos_treinamento` t ON t.`CODTIPOTREINAMENTO` = x.`CODTIPOTREINAMENTO`
  LEFT JOIN (SELECT `CODTREINAMENTO`, COUNT(*) AS `TREINADOS`, SUM(`STATUS_TREINADO` = 'Vencido') AS `TREINADOS_VENCIDOS`,
               SUM(`STATUS_TREINADO` = 'A vencer') AS `TREINADOS_A_VENCER` FROM `$VIEW` GROUP BY `CODTREINAMENTO`) r ON r.`CODTREINAMENTO` = b.`$pk`");
            $t0 = microtime(true); $q = (int)$pdo->query("SELECT COUNT(*) FROM `$v`")->fetchColumn();
            $info['treinamentos'][$v] = sprintf('ok (%d linha(s), %.2fs)', $q, microtime(true) - $t0);
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$rel['proximo_passo'] = 'rodar scripts\\aplicar-migracao-407-responsaveis-legal.php (refaz as views "Minhas" da 361 com o resumo dos treinados)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_410_treinados' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

<?php
declare(strict_types=1);

/*
 * Migração 412 — Onda 3 da Revisão G (25/11/2026): plano de manutenção da Infraestrutura (item INF-119).
 *   Tabela ml_infraestrutura_manutencoes: atividade, periodicidade (meses), responsável (usuário), data prevista, data realizada,
 *   observação. Evidências no GED (tipo infraestruturamanutencoes).
 *   View vw_ml_infraestrutura_manutencoes_grid: + próxima data (realizada + periodicidade) e status:
 *     Realizada · Atrasada (prevista vencida) · Próxima (prevista em até 7 dias) · Programada · Sem data.
 * Depois rodar a 411 (monta as telas da Infraestrutura com o resumo das manutenções) e a 407 (avisos).
 * Só tabela nova e view; nada é apagado. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-412-manutencoes-infraestrutura.php [--simular] [--banco=gcrown_dbp]
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
$rel = ['ok' => true, 'migracao' => '412', 'modo' => 'APLICAR' . ($simular ? ' (SIMULACAO)' : ''), 'bancos' => []];
$TAB = 'ml_infraestrutura_manutencoes';
$VIEW = 'vw_ml_infraestrutura_manutencoes_grid';

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
        if (!isset(ResponsaveisRegras::colunas($pdo, 'ml_infraestrutura')['codinfraestrutura'])) { $rel['bancos'][$banco] = 'ignorado: sem ml_infraestrutura'; continue; }
        if (!ResponsaveisRegras::existe($pdo, $TAB)) {
            $s = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $s->execute(['ml_infraestrutura']); $col = (string)$s->fetchColumn(); $s->closeCursor();
            if (!$simular) $pdo->exec("CREATE TABLE `$TAB` (
  `CODMANUTENCAO` INT NOT NULL AUTO_INCREMENT,
  `CODINFRAESTRUTURA` INT NOT NULL,
  `ATIVIDADE` VARCHAR(300) NULL,
  `PERIODICIDADE_MESES` INT NULL,
  `CODUSUARIO` INT NULL,
  `DATA_PREVISTA` DATE NULL,
  `DATA_REALIZADA` DATE NULL,
  `OBSERVACAO` TEXT NULL,
  PRIMARY KEY (`CODMANUTENCAO`),
  KEY `ix_mlim_infraestrutura` (`CODINFRAESTRUTURA`),
  KEY `ix_mlim_prevista` (`DATA_PREVISTA`)
) ENGINE=InnoDB DEFAULT CHARSET=" . ($col !== '' ? explode('_', $col)[0] . " COLLATE=$col" : 'utf8mb4'));
            $info['tabela'] = $simular ? "$TAB será criada" : "$TAB criada";
        } else $info['tabela'] = "$TAB já existe";
        if ($simular) { $info['view'] = "$VIEW será criada"; $rel['bancos'][$banco] = $info; continue; }
        $pdo->exec("CREATE OR REPLACE VIEW `$VIEW` AS
SELECT m.`CODMANUTENCAO`, m.`CODINFRAESTRUTURA`, m.`ATIVIDADE`, m.`PERIODICIDADE_MESES`, m.`CODUSUARIO`, m.`DATA_PREVISTA`, m.`DATA_REALIZADA`, m.`OBSERVACAO`,
  i.`DSCINFRAESTRUTURA`,
  CASE WHEN m.`DATA_REALIZADA` IS NOT NULL AND m.`PERIODICIDADE_MESES` > 0 THEN DATE_ADD(m.`DATA_REALIZADA`, INTERVAL m.`PERIODICIDADE_MESES` MONTH) END AS `PROXIMA_MANUTENCAO`,
  CASE WHEN m.`DATA_REALIZADA` IS NOT NULL THEN 'Realizada'
       WHEN m.`DATA_PREVISTA` IS NULL THEN 'Sem data'
       WHEN m.`DATA_PREVISTA` < CURDATE() THEN 'Atrasada'
       WHEN m.`DATA_PREVISTA` <= CURDATE() + INTERVAL 7 DAY THEN 'Próxima'
       ELSE 'Programada' END AS `STATUS_MANUTENCAO`,
  '' AS `ARQUIVO`
FROM `$TAB` m LEFT JOIN `ml_infraestrutura` i ON i.`codinfraestrutura` = m.`CODINFRAESTRUTURA`");
        $info['view'] = "$VIEW ok (" . (int)$pdo->query("SELECT COUNT(*) FROM `$VIEW`")->fetchColumn() . ' linha(s))';
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$rel['proximo_passo'] = 'rodar scripts\\aplicar-migracao-411-unidades-pilares.php (telas da Infraestrutura com o resumo das manutenções) e depois scripts\\aplicar-migracao-407-responsaveis-legal.php (avisos)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_412_manutencoes_infraestrutura' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

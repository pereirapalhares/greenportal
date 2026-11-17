<?php
declare(strict_types=1);

/** Remove somente duplicidades exatas da auditoria de órfãos, preservando o primeiro registro. */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$banco = '';
foreach ($argv ?? [] as $arg) if (str_starts_with((string)$arg, '--banco=')) $banco = strtolower(trim(substr((string)$arg, 8)));
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) { fwrite(STDERR, "Informe --banco=g<empresa>_dbp.\n"); exit(2); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='migracao_registros_orfaos'")->fetchColumn();
if (!$exists) { echo json_encode(['ok' => true, 'banco' => $banco, 'removidos' => 0, 'motivo' => 'tabela inexistente'], JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(0); }
$pdo->exec('DROP PROCEDURE IF EXISTS sp_migracao_orfaos_deduplicar');
$pdo->exec("CREATE PROCEDURE sp_migracao_orfaos_deduplicar()
BEGIN
  DROP TEMPORARY TABLE IF EXISTS tmp_migracao_orfaos_manter;
  CREATE TEMPORARY TABLE tmp_migracao_orfaos_manter (dados VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL, manter BIGINT UNSIGNED NOT NULL, PRIMARY KEY (dados(255))) ENGINE=InnoDB;
  INSERT INTO tmp_migracao_orfaos_manter (dados, manter)
    SELECT dados, MIN(codorfao) FROM migracao_registros_orfaos GROUP BY dados;
  DELETE o FROM migracao_registros_orfaos o INNER JOIN tmp_migracao_orfaos_manter m ON m.dados=o.dados WHERE o.codorfao > m.manter;
  SELECT ROW_COUNT() AS removidos;
  DROP TEMPORARY TABLE IF EXISTS tmp_migracao_orfaos_manter;
END");
$rows = GreenPortalDatabase::call($pdo, 'CALL sp_migracao_orfaos_deduplicar()');
$remaining = (int)$pdo->query('SELECT COUNT(*) FROM migracao_registros_orfaos')->fetchColumn();
$result = ['ok' => true, 'banco' => $banco, 'removidos' => (int)($rows[0]['removidos'] ?? 0), 'restantes' => $remaining, 'regra' => 'duplicidade exata por dados; menor codorfao preservado'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

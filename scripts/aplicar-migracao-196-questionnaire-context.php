<?php
declare(strict_types=1);
require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$sql = file_get_contents(__DIR__ . '/../database/196_questionnaire_context_workflows.sql');
if ($sql === false) throw new RuntimeException('SQL 196 não localizado.');
$result = [];
foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec('USE `' . $database . '`; ' . $sql);
    $query = $pdo->query("SELECT d.codigo, COUNT(*) AS etapas FROM workflow_definicoes d JOIN workflow_etapas e ON e.codworkflow=d.codworkflow WHERE d.codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') GROUP BY d.codigo ORDER BY d.codigo");
    $result[$database] = $query->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode(['ok' => true, 'migration' => '196', 'targets' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/197_windows_task_scheduler_catalog.sql');
if ($sql === false) throw new RuntimeException('Migração 197 não localizada.');
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$pdo->exec($sql);
$count = (int)$pdo->query("SELECT COUNT(*) FROM servicos WHERE host='TASK_SCHEDULER' AND ativo=1")->fetchColumn();
echo json_encode(['ok' => true, 'migration' => '197', 'scheduled_services' => $count], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

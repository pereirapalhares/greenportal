<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$sql = file_get_contents(dirname(__DIR__) . '/database/183_green_tasks.sql');
if ($sql === false) {
    throw new RuntimeException('Migração 183 não localizada.');
}

$result = [];
foreach ($targets as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec('USE `' . $database . '`; ' . $sql);
    $result[$database] = [
        'tasks' => (int)$pdo->query('SELECT COUNT(*) FROM green_tasks')->fetchColumn(),
        'history' => (int)$pdo->query('SELECT COUNT(*) FROM green_task_history')->fetchColumn(),
        'migration' => (int)$pdo->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_TASKS_183'")->fetchColumn(),
    ];
}
echo json_encode(['ok' => true, 'migration' => '183', 'targets' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

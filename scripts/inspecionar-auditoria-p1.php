<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    echo $database . ': ' . implode(',', $pdo->query('SHOW COLUMNS FROM logs_auditoria')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
}

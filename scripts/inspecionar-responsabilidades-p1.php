<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    echo "--- {$database} ---\n";
    foreach (['responsabilidade_atribuicoes', 'responsabilidade_delegacoes', 'green_tasks', 'green_task_history'] as $table) {
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            echo $table . ': ' . implode(',', $columns) . "\n";
        } catch (Throwable) {
            echo $table . ': MISSING\n';
        }
    }
    foreach (['vw_responsabilidade_efetiva', 'vw_responsabilidade_atribuicoes_atuais', 'vw_responsabilidade_orfas'] as $view) {
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM `{$view}`")->fetchAll(PDO::FETCH_COLUMN);
            echo $view . ': ' . implode(',', $columns) . "\n";
        } catch (Throwable) {
            echo $view . ': MISSING\n';
        }
    }
}

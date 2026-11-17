<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    echo "--- {$database} ---\n";
    foreach (['menus', 'perfis', 'permissoes'] as $table) {
        echo $table . ': ' . implode(',', $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN)) . "\n";
    }
    $rows = $pdo->query("SELECT codmenu,codigo,label_pt,url,codmenu_pai,ativo FROM menus WHERE codigo LIKE '%RESPONS%' OR codigo='SEGURANCA' ORDER BY codmenu")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$connection = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$schemas = ['green_atlas_seguranca', 'gatlas_dbp', 'green_modelo_seguranca', 'gmodelo_dbp'];
$placeholders = implode(',', array_fill(0, count($schemas), '?'));
$statement = $connection->prepare("SELECT TABLE_SCHEMA,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLUMN_KEY
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA IN ($placeholders) AND TABLE_NAME='usuarios'
    ORDER BY TABLE_SCHEMA,ORDINAL_POSITION");
$statement->execute($schemas);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
$result = [];
foreach ($rows as $row) $result[$row['TABLE_SCHEMA']][] = $row;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

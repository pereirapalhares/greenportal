<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$legacy=GreenPortalDatabase::connect($env,'green_gd_americana','LEGADO_MODELO');
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$find=function(PDO $pdo):array{
    $rows=$pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (LOWER(COLUMN_NAME) LIKE '%arquivo%' OR LOWER(COLUMN_NAME) LIKE '%anexo%' OR LOWER(COLUMN_NAME) LIKE '%documento%' OR LOWER(COLUMN_NAME) LIKE '%evidencia%' OR LOWER(COLUMN_NAME) LIKE '%caminho%' OR LOWER(COLUMN_NAME) LIKE '%ficheiro%') ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $out=[];foreach($rows as $row)$out[$row['TABLE_NAME']][]=$row['COLUMN_NAME'];return $out;
};
echo json_encode(['legado'=>$find($legacy),'target'=>$find($target)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

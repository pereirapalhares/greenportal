<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$result=[];
foreach([['pdo'=>GreenPortalDatabase::connect($env,'green_gd_americana','LEGADO_MODELO'),'nome'=>'legado'],['pdo'=>GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT'),'nome'=>'target']] as $db){
  $rows=$db['pdo']->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (LOWER(TABLE_NAME) LIKE 'documentos%' OR LOWER(TABLE_NAME) LIKE 'ged_%' OR LOWER(TABLE_NAME) LIKE 'responsabilidade%' OR LOWER(TABLE_NAME) LIKE '%controle%' OR LOWER(TABLE_NAME) LIKE 'legislacaoxdocumentos') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
  foreach($rows as $table){$result[$db['nome']][$table]=(int)$db['pdo']->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();}
}
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

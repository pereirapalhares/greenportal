<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$legacy=GreenPortalDatabase::connect($env,'green_gd_americana','LEGADO_MODELO');
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$tables=function(PDO $pdo):array{return $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (LOWER(TABLE_NAME) LIKE '%document%' OR LOWER(TABLE_NAME) LIKE '%controle%' OR LOWER(TABLE_NAME) LIKE '%respons%' OR LOWER(TABLE_NAME) LIKE '%requisito%') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);};
$describe=function(PDO $pdo,array $names):array{$out=[];foreach($names as $name){try{$out[$name]=['count'=>(int)$pdo->query("SELECT COUNT(*) FROM `$name`")->fetchColumn(),'create'=>$pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM)[1]];}catch(Throwable $e){$out[$name]=['erro'=>$e->getMessage()];}}return $out;};
$lt=$tables($legacy);$tt=$tables($target);
echo json_encode(['legado'=>$describe($legacy,$lt),'target'=>$describe($target,$tt)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

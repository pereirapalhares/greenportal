<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();$sql=file_get_contents(dirname(__DIR__).'/database/185_menu_responsabilidade_substituicao.sql');if($sql===false)throw new RuntimeException('Migração 185 não localizada.');$result=[];
foreach(['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $database){$pdo=GreenPortalDatabase::connect($env,$database,'GPT');$pdo->exec('USE `'.$database.'`; '.$sql);$result[$database]=['menu'=>(int)$pdo->query("SELECT COUNT(*) FROM menus WHERE codigo='RESPONSABILIDADE_SUBSTITUICAO'")->fetchColumn(),'migration'=>(int)$pdo->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_RESPONSIBILITY_185'")->fetchColumn()];}
echo json_encode(['ok'=>true,'migration'=>'185','targets'=>$result],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

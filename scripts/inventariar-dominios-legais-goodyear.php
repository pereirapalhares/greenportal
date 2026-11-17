<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();$out=[];
$dbs=[['nome'=>'legado','db'=>'green_gd_americana','prefix'=>'LEGADO_MODELO'],['nome'=>'target','db'=>'ggdamericana_dbp','prefix'=>'GPT']];
$patterns=['ml_%','lais_%','lpro_%','laia_%','mm_%','responsabilidade_%','*treinamento*','*infraestrutura*'];
foreach($dbs as $cfg){$pdo=GreenPortalDatabase::connect($env,$cfg['db'],$cfg['prefix']);$where=[];$params=[];foreach($patterns as $i=>$pattern){$key='p'.$i;$where[]='LOWER(TABLE_NAME) LIKE :'.$key;$params[$key]=str_replace('*','%',$pattern);}$q=$pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (".implode(' OR ',$where).") ORDER BY TABLE_NAME");$q->execute($params);$out[$cfg['nome']]=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $table){$out[$cfg['nome']][$table]=(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();}}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

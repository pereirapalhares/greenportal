<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$dbs=['ggdamericana_dbp'=>'GPT','gmodelo_dbp'=>'GPT','gatlas_dbp'=>'GPT'];$tables=[];$columns=[];
foreach($dbs as $db=>$prefix){
    $pdo=GreenPortalDatabase::connect($env,$db,$prefix);
    $tables[$db]=array_fill_keys($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),true);
    $columns[$db]=[];
    foreach($pdo->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION") as $c){
        $default=(string)$c['COLUMN_DEFAULT'];
        if($c['COLUMN_NAME']==='schema_origem'&&str_starts_with($default,'g'))$default='__BANCO_TENANT__';
        $columns[$db][$c['TABLE_NAME'].'|'.$c['COLUMN_NAME']]=implode('|',[(string)$c['COLUMN_TYPE'],(string)$c['IS_NULLABLE'],$default]);
    }
}
$diff=[];foreach(['gmodelo_dbp','gatlas_dbp'] as $ref){$diff[$ref]=['tabelas_ausentes'=>array_values(array_diff(array_keys($tables[$ref]),array_keys($tables['ggdamericana_dbp']))),'tabelas_adicionais'=>array_values(array_diff(array_keys($tables['ggdamericana_dbp']),array_keys($tables[$ref]))),'colunas_diferentes'=>[]];foreach($columns[$ref] as $key=>$sig)if(isset($columns['ggdamericana_dbp'][$key])&&$columns['ggdamericana_dbp'][$key]!==$sig)$diff[$ref]['colunas_diferentes'][$key]=['novo'=>$columns['ggdamericana_dbp'][$key],'referencia'=>$sig];}
echo json_encode(['ok'=>count($diff['gmodelo_dbp']['tabelas_ausentes'])===0&&count($diff['gmodelo_dbp']['colunas_diferentes'])===0,'contagens_tabelas'=>array_map('count',$tables),'comparacao'=>$diff],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

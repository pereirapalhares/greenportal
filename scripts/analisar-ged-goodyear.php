<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$legacy=GreenPortalDatabase::connect($env,'green_gd_americana','LEGADO_MODELO');
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$out=[];
foreach ([['pdo'=>$legacy,'label'=>'legado','tables'=>['ged_documentos','documentos','li_licencas','legislacaoxdocumentos']],['pdo'=>$target,'label'=>'target','tables'=>['ged_documentos','documentos','li_licencas','content_documents','content_versions','content_document_links','legislacaoxdocumentos']]] as $set){
  foreach($set['tables'] as $table){
    try{$out[$set['label']][$table]=['count'=>(int)$set['pdo']->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(),'create'=>$set['pdo']->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1]];}catch(Throwable $e){$out[$set['label']][$table]=['erro'=>$e->getMessage()];}
  }
}
$out['legado']['ged_amostras']=$legacy->query('SELECT CODDOCUMENTO,CODDOCUMENTO_SUPERIOR,NOME_ARQUIVO,PATH_ARQUIVO FROM ged_documentos ORDER BY CODDOCUMENTO LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
$out['legado']['documentos_amostras']=$legacy->query('SELECT * FROM documentos ORDER BY CODDOCUMENTO LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
$out['legado']['licencas_com_arquivo']=$legacy->query("SELECT COUNT(*) FROM li_licencas")->fetchColumn();
$out['target']['licencas_com_arquivo']=$target->query("SELECT COUNT(*) FROM li_licencas WHERE NOME_ARQUIVO IS NOT NULL AND NOME_ARQUIVO<>''")->fetchColumn();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

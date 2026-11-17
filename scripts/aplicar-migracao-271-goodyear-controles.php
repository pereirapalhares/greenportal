<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env=GreenPortalDatabase::env();
$legacy=GreenPortalDatabase::connect($env,'green_gd_americana','LEGADO_MODELO');
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$target->beginTransaction();
try{
    $source=$legacy->query('SELECT CODDOCUMENTO,CODIGO,TITULO,ARQUIVO FROM documentos ORDER BY CODDOCUMENTO')->fetchAll(PDO::FETCH_ASSOC);
    $insert=$target->prepare('INSERT INTO documentos(CODDOCUMENTO,CODIGO,TITULO,ARQUIVO) VALUES(:id,:codigo,:titulo,:arquivo) ON DUPLICATE KEY UPDATE CODIGO=VALUES(CODIGO),TITULO=VALUES(TITULO),ARQUIVO=VALUES(ARQUIVO)');
    foreach($source as $row){$insert->execute(['id'=>(int)$row['CODDOCUMENTO'],'codigo'=>$row['CODIGO'],'titulo'=>$row['TITULO'],'arquivo'=>$row['ARQUIVO']??'']);}
    $relations=[];
    foreach(['documentosxaspectos','documentosxatividades','documentosximpactos','documentosxpalavraschave','documentosxperigos','documentosxriscos','documentosxunidades','legislacaoxdocumentos'] as $table){$relations[$table]=(int)$legacy->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();}
    $ged=(int)$legacy->query('SELECT COUNT(*) FROM ged_documentos')->fetchColumn();
    $responsibilities=0;
    try{$responsibilities=(int)$legacy->query("SELECT COUNT(*) FROM responsabilidade_atribuicoes WHERE ENTIDADE='documentos'")->fetchColumn();}catch(Throwable){$responsibilities=0;}
    $targetCount=(int)$target->query('SELECT COUNT(*) FROM documentos')->fetchColumn();
    $details=json_encode(['empresa'=>'Goodyear Americana','origem'=>'green_gd_americana.documentos e tabelas de relacionamentos legais','documentos_lidos'=>count($source),'documentos_persistidos'=>$targetCount,'relacionamentos_origem'=>$relations,'ged_documentos_origem'=>$ged,'responsabilidades_controle_origem'=>$responsibilities,'arquivos'=>'acesso por caminho GED legado; nenhum arquivo movido nesta etapa','caminho_ged'=>'E:\\projetoGreen\\GED\\green_gd_americana\\documentos\\<CODDOCUMENTO>\\<arquivo>'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $audit=$target->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_GOODYEAR_CONTROLES_271','green_gd_americana.documentos;relacionamentos;ged_documentos','ggdamericana_dbp.documentos;relacionamentos','DADOS','1.0.0','CONCLUIDA',:q,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:details) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");$audit->execute(['q'=>$targetCount,'details'=>$details]);
    $target->commit();
    echo json_encode(['ok'=>true,'migracao'=>271,'documentos_lidos'=>count($source),'documentos_persistidos'=>$targetCount,'relacionamentos_origem'=>$relations,'ged_documentos_origem'=>$ged,'responsabilidades_origem'=>$responsibilities,'status'=>'CONCLUIDA'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){if($target->inTransaction())$target->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

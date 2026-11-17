<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';

$env=GreenPortalDatabase::env();
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$storage=new GedStorageService($target,$env,['tenant'=>['banco_dados'=>'ggdamericana_dbp','banco_legado'=>'green_gd_americana']]);
$files=$storage->list('licencas',1,'api/gestao_arquivos.php?tipo=licencas&valor=1');
$audit=$target->query("SELECT codigo_migracao,status,quantidade_registros FROM registros_migracao WHERE codigo_migracao='GREEN_GOODYEAR_GED_270'")->fetch(PDO::FETCH_ASSOC);
$central=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$company=$central->query("SELECT e.codigo,e.status,c.status_migracao,c.sincronismo_ativo FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE c.banco_novo='ggdamericana_dbp' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>!empty($files)&&$audit&&$audit['status']==='CONCLUIDA'&&$company&&$company['status']==='INATIVA'&&(int)$company['sincronismo_ativo']===0,'arquivos_resolvidos_licenca_1'=>count($files),'primeiro_arquivo'=>$files[0]['name']??null,'auditoria'=>$audit,'empresa'=>$company],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

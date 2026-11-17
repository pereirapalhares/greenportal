<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__).'/classes/AssistenteAplicacaoService.php';
$env=GreenPortalDatabase::env();$db=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');$rows=$db->query("SELECT codsolicitacao FROM migracao_solicitacoes WHERE status='FINALIZADO_COM_SUCESSO' AND prompts_detalhes IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);$dir=dirname(__DIR__).'/storage/logs/migracao';foreach($rows as $id){$file=$dir.'/migracao_'.(int)$id.'_relatorio.json';if(!is_file($file))continue;$report=json_decode((string)file_get_contents($file),true);$detail=AssistenteAplicacaoService::chamarProcedure($db,'sp_migracao_prompts_detalhe_obter',[(int)$id])[0]??[];$report['prompts_ia']=array_merge($detail,['detalhes'=>json_decode((string)($detail['prompts_detalhes']??'{}'),true)]);file_put_contents($file,json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));}

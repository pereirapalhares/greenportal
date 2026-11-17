<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__).'/classes/AssistenteAplicacaoService.php';
$env=GreenPortalDatabase::env();$pdo=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$created=AssistenteAplicacaoService::chamarProcedure($pdo,'sp_migracao_solicitacao_criar',[110,1,json_encode(['green_goodyear_anchieta','green_goodyear_anchieta_seguranca']),'gganchieta_dbp','E:\\projetoGreen\\GED\\green_goodyear_anchieta','Teste visual e operacional Goodyear Anchieta']);
$id=(int)($created[0]['codsolicitacao']??0);AssistenteAplicacaoService::chamarProcedure($pdo,'sp_migracao_solicitacao_iniciar',[$id,1]);echo json_encode(['ok'=>$id>0,'codsolicitacao'=>$id,'empresa'=>'GOODYEAR_ANCHIETA','origens'=>['green_goodyear_anchieta','green_goodyear_anchieta_seguranca'],'destino'=>'gganchieta_dbp','ged'=>'E:\\projetoGreen\\GED\\green_goodyear_anchieta','status'=>'EM_EXECUCAO'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

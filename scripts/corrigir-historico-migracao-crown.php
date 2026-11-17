<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';
$env=GreenPortalDatabase::env();$central=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$id=(int)($argv[1]??7);$detalhes=json_encode(['motivo'=>'Solicitação finalizada indevidamente sem execução do Módulo Legal','acao'=>'Reprocessar após correção dos órfãos de licenças'],JSON_UNESCAPED_UNICODE);
AssistenteAplicacaoService::chamarProcedure($central,'sp_migracao_etapa_erro',[$id,'MIGRAR_MODULO_LEGAL','Solicitação histórica inválida: Módulo Legal não foi migrado.',$detalhes]);
echo json_encode(['ok'=>true,'solicitacao'=>$id,'status'=>'ERRO'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

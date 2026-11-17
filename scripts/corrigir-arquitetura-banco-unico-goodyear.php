<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$central=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$central->beginTransaction();
try{
    $central->exec('UPDATE empresa_migracao_config SET banco_novo_seguranca=banco_novo');
    $q=$central->query("SELECT e.codigo,e.nome,e.status,c.banco_novo,c.banco_novo_seguranca,c.status_migracao,c.sincronismo_ativo FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE c.banco_novo='ggdamericana_dbp' LIMIT 1");
    $company=$q->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Mapeamento Goodyear nao encontrado.');
    $audit=$target->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_PORTAL_BANCO_UNICO_272','arquitetura GreenPortal;gmodelo_dbp;gatlas_dbp','ggdamericana_dbp','ARQUITETURA','1.0.0','CONCLUIDA',:q,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:d) ON DUPLICATE KEY UPDATE status='CONCLUIDA',concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
    $details=json_encode(['regra'=>'um unico banco operacional por empresa','banco_goodyear'=>'ggdamericana_dbp','seguranca_no_mesmo_banco'=>true,'banco_novo_seguranca_configurado'=>'ggdamericana_dbp','banco_separado_nao_utilizado'=>true,'estrutura_referencia'=>['gmodelo_dbp','gatlas_dbp']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$audit->execute(['q'=>1,'d'=>$details]);
    $central->commit();
    echo json_encode(['ok'=>true,'empresa'=>$company,'auditoria'=>'GREEN_PORTAL_BANCO_UNICO_272'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){if($central->inTransaction())$central->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

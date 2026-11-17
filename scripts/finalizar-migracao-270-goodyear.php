<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$central=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$central->beginTransaction();
try{
    $q=$central->query("SELECT e.codempresa FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE c.banco_novo='ggdamericana_dbp' LIMIT 1");
    $company=$q->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Mapeamento Goodyear nao encontrado.');
    $u=$central->prepare("UPDATE empresa_migracao_config SET status_migracao='LEGAL_GED_CONCLUIDA',sincronismo_ativo=0,atualizado_em=CURRENT_TIMESTAMP WHERE codempresa=:id");$u->execute(['id'=>(int)$company['codempresa']]);
    $s=$central->prepare("UPDATE empresa SET status='INATIVA' WHERE codempresa=:id");$s->execute(['id'=>(int)$company['codempresa']]);
    $central->commit();
    $check=$central->prepare("SELECT e.codigo,e.status,c.status_migracao,c.sincronismo_ativo FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE e.codempresa=:id");$check->execute(['id'=>(int)$company['codempresa']]);
    echo json_encode(['ok'=>true,'resultado'=>$check->fetch(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){if($central->inTransaction())$central->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

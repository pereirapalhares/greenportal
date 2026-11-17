<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$central=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$row=$central->query("SELECT e.codempresa,e.codigo,e.status,c.banco_novo,c.banco_novo_seguranca,c.status_migracao,c.sincronismo_ativo FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE c.banco_novo='ggdamericana_dbp' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$row || $row['banco_novo']!==$row['banco_novo_seguranca']) throw new RuntimeException('Mapeamento Goodyear nao esta no banco unico.');
$det=json_encode(['backup'=>'runtime/backups/20261014_goodyear_pos_homologacao_275/ggdamericana_dbp.sql','legado_somente_leitura'=>'congelamento logico; sincronismo Goodyear desativado','agendamento'=>'nao localizado no Agendador do Windows; script existente nao possui par Goodyear','teste_operacional'=>'validacao transacional e GED registrada no relatorio'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$stmt=$target->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_GOODYEAR_POS_HOMOLOGACAO_275','green_gd_americana;green_gd_americana_seguranca','ggdamericana_dbp','HOMOLOGACAO','1.0.0','CONCLUIDA',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:det) ON DUPLICATE KEY UPDATE status='CONCLUIDA',concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
$stmt->execute(['det'=>$det]);
$u=$central->prepare("UPDATE empresa_migracao_config SET status_migracao='MIGRACAO_CONCLUIDA',sincronismo_ativo=0,atualizado_em=CURRENT_TIMESTAMP WHERE codempresa=:id");$u->execute(['id'=>(int)$row['codempresa']]);
$check=$central->prepare("SELECT e.codigo,e.status,c.banco_novo,c.banco_novo_seguranca,c.status_migracao,c.sincronismo_ativo FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE e.codempresa=:id");$check->execute(['id'=>(int)$row['codempresa']]);
echo json_encode(['ok'=>true,'resultado'=>$check->fetch(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

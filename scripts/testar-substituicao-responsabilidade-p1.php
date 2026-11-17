<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';
require_once dirname(__DIR__) . '/classes/ResponsibilityService.php';

$env=GreenPortalDatabase::env();$pdo=GreenPortalDatabase::connect($env,'gmodelo_dbp','GPT');$row=$pdo->query("SELECT CODATRIBUICAO,CODMODULO FROM responsabilidade_atribuicoes WHERE ATIVO=1 ORDER BY CODATRIBUICAO LIMIT 1")->fetch(PDO::FETCH_ASSOC);$user=(int)$pdo->query('SELECT codusuario FROM usuarios WHERE ativo=1 ORDER BY codusuario LIMIT 1')->fetchColumn();
if(!$row||$user<=0)throw new RuntimeException('Modelo não possui dados suficientes para o teste.');
$service=new ResponsibilityService($pdo,new TaskService($pdo));$id=$service->createSubstitution(['CODATRIBUICAO'=>(int)$row['CODATRIBUICAO'],'CODMODULO'=>(int)$row['CODMODULO'],'CODUSUARIO'=>$user,'VALIDO_DE'=>date('Y-m-d'),'VALIDO_ATE'=>date('Y-m-d',strtotime('+7 days')),'MOTIVO'=>'Teste P1 temporário de substituição.'],$user);$service->approveSubstitution($id,$user);$effective=count(array_filter($service->effectiveForUser($user),static fn(array $item):bool=>(int)($item['CODORIGEM']??0)===$id));$service->revokeSubstitution($id,$user);$pdo->prepare('DELETE FROM responsabilidade_substituicoes WHERE CODSUBSTITUICAO=:id')->execute(['id'=>$id]);echo json_encode(['ok'=>$effective===1,'codsubstituicao'=>$id,'efetiva_apos_aprovacao'=>$effective,'status_final'=>'REVOGADA','registro_temporario_removido'=>true,'auditoria_preservada'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__).'/classes/DocumentAiQueueService.php';
require_once dirname(__DIR__).'/classes/InteligenciaArtificialDocumento.php';
$pdo=GreenPortalDatabase::connect(GreenPortalDatabase::env(),'gatlas_dbp','GPT');$pdo->beginTransaction();
try{$q=new DocumentAiQueueService($pdo);$a=$q->enqueue(['chave_idempotencia'=>'TEST:IA:DOCUMENTO','tipo'=>'TESTE','entidade'=>'teste','registro_id'=>'1','arquivo'=>'teste.pdf','codanalise_ged'=>0,'codusuario'=>1,'idioma'=>'pt','prompt'=>'Retorne JSON.','texto'=>'Documento de teste.']);$b=$q->enqueue(['chave_idempotencia'=>'TEST:IA:DOCUMENTO','tipo'=>'TESTE','entidade'=>'teste','registro_id'=>'1','arquivo'=>'teste.pdf','codanalise_ged'=>0,'codusuario'=>1,'idioma'=>'pt','prompt'=>'Retorne JSON.','texto'=>'Documento de teste.']);$ok=(int)$a['codjob']===(int)$b['codjob'];$pdo->rollBack();echo json_encode(['ok'=>$ok,'idempotente'=>$ok],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit($ok?0:1);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

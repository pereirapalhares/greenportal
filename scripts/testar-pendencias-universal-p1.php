<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/classes/GreenPortalDatabase.php';require_once dirname(__DIR__).'/classes/PendenciasService.php';
$env=GreenPortalDatabase::env();$result=[];foreach(['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $d){$p=GreenPortalDatabase::connect($env,$d,'GPT');$uid=(int)$p->query('SELECT codusuario FROM usuarios WHERE ativo=1 ORDER BY codusuario LIMIT 1')->fetchColumn();$counts=(new PendenciasService($p))->contar($uid);$result[$d]=['usuario'=>$uid,'counts'=>$counts];}echo json_encode(['ok'=>true,'targets'=>$result],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

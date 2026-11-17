<?php
declare(strict_types=1);
$e=[];foreach(file(__DIR__.'/../.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){$l=trim($l);if($l===''||$l[0]==='#'||!str_contains($l,'='))continue;[$k,$v]=explode('=',$l,2);$e[trim($k)]=trim(trim($v),'"\'');}
$p=new PDO('mysql:host='.$e['GPT_DB_HOST'].';port='.$e['GPT_DB_PORT'].';dbname=guniversal_dbp;charset=utf8mb4',$e['GPT_DB_USERNAME'],$e['GPT_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$check=$p->prepare('SELECT codempresa,nome,banco_dados,status FROM empresa WHERE codigo=:codigo OR banco_dados=:banco');$check->execute(['codigo'=>'MODELO2','banco'=>'gmodelo2_dbp']);$existing=$check->fetchAll();
if($existing){echo json_encode(['created'=>false,'existing'=>$existing],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;exit;}
$insert=$p->prepare('INSERT INTO empresa (codigo,nome,banco_dados,status,observacoes) VALUES (:codigo,:nome,:banco,:status,:obs)');$insert->execute(['codigo'=>'MODELO2','nome'=>'Modelo2','banco'=>'gmodelo2_dbp','status'=>'INATIVA','obs'=>'Tenant de homologacao clonado de gmodelo_dbp; ativacao condicionada a validacao de isolamento.']);
echo json_encode(['created'=>true,'codempresa'=>(int)$p->lastInsertId(),'status'=>'INATIVA'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;

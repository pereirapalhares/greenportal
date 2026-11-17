<?php
$e=[];foreach(file(__DIR__.'/../.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){$l=trim($l);if($l===''||$l[0]==='#'||!str_contains($l,'='))continue;[$k,$v]=explode('=',$l,2);$e[trim($k)]=trim(trim($v),'"\'');}
$p=new PDO('mysql:host='.$e['GPT_DB_HOST'].';port='.$e['GPT_DB_PORT'].';dbname=gmodelo_dbp;charset=utf8mb4',$e['GPT_DB_USERNAME'],$e['GPT_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$s=$p->query("SELECT CODCONDICIONANTE,CODLICENCA,DSCCONDICIONANTE,FREQUENCIA,OBS FROM li_condicionantes WHERE DSCCONDICIONANTE LIKE 'TESTE%20260922' OR OBS LIKE 'Registro temporario%'");echo json_encode($s->fetchAll(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;

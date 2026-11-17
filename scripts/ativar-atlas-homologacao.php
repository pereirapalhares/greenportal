<?php
declare(strict_types=1);
$env=[];foreach(file(__DIR__.'/../.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),"\"'");}
$p=new PDO('mysql:host='.$env['GUNIVERSAL_DB_HOST'].';port='.$env['GUNIVERSAL_DB_PORT'].';dbname='.($env['GUNIVERSAL_DB_DATABASE']??'guniversal_dbp'),$env['GUNIVERSAL_DB_USERNAME'],$env['GUNIVERSAL_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$s=$p->prepare("UPDATE empresa SET status='ATIVA',observacoes=CONCAT(COALESCE(observacoes,''),' | Atlas ativada para teste de login homologacao 2026-10-03.') WHERE codigo='ATLAS' AND banco_dados='gatlas_dbp'");$s->execute();$row=$p->query("SELECT codigo,nome,banco_dados,status FROM empresa WHERE codigo='ATLAS'")->fetch();echo json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;

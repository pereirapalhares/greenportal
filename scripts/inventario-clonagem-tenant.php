<?php
declare(strict_types=1);
$env=[];
foreach(file(__DIR__.'/../.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),'"\'');}
$pdo=new PDO('mysql:host='.$env['GPT_DB_HOST'].';port='.$env['GPT_DB_PORT'].';dbname=information_schema;charset=utf8mb4',$env['GPT_DB_USERNAME'],$env['GPT_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$sql="SELECT table_schema,ROUND(SUM(data_length+index_length)/1024/1024,1) AS mb,COUNT(*) AS tables FROM tables WHERE table_schema IN ('gmodelo_dbp','gmodelo2_dbp') GROUP BY table_schema";
$rows=$pdo->query($sql)->fetchAll();
$companies=$pdo->query("SELECT nome,banco_dados,status FROM guniversal_dbp.empresa ORDER BY nome")->fetchAll();
echo json_encode(['databases'=>$rows,'companies'=>$companies],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;

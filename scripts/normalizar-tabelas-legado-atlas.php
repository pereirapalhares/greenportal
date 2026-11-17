<?php
declare(strict_types=1);
$env=[];foreach(file(__DIR__.'/../.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),"\"'");}
$p=new PDO('mysql:host='.$env['GPT_DB_HOST'].';port='.$env['GPT_DB_PORT'].';dbname=gatlas_dbp;charset=utf8mb4',$env['GPT_DB_USERNAME'],$env['GPT_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$target=array_flip($p->query("SELECT table_name FROM information_schema.tables WHERE table_schema='gatlas_dbp'")->fetchAll(PDO::FETCH_COLUMN));
$legacy=$p->query("SELECT table_name FROM information_schema.tables WHERE table_schema='gatlas_dbp' AND (table_name LIKE 'legado_atlas_%' OR table_name LIKE 'legado_seguranca_%')")->fetchAll(PDO::FETCH_COLUMN);$renamed=[];
foreach($legacy as $old){$prefix=str_starts_with($old,'legado_seguranca_')?'legado_seguranca_':'legado_atlas_';$new=substr($old,strlen($prefix));if(isset($target[$new])||$new==='')continue;$p->exec('RENAME TABLE `gatlas_dbp`.`'.$old.'` TO `gatlas_dbp`.`'.$new.'`');$target[$new]=true;$renamed[]=$old.' -> '.$new;}
echo json_encode(['renamed'=>$renamed,'count'=>count($renamed)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;

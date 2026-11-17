<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__).'/classes/WindowsServiceRuntime.php';
$env=GreenPortalDatabase::env();$sql=file_get_contents(dirname(__DIR__).'/database/284_fila_ia_documentos.sql');if($sql===false)throw new RuntimeException('Migração 284 não localizada.');
$runtime=new WindowsServiceRuntime($env,'ia',true);$out=[];$requested=null;foreach($argv??[] as $arg)if(str_starts_with($arg,'--database='))$requested=substr($arg,11);$databases=$requested!==null?[$requested]:$runtime->databases(null);
[$pre,$procedures]=array_pad(explode('DELIMITER $$',$sql,2),2,'');
foreach($databases as $db){try{$pdo=GreenPortalDatabase::connect($env,$db,'GPT');$pdo->exec($pre);foreach(explode('$$',$procedures) as $piece){$piece=str_replace('DELIMITER ;','',$piece);if(trim($piece)!=='')$pdo->exec($piece);}$out[$db]=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='green_ia_document_jobs'")->fetchColumn();}catch(Throwable $e){$out[$db]=['erro'=>$e->getMessage()];}}
$ok=true;foreach($out as $v)if(is_array($v)&&isset($v['erro']))$ok=false;echo json_encode(['ok'=>$ok,'migration'=>'284','databases'=>$out],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit($ok?0:1);

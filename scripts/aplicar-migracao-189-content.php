<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/classes/GreenPortalDatabase.php';
$e=GreenPortalDatabase::env();$sql=file_get_contents(dirname(__DIR__).'/database/189_green_content.sql');if($sql===false)throw new RuntimeException('Migração 189 não localizada.');$r=[];
foreach(['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $d){$p=GreenPortalDatabase::connect($e,$d,'GPT');$p->exec('USE `'.$d.'`; '.$sql);$r[$d]=['tabelas'=>(int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('content_documents','content_versions')")->fetchColumn(),'migration'=>(int)$p->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_CONTENT_189'")->fetchColumn(),'workflow'=>(int)$p->query("SELECT COUNT(*) FROM workflow_definicoes WHERE codigo='CONTENT_APPROVAL' AND publicado=1")->fetchColumn()];}
echo json_encode(['ok'=>true,'migration'=>'189','targets'=>$r],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

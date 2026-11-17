<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();$sql=file_get_contents(dirname(__DIR__).'/database/191_filas_assincronas_ia_ocr.sql');
if($sql===false)throw new RuntimeException('Migração 191 não localizada.');$out=[];
foreach(['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $db){$p=GreenPortalDatabase::connect($env,$db,'GPT');$p->exec('USE `'.$db.'`; '.$sql);$out[$db]=['ocr_jobs'=>(int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='green_ocr_jobs'")->fetchColumn(),'migration'=>(int)$p->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_ASYNC_AI_OCR_191'")->fetchColumn()];}
echo json_encode(['ok'=>true,'migration'=>'191','targets'=>$out],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

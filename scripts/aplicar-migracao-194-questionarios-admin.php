<?php
declare(strict_types=1);
require_once __DIR__.'/../classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();$universalDb=$env['GUNIVERSAL_DB_DATABASE']??'guniversal_dbp';$universal=GreenPortalDatabase::connect($env,$universalDb,'GUNIVERSAL');
$uSql=file_get_contents(__DIR__.'/../database/194_questionarios_admin_universal.sql');$tSql=file_get_contents(__DIR__.'/../database/194_questionarios_admin_tenant.sql');if($uSql===false||$tSql===false)throw new RuntimeException('SQL da migração 194 não localizado.');
$universal->exec('USE `'.$universalDb.'`; '.$uSql);$out=[];
foreach(['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $db){$pdo=GreenPortalDatabase::connect($env,$db,'GPT');$pdo->exec('USE `'.$db.'`; '.$tSql);$out[$db]=['permissoes'=>(int)$pdo->query("SELECT COUNT(*) FROM permissoes p JOIN ".$universalDb.".menus m ON m.codmenu=p.codmenu WHERE m.codigo='QUESTIONARIOS'")->fetchColumn(),'migracao'=>(int)$pdo->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_QUESTIONNAIRE_ADMIN_194'")->fetchColumn()];}
echo json_encode(['ok'=>true,'migration'=>'194','universal_menu'=>(int)$universal->query("SELECT COUNT(*) FROM menus WHERE codigo='QUESTIONARIOS'")->fetchColumn(),'targets'=>$out],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

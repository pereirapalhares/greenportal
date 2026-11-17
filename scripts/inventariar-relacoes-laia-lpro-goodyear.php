<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$e=GreenPortalDatabase::env();$p=GreenPortalDatabase::connect($e,'green_gd_americana','LEGADO_MODELO');$rows=$p->query("SELECT TABLE_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS colunas FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME IN ('CODLAIA','CODLPRO') OR LOWER(TABLE_NAME) LIKE 'laia%' OR LOWER(TABLE_NAME) LIKE 'lpro%') GROUP BY TABLE_NAME ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);$out=[];foreach($rows as $r){$out[$r['TABLE_NAME']]=['colunas'=>$r['colunas'],'count'=>(int)$p->query("SELECT COUNT(*) FROM `{$r['TABLE_NAME']}`")->fetchColumn()];}echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

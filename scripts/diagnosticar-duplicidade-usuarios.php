<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$e=GreenPortalDatabase::env();
foreach ([['gatlas_dbp','green_atlas_seguranca'],['gmodelo_dbp','green_modelo_seguranca']] as [$target,$legacy]) {
    $p=GreenPortalDatabase::connect($e,$target,'GPT');
    $q=$p->query("SELECT USERID,COUNT(*) qtd FROM `$legacy`.usuarios GROUP BY USERID HAVING COUNT(*)>1 ORDER BY qtd DESC,USERID");
    echo $target.PHP_EOL.json_encode($q->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}

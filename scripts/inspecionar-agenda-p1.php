<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();foreach(['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $d){$p=GreenPortalDatabase::connect($env,$d,'GPT');echo "---$d---\n";foreach(['ev_eventos','alertas','green_tasks'] as $t){try{echo $t.': '.implode(',', $p->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN))."\n";}catch(Throwable){echo "$t: MISSING\n";}}}

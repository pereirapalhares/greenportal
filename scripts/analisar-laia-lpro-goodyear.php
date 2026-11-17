<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$e=GreenPortalDatabase::env();$l=GreenPortalDatabase::connect($e,'green_gd_americana','LEGADO_MODELO');$t=GreenPortalDatabase::connect($e,'ggdamericana_dbp','GPT');$out=[];
foreach([['legado',$l,['laia','lpro']],['target',$t,['laia','lpro','laia_inventario','lpro_abrangencia','lpro_natureza_do_perigo','lpro_situacao','lpro_tipo_de_atividade']]] as [$n,$p,$tables])foreach($tables as $table){try{$out[$n][$table]=['count'=>(int)$p->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(),'create'=>$p->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'rows'=>$p->query("SELECT * FROM `$table` LIMIT 3")->fetchAll(PDO::FETCH_ASSOC)];}catch(Throwable $x){$out[$n][$table]=['erro'=>$x->getMessage()];}}echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

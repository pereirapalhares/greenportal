<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$e=GreenPortalDatabase::env();$l=GreenPortalDatabase::connect($e,'green_gd_americana','LEGADO_MODELO');$t=GreenPortalDatabase::connect($e,'ggdamericana_dbp','GPT');
$spec=[['origem',$l,'infraestrutura'],['origem',$l,'lais_revisao'],['destino',$t,'ml_infraestrutura'],['destino',$t,'lais_revisao'],['destino',$t,'ml_treinamentos'],['destino',$t,'mm_monitoramento'],['destino',$t,'mm_controleequipamento'],['destino',$t,'mm_controle_calibracao'],['destino',$t,'responsabilidade_atribuicoes']];$out=[];
foreach($spec as [$label,$pdo,$table]){try{$out[$label][$table]=['count'=>(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(),'create'=>$pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'rows'=>$pdo->query("SELECT * FROM `$table` LIMIT 5")->fetchAll(PDO::FETCH_ASSOC)];}catch(Throwable $x){$out[$label][$table]=['erro'=>$x->getMessage()];}}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

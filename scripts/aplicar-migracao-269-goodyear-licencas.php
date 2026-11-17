<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$target = GreenPortalDatabase::connect($env, 'ggdamericana_dbp', 'GPT');
$legacy = GreenPortalDatabase::connect($env, 'green_gd_americana', 'LEGADO_MODELO');
$legislation = GreenPortalDatabase::connect($env, 'glegislacao_dbp', 'GLEGISLACAO');
$target->beginTransaction();

try {
    $validLaws=[]; foreach($legislation->query('SELECT CODLEGISLACAO FROM legislacao') as $row)$validLaws[(int)$row['CODLEGISLACAO']]=true;
    $validUnits=[]; foreach($target->query('SELECT CODUNIDADE FROM unidades') as $row)$validUnits[(int)$row['CODUNIDADE']]=true;
    $validUsers=[]; foreach($target->query('SELECT codusuario FROM usuarios') as $row)$validUsers[(int)$row['codusuario']]=true;
    $validGroups=[]; foreach($target->query('SELECT codgrupo FROM grupos') as $row)$validGroups[(int)$row['codgrupo']]=true;
    $licenseColumns=['CODLICENCA','DSCLICENCA','DATA_VALIDADE','DATA_REACAO','codunidade','codusuario','data_protocolo','codgrupo','codgrupo_validade','codgrupo_reacao','codgrupo_protocolo','emprotocolo','codlista','protocolado_em','protocolado_no_prazo','qtd_dias_em_protocolo','CODSTATUS','CODCLASSIFICACAO','CODFREQUENCIA','observacao','data_de_alteracao','atividade','codgrupo_acesso'];
    $quoted=implode(',',array_map(static fn(string $c):string=>'`'.$c.'`',$licenseColumns)); $marks=implode(',',array_map(static fn(string $c):string=>':'.$c,$licenseColumns)); $updates=implode(',',array_map(static fn(string $c):string=>'`'.$c.'`=VALUES(`'.$c.'`)',$licenseColumns));
    $insert=$target->prepare("INSERT INTO li_licencas ($quoted) VALUES ($marks) ON DUPLICATE KEY UPDATE $updates"); $loaded=0; $adjusted=['codunidade'=>0,'codusuario'=>0,'grupos'=>0];
    $groupColumns=['codgrupo','codgrupo_validade','codgrupo_reacao','codgrupo_protocolo','codgrupo_acesso'];
    foreach($legacy->query("SELECT $quoted FROM li_licencas ORDER BY CODLICENCA") as $row){
        foreach(['codunidade'] as $column){$value=(int)($row[$column]??0);if($value<=0||!isset($validUnits[$value])){$row[$column]=null;$adjusted[$column]++;}}
        $user=(int)($row['codusuario']??0);if($user<=0||!isset($validUsers[$user])){$row['codusuario']=null;$adjusted['codusuario']++;}
        foreach($groupColumns as $column){$group=(int)($row[$column]??0);if($group<=0||!isset($validGroups[$group])){$row[$column]=null;$adjusted['grupos']++;}}
        $params=[];foreach($licenseColumns as $column)$params[$column]=$row[$column]??null;$insert->execute($params);$loaded++;
    }
    $relInsert=$target->prepare('INSERT IGNORE INTO legislacaoxlicencas (CODLEGISLACAO,CODLICENCA,codunidade) VALUES (:law,:license,:unit)');$relExists=$target->prepare('SELECT 1 FROM legislacaoxlicencas WHERE CODLEGISLACAO <=> :law AND CODLICENCA <=> :license AND codunidade <=> :unit LIMIT 1');$relLoaded=0;$relIgnored=0;
    foreach($legacy->query('SELECT CODLEGISLACAO,CODLICENCA,codunidade FROM legislacaoxlicencas') as $row){$law=(int)$row['CODLEGISLACAO'];$license=(int)$row['CODLICENCA'];$unit=(int)($row['codunidade']??0);if(!isset($validLaws[$law])||!$target->query('SELECT 1 FROM li_licencas WHERE CODLICENCA='.$license.' LIMIT 1')->fetchColumn()||($unit>0&&!isset($validUnits[$unit]))){$relIgnored++;continue;}$params=['law'=>$law,'license'=>$license,'unit'=>$row['codunidade']];$relExists->execute($params);if($relExists->fetchColumn())continue;$relInsert->execute($params);$relLoaded++;}
    $licenseCount=(int)$target->query('SELECT COUNT(*) FROM li_licencas')->fetchColumn();$relationCount=(int)$target->query('SELECT COUNT(*) FROM legislacaoxlicencas')->fetchColumn();
    $details=json_encode(['empresa'=>'Goodyear Americana','origem'=>'green_gd_americana.li_licencas;green_gd_americana.legislacaoxlicencas','licencas_lidas'=>$loaded,'licencas_persistidas'=>$licenseCount,'relacionamentos_persistidos'=>$relationCount,'relacionamentos_ignorados_orfaos'=>$relIgnored,'ajustes_referencias'=>$adjusted,'codtipodocumento'=>'NULL; classificacao documental sera feita na etapa GED/tipos documentais','arquivos'=>'nao migrados nesta etapa'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $audit=$target->prepare("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('GREEN_GOODYEAR_LICENCAS_269','green_gd_americana.li_licencas;legislacaoxlicencas','ggdamericana_dbp.li_licencas;legislacaoxlicencas','DADOS','1.0.0','CONCLUIDA',:q,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:details) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");$audit->execute(['q'=>$licenseCount+$relationCount,'details'=>$details]);
    $target->commit();echo json_encode(['ok'=>true,'migracao'=>269,'licencas_lidas'=>$loaded,'licencas_persistidas'=>$licenseCount,'relacionamentos_persistidos'=>$relationCount,'relacionamentos_ignorados'=>$relIgnored,'ajustes_referencias'=>$adjusted,'status'=>'CONCLUIDA'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $exception){if($target->inTransaction())$target->rollBack();fwrite(STDERR,$exception->getMessage().PHP_EOL);exit(1);}

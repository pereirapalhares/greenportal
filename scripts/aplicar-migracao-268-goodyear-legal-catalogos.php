<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$target = GreenPortalDatabase::connect($env, 'ggdamericana_dbp', 'GPT');
$legacy = GreenPortalDatabase::connect($env, 'green_gd_americana', 'LEGADO_MODELO');
$legislation = GreenPortalDatabase::connect($env, 'glegislacao_dbp', 'GLEGISLACAO');
$target->beginTransaction();

try {
    $validLaws = [];
    foreach ($legislation->query('SELECT CODLEGISLACAO FROM legislacao') as $law) $validLaws[(int)$law['CODLEGISLACAO']] = true;
    $validUnits = [];
    $catalogs = [
        'unidades'=>['CODUNIDADE','DSCUNIDADE','usado_em_manutencao_legal','tipo_de_rcl'],
        'areas'=>['CODAREA','DSCAREA'],
        'atividades'=>['CODATIVIDADE','DSCATIVIDADE'],
        'aspectos'=>['CODASPECTO','DSCASPECTO'],
        'impactos'=>['CODIMPACTO','DSCIMPACTO'],
        'destinos'=>['CODDESTINO','DSCDESTINO'],
        'perigos'=>['CODPERIGO','DSCPERIGO'],
        'riscos'=>['CODRISCO','DSCRISCO'],
    ];
    $loaded = []; $skipped = [];
    foreach ($catalogs as $table=>$columns) {
        $quoted = implode(',', array_map(static fn(string $column): string => '`'.$column.'`', $columns));
        $marks = implode(',', array_map(static fn(string $column): string => ':'.$column, $columns));
        $insert = $target->prepare("INSERT IGNORE INTO `$table` ($quoted) VALUES ($marks)");
        $count = 0;
        foreach ($legacy->query("SELECT $quoted FROM `$table`") as $row) { $params=[]; foreach ($columns as $column) $params[$column]=$row[$column]??null; $insert->execute($params); $count++; }
        $loaded[$table] = $count;
        if ($table === 'unidades') foreach ($legacy->query('SELECT CODUNIDADE FROM unidades') as $unit) $validUnits[(int)$unit['CODUNIDADE']] = true;
    }
    $relations = [
        ['table'=>'legislacaoxaspecto','columns'=>['CODLEGISLACAO','CODASPECTO','codunidade'],'catalog'=>'aspectos','key'=>'CODASPECTO'],
        ['table'=>'legislacaoxatividade','columns'=>['CODLEGISLACAO','CODATIVIDADE','codunidade'],'catalog'=>'atividades','key'=>'CODATIVIDADE'],
        ['table'=>'legislacaoximpacto','columns'=>['CODLEGISLACAO','CODIMPACTO','codunidade'],'catalog'=>'impactos','key'=>'CODIMPACTO'],
        ['table'=>'legislacaoxperigo','columns'=>['CODLEGISLACAO','CODPERIGO','codunidade'],'catalog'=>'perigos','key'=>'CODPERIGO'],
        ['table'=>'legislacaoxrisco','columns'=>['CODLEGISLACAO','CODRISCO','codunidade'],'catalog'=>'riscos','key'=>'CODRISCO'],
        ['table'=>'legislacaoxpoder','columns'=>['CODLEGISLACAO','poder','codunidade']],
    ];
    foreach ($relations as $relation) {
        $table=$relation['table']; $columns=$relation['columns']; $quoted=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',$columns)); $marks=implode(',',array_map(static fn(string $column):string=>':'.$column,$columns));
        $insert=$target->prepare("INSERT IGNORE INTO `$table` ($quoted) VALUES ($marks)"); $identity=implode(' AND ',array_map(static fn(string $column):string=>'`'.$column.'` <=> :'.$column,$columns)); $exists=$target->prepare("SELECT 1 FROM `$table` WHERE $identity LIMIT 1"); $count=0; $ignored=0;
        foreach ($legacy->query("SELECT $quoted FROM `$table`") as $row) {
            $law=(int)($row['CODLEGISLACAO']??0); $unit=array_key_exists('codunidade',$row)?(int)$row['codunidade']:(int)($row['CODUNIDADE']??0); $catalogOk=true;
            if (isset($relation['catalog'])) { $catalogOk=(bool)$target->query("SELECT 1 FROM `{$relation['catalog']}` WHERE `{$relation['key']}`=".(int)$row[$relation['key']].' LIMIT 1')->fetchColumn(); }
            if (!isset($validLaws[$law]) || !$catalogOk || (($unit>0) && !isset($validUnits[$unit]))) { $ignored++; continue; }
            $params=[]; foreach($columns as $column)$params[$column]=$row[$column]??null; $exists->execute($params); if($exists->fetchColumn()) continue; $insert->execute($params); $count++;
        }
        $loaded[$table]=$count; $skipped[$table]=$ignored;
    }
    $statusRows = 0; $statusIgnored=0;
    $insertStatus=$target->prepare('INSERT IGNORE INTO legislacaoxsituacao (CODSITUACAO,CODLEGISLACAO,dscsituacao,atendido,CODUSUARIO,OBS,codunidade,data_de_avaliacao,data_de_vencimento_avaliacao,CODLEGISLACAOCODUNIDADE) VALUES (:id,:law,:description,:attended,:user,:obs,:unit,:evaluated,:due,:key)');
    foreach ($legacy->query('SELECT CODSITUACAO,CODLEGISLACAO,dscsituacao,atendido,CODUSUARIO,OBS,codunidade,data_de_avaliacao,data_de_vencimento_avaliacao,CODLEGISLACAOCODUNIDADE FROM legislacaoxsituacao') as $row) {
        $law=(int)$row['CODLEGISLACAO']; $unit=(int)($row['codunidade']??0);
        if (!(($validLaws[$law]??false)) || ($unit>0 && !isset($validUnits[$unit]))) { $statusIgnored++; continue; }
        $insertStatus->execute(['id'=>$row['CODSITUACAO'],'law'=>$law,'description'=>$row['dscsituacao'],'attended'=>$row['atendido'],'user'=>$row['CODUSUARIO'],'obs'=>$row['OBS'],'unit'=>$row['codunidade'],'evaluated'=>$row['data_de_avaliacao'],'due'=>$row['data_de_vencimento_avaliacao'],'key'=>$row['CODLEGISLACAOCODUNIDADE']]); $statusRows++;
    }
    $loaded['legislacaoxsituacao']=$statusRows; $skipped['legislacaoxsituacao']=$statusIgnored;
    $companyRows=0; $companyIgnored=0;
    $insertCompany=$target->prepare('INSERT IGNORE INTO legislacaoxempresa (CODLEGISLACAXEMPRESA,CODLEGISLACAO,CODEMPRESA,codunidade,atendido,tipo_de_lei,codlegislacaocodunidade) VALUES (:id,:law,20,:unit,:attended,:type,:key)');
    foreach ($legacy->query('SELECT CODLEGISLACAXEMPRESA,CODLEGISLACAO,codunidade,atendido,tipo_de_lei,codlegislacaocodunidade FROM legislacaoxempresa WHERE CODEMPRESA=20') as $row) {
        $law=(int)$row['CODLEGISLACAO']; $unit=(int)($row['codunidade']??0);
        if (!(($validLaws[$law]??false)) || ($unit>0 && !isset($validUnits[$unit]))) { $companyIgnored++; continue; }
        $insertCompany->execute(['id'=>$row['CODLEGISLACAXEMPRESA'],'law'=>$law,'unit'=>$row['codunidade'],'attended'=>$row['atendido'],'type'=>$row['tipo_de_lei'],'key'=>$row['codlegislacaocodunidade']]); $companyRows++;
    }
    $loaded['legislacaoxempresa']=$companyRows; $skipped['legislacaoxempresa']=$companyIgnored;
    $persisted=[]; foreach(array_keys($loaded) as $table) $persisted[$table]=(int)$target->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $totalLoaded=array_sum($loaded); $totalPersisted=array_sum($persisted); $totalSkipped=array_sum($skipped);
    $details=json_encode(['empresa'=>'Goodyear Americana','origem'=>'green_gd_americana','leis_validas'=>count($validLaws),'lidos_validos'=>$loaded,'persistidos'=>$persisted,'ignorados_orfaos'=>$skipped,'escopo'=>'catalogos e relacionamentos de legislacao; licencas, documentos e GED ficam para etapas posteriores'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $audit=$target->prepare("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('GREEN_GOODYEAR_LEGAL_CATALOGOS_268','green_gd_americana.catalogos;relacionamentos','ggdamericana_dbp.catalogos;legislacaox*','DADOS','1.0.0','CONCLUIDA',:q,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:details) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
    $audit->execute(['q'=>$totalPersisted,'details'=>$details]);
    $target->commit();
    echo json_encode(['ok'=>true,'migracao'=>268,'carregados'=>$loaded,'persistidos'=>$persisted,'ignorados_orfaos'=>$skipped,'total_lido'=>$totalLoaded,'total_persistido'=>$totalPersisted,'total_ignorado'=>$totalSkipped,'status'=>'CONCLUIDA'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) { if($target->inTransaction())$target->rollBack(); fwrite(STDERR,$exception->getMessage().PHP_EOL); exit(1); }

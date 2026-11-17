<?php
$e=[]; foreach(file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){if(strpos($l,'=')!==false){[$k,$v]=explode('=',$l,2);$e[trim($k)]=trim($v);}}
$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
$g=new PDO("mysql:host={$e['GPT_DB_HOST']};port={$e['GPT_DB_PORT']};dbname=gmodelo_dbp;charset=utf8mb4",$e['GPT_DB_USERNAME'],$e['GPT_DB_PASSWORD'],$opt);
$o=new PDO("mysql:host={$e['LEGADO_MODELO_DB_HOST']};port={$e['LEGADO_MODELO_DB_PORT']};dbname=green_modelo;charset=latin1",$e['LEGADO_MODELO_DB_USERNAME'],$e['LEGADO_MODELO_DB_PASSWORD'],$opt);
$q=function($p,$s){return (int)$p->query($s)->fetchColumn();}; $out=[];
$out['atividade_target']=$q($g,'SELECT COUNT(*) FROM legislacaoxatividade'); $out['atividade_legacy']=$q($o,'SELECT COUNT(*) FROM legislacaoxatividade');
$out['risco_target']=$q($g,'SELECT COUNT(*) FROM legislacaoxrisco'); $out['risco_legacy']=$q($o,'SELECT COUNT(*) FROM legislacaoxrisco');
$out['empresa_target']=$q($g,'SELECT COUNT(*) FROM legislacaoxempresa'); $out['empresa_legacy']=$q($o,'SELECT COUNT(*) FROM legislacaoxempresa');
$out['atividade_dup']=$q($g,'SELECT COUNT(*) FROM (SELECT CODLEGISLACAO,CODATIVIDADE,codunidade FROM legislacaoxatividade GROUP BY CODLEGISLACAO,CODATIVIDADE,codunidade HAVING COUNT(*)>1) x');
$out['risco_dup']=$q($g,'SELECT COUNT(*) FROM (SELECT CODLEGISLACAO,CODRISCO,codunidade FROM legislacaoxrisco GROUP BY CODLEGISLACAO,CODRISCO,codunidade HAVING COUNT(*)>1) x');
$out['empresa_sem_unidade']=$q($g,'SELECT COUNT(*) FROM legislacaoxempresa WHERE codunidade IS NULL OR codlegislacaocodunidade IS NULL');
$out['empresa_backup']=$q($g,'SELECT COUNT(*) FROM zz_backup_153_legislacaoxempresa_sem_unidade');
$out['registro_restaurado']=$q($g,"SELECT COUNT(*) FROM legislacaoxempresa WHERE CODLEGISLACAO=1 AND CODEMPRESA=4 AND codunidade=1 AND codlegislacaocodunidade='11'");
$out['atividade_missing']=$q($o,'SELECT COUNT(*) FROM legislacaoxatividade a WHERE NOT EXISTS (SELECT 1 FROM gmodelo_dbp.legislacaoxatividade b WHERE b.CODLEGISLACAO=a.CODLEGISLACAO AND b.CODATIVIDADE=a.CODATIVIDADE AND b.codunidade=a.codunidade)');
$out['atividade_extra']=$q($g,'SELECT COUNT(*) FROM legislacaoxatividade b WHERE NOT EXISTS (SELECT 1 FROM green_modelo.legislacaoxatividade a WHERE a.CODLEGISLACAO=b.CODLEGISLACAO AND a.CODATIVIDADE=b.CODATIVIDADE AND a.codunidade=b.codunidade)');
$out['risco_missing']=$q($o,'SELECT COUNT(*) FROM legislacaoxrisco a WHERE NOT EXISTS (SELECT 1 FROM gmodelo_dbp.legislacaoxrisco b WHERE b.CODLEGISLACAO=a.CODLEGISLACAO AND b.CODRISCO=a.CODRISCO AND b.codunidade=a.codunidade)');
$out['risco_extra']=$q($g,'SELECT COUNT(*) FROM legislacaoxrisco b WHERE NOT EXISTS (SELECT 1 FROM green_modelo.legislacaoxrisco a WHERE a.CODLEGISLACAO=b.CODLEGISLACAO AND a.CODRISCO=b.CODRISCO AND a.codunidade=b.codunidade)');
$out['empresa_missing']=$q($o,'SELECT COUNT(*) FROM legislacaoxempresa a WHERE NOT EXISTS (SELECT 1 FROM gmodelo_dbp.legislacaoxempresa b WHERE b.CODLEGISLACAO=a.CODLEGISLACAO AND b.CODEMPRESA=a.CODEMPRESA AND b.codunidade=a.codunidade AND b.codlegislacaocodunidade=a.codlegislacaocodunidade)');
$out['empresa_extra']=$q($g,'SELECT COUNT(*) FROM legislacaoxempresa b WHERE NOT EXISTS (SELECT 1 FROM green_modelo.legislacaoxempresa a WHERE a.CODLEGISLACAO=b.CODLEGISLACAO AND b.CODEMPRESA=a.CODEMPRESA AND b.codunidade=a.codunidade AND b.codlegislacaocodunidade=a.codlegislacaocodunidade)');
$out['unique_atividade']=$q($g,"SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema='gmodelo_dbp' AND table_name='legislacaoxatividade' AND index_name='uq_lxat_legislacao_atividade_unidade'");
$out['unique_risco']=$q($g,"SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema='gmodelo_dbp' AND table_name='legislacaoxrisco' AND index_name='uq_lxr_legislacao_risco_unidade'");
foreach(['vw_modulolegal_correlacionamento_ambiental_grid','vw_modulolegal_correlacionamento_ambiental_atividades','vw_modulolegal_correlacionamento_ambiental_impactos','vw_modulolegal_correlacionamento_ambiental_licencas','vw_modulolegal_correlacionamento_ambiental_documentos'] as $v)$out[$v]=$q($g,"SELECT COUNT(*) FROM `$v`");
echo json_encode($out,JSON_UNESCAPED_UNICODE);

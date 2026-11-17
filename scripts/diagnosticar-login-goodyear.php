<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$central=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$tenant=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$legacySecurity=GreenPortalDatabase::connect($env,'green_gd_americana_seguranca','LEGADO_SEGURANCA');
$company=$central->query("SELECT e.codempresa,e.codigo,e.nome,e.status,e.banco_dados,c.banco_legado,c.banco_novo FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE c.banco_novo='ggdamericana_dbp' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$fetch=function(PDO $pdo,string $sql):?array{$r=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC);return $r?:null;};
$legacy=$fetch($legacySecurity,"SELECT USERID,SENHA FROM usuarios WHERE USERID='admin' LIMIT 1");
$main=$fetch($tenant,"SELECT codusuario,userid,ativo,senha,senha_usa_hash,codperfil FROM usuarios WHERE userid='admin' LIMIT 1");
$profile=$main? $fetch($tenant,'SELECT codperfil,codigo,nome FROM perfis WHERE codperfil='.(int)$main['codperfil'].' LIMIT 1'):null;
$proc=[];try{$rows=GreenPortalDatabase::call($tenant,'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)',['tabela'=>'usuarios','filtros'=>json_encode(['userid'=>'admin'])]);$proc=$rows[0]??[];}catch(Throwable $e){$proc=['erro'=>$e->getMessage()];}
$compare=null;if($legacy&&$main){$compare=(int)$main['senha_usa_hash']===1?password_verify((string)$legacy['SENHA'],(string)$main['senha']):hash_equals((string)$main['senha'],(string)$legacy['SENHA']);}
echo json_encode(['empresa'=>$company,'legado'=>['existe'=>(bool)$legacy,'senha_tamanho'=>isset($legacy['SENHA'])?strlen((string)$legacy['SENHA']):0],'tenant_principal'=>['existe'=>(bool)$main,'ativo'=>$main['ativo']??null,'senha_usa_hash'=>$main['senha_usa_hash']??null,'senha_tamanho'=>isset($main['senha'])?strlen((string)$main['senha']):0,'codperfil'=>$main['codperfil']??null],'seguranca_no_mesmo_banco'=>true,'perfil'=>$profile,'sp_crud_select'=>array_intersect_key($proc,array_flip(['codusuario','userid','ativo','senha_usa_hash','codperfil'])),'senha_compativel'=>$compare],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

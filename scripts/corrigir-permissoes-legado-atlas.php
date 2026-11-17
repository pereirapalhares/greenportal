<?php
declare(strict_types=1);

/**
 * Reflete gruposxmenus do legado Atlas nos perfis equivalentes do gatlas_dbp.
 * O legado não possui o catálogo de menus na mesma base: o vínculo usa
 * códigos antigos. O mapeamento é feito pelos caminhos descritivos da view
 * vgruposxmenus e pelo catálogo atual de menus.
 */
$env=[];
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line=trim($line); if ($line==='' || $line[0]==='#' || !str_contains($line,'=')) continue;
    [$key,$value]=explode('=',$line,2); $env[trim($key)]=trim(trim($value),"\"'");
}
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
function atlasDb(array $env,string $prefix,string $schema,array $options):PDO { return new PDO('mysql:host='.$env[$prefix.'_DB_HOST'].';port='.$env[$prefix.'_DB_PORT'].';dbname='.$schema.';charset=utf8mb4',$env[$prefix.'_DB_USERNAME'],$env[$prefix.'_DB_PASSWORD'],$options); }
function atlasNorm(string $value):string { $value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value) ?: $value; return preg_replace('/[^a-z0-9]+/i','',strtolower(trim($value))) ?: ''; }
function atlasParts(string $value):array { $value=preg_replace('/[\\>]+/','/',$value) ?: $value; return array_values(array_filter(array_map('trim',explode('/',$value)))); }
function atlasCodigoPerfilGrupo(string $nome,int $gid):string { $s=mb_strtoupper(trim($nome),'UTF-8'); if(class_exists('Normalizer')) $s=preg_replace('/\p{Mn}+/u','',Normalizer::normalize($s,Normalizer::FORM_D))??$s; $s=trim(preg_replace('/_+/','_',preg_replace('/[^A-Z0-9]+/','_',$s)??'')??'','_'); return $s!==''?$s:'GRUPO_'.$gid; }
function atlasLog(PDO $pdo,int $quantity,array $details,string $status):void { $status=$status==='PENDENTE'?'PENDENTE':'CONCLUIDA'; $sql="INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('ATLAS_GRUPOSXMENUS_REFLETIDOS','green_atlas_seguranca.grupos;gruposxmenus;vgruposxmenus','gatlas_dbp.perfis;perfil_menus;permissoes','DADOS','0.160.0',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?) ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)"; $stmt=$pdo->prepare($sql); $stmt->execute([$status,$quantity,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); }

$legacy=atlasDb($env,'LEGADO_SEGURANCA','green_atlas_seguranca',$options);
$target=atlasDb($env,'GPT','gatlas_dbp',$options);
$target->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');

$target->exec("CREATE TABLE IF NOT EXISTS atlas_perfis_legado (codgrupo BIGINT NOT NULL PRIMARY KEY,codperfil BIGINT NOT NULL,descricao VARCHAR(500) NOT NULL,origem VARCHAR(120) NOT NULL,UNIQUE KEY uk_atlas_perfil_legado(codperfil)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
$target->exec("CREATE TABLE IF NOT EXISTS atlas_permissoes_legado (codgrupo BIGINT NOT NULL,codmenu_legado BIGINT NOT NULL,codmenu BIGINT NULL,funcionalidade VARCHAR(100) NULL,status VARCHAR(30) NOT NULL,detalhes JSON NULL,PRIMARY KEY(codgrupo,codmenu_legado)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
$target->exec("CREATE TABLE IF NOT EXISTS atlas_usuarios_perfis_legado (codusuario BIGINT NOT NULL,codperfil BIGINT NOT NULL,codgrupo BIGINT NULL,efetivo TINYINT(1) NOT NULL DEFAULT 0,PRIMARY KEY(codusuario,codperfil),KEY ix_atlas_upl_perfil(codperfil),KEY ix_atlas_upl_grupo(codgrupo)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

$groups=$legacy->query('SELECT CODGRUPO,DSCGRUPO FROM grupos ORDER BY CODGRUPO')->fetchAll();
$groupProfiles=[]; $official=['ADMINISTRADOR'=>'ADMINISTRADOR','ADM DA EMPRESA'=>'ADM_DA_EMPRESA','FORNECEDOR'=>'FORNECEDOR','HOMOLOGACAO'=>'HOMOLOGACAO'];
$findProfile=$target->prepare('SELECT codperfil FROM perfis WHERE codigo=? LIMIT 1');
$insertProfile=$target->prepare("INSERT INTO perfis(codigo,nome,acesso_total,ativo) VALUES(?,?,0,1) ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1");
$mapProfile=$target->prepare('INSERT INTO atlas_perfis_legado(codgrupo,codperfil,descricao,origem) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE codperfil=VALUES(codperfil),descricao=VALUES(descricao)');
foreach ($groups as $group) {
    $gid=(int)$group['CODGRUPO']; $description=(string)$group['DSCGRUPO']; $code=$official[$description] ?? atlasCodigoPerfilGrupo($description,$gid); // migração 240: perfil com o nome do grupo, não mais ATLAS_GRUPO_<código>
    $findProfile->execute([$code]); $profile=(int)$findProfile->fetchColumn();
    if (!$profile) { $insertProfile->execute([$code,$description]); $findProfile->execute([$code]); $profile=(int)$findProfile->fetchColumn(); }
    if (!$profile) throw new RuntimeException('Não foi possível criar o perfil do grupo '.$description);
    $target->prepare('UPDATE perfis SET nome=?,ativo=1 WHERE codperfil=?')->execute([$description,$profile]);
    $technicalCode='ATLAS_GRUPO_'.$gid;
    if ($code!==$technicalCode) $target->prepare('UPDATE perfis SET ativo=0 WHERE codigo=? AND codperfil<>?')->execute([$technicalCode,$profile]);
    $mapProfile->execute([$gid,$profile,$description,'green_atlas_seguranca.grupos']);
    $groupProfiles[$gid]=$profile;
}

$targetMenus=$target->query('SELECT codmenu,codmenu_pai,codigo,label_pt,label_en,label_es,url FROM menus WHERE ativo=1 OR ativo IS NULL')->fetchAll();
$menuIndex=[]; $targetPathParts=[]; $targetById=[];
foreach ($targetMenus as $menu) $targetById[(int)$menu['codmenu']]=$menu;
foreach ($targetMenus as $menu) {
    $keys=[(string)$menu['codigo'],(string)($menu['label_pt']??''),(string)($menu['label_en']??''),(string)($menu['label_es']??'')];
    foreach ($keys as $key) { $norm=atlasNorm($key); if ($norm!=='') $menuIndex[$norm][]=$menu; }
    $parts=[(string)($menu['label_pt']??$menu['codigo'])]; $parent=(int)($menu['codmenu_pai']??0); $guard=0;
    while ($parent && isset($targetById[$parent]) && $guard++<10) { array_unshift($parts,(string)($targetById[$parent]['label_pt']??$targetById[$parent]['codigo'])); $parent=(int)($targetById[$parent]['codmenu_pai']??0); }
    $targetPathParts[(int)$menu['codmenu']]=array_values(array_filter(array_map('atlasNorm',$parts)));
}
$legacyDescriptions=[];
foreach ($legacy->query('SELECT codgrupo,codmenu,dscmenu FROM vgruposxmenus WHERE dscmenu IS NOT NULL AND TRIM(dscmenu)<>"" GROUP BY codgrupo,codmenu,dscmenu') as $row) $legacyDescriptions[(int)$row['codgrupo']][(int)$row['codmenu']][]=(string)$row['dscmenu'];

// Localiza menus pelo caminho, evitando que um nome genérico como Perigos ou
// Riscos seja associado simultaneamente a módulos diferentes.
$legacyToTarget=[]; $mappingEvidence=[];
foreach ($legacyDescriptions as $groupId=>$menusForGroup) foreach ($menusForGroup as $legacyId=>$paths) {
    foreach ($paths as $path) {
        $legacyParts=[]; foreach (atlasParts($path) as $part) { $norm=atlasNorm($part); if ($norm!=='' && ($legacyParts[count($legacyParts)-1]??null)!==$norm) $legacyParts[]=$norm; }
        $leaf=end($legacyParts); if (!$leaf) continue; $matches=[];
        foreach ($targetPathParts as $id=>$targetParts) {
            if (($targetParts[count($targetParts)-1]??null)!==$leaf) continue;
            $targetCount=count($targetParts); $legacyCount=count($legacyParts); $matched=0;
            for ($i=1;$i<=min($targetCount,$legacyCount);$i++) if ($targetParts[$targetCount-$i]=== $legacyParts[$legacyCount-$i]) $matched++; else break;
            if ($matched>=2 || ($matched===1 && count($menuIndex[$leaf]??[])===1)) $matches[$id]=$matched;
        }
        foreach ($matches as $id=>$score) { $legacyToTarget[$groupId][$legacyId][$id]=true; $mappingEvidence[$groupId][$legacyId][$id][]=$path; }
    }
}

$sourceRows=$legacy->query('SELECT CODGRUPO,CODMENU,funcionalidade FROM gruposxmenus ORDER BY CODGRUPO,CODMENU')->fetchAll();
$deleteProfileMenus=[]; $mapped=0; $unmapped=[]; $permissionRows=[];
foreach ($sourceRows as $row) {
    $gid=(int)$row['CODGRUPO']; $legacyId=(int)$row['CODMENU']; $profile=$groupProfiles[$gid]??0; $targets=array_keys($legacyToTarget[$gid][$legacyId]??[]); $descriptions=$legacyDescriptions[$gid][$legacyId]??[];
    if (!$targets || !$profile) {
        $unmapped[]=['codgrupo'=>$gid,'codmenu_legado'=>$legacyId,'descricao'=>$descriptions];
        $target->prepare('INSERT INTO atlas_permissoes_legado(codgrupo,codmenu_legado,codmenu,funcionalidade,status,detalhes) VALUES(?,?,NULL,?,"NAO_MAPEADA",?) ON DUPLICATE KEY UPDATE codmenu=NULL,funcionalidade=VALUES(funcionalidade),status="NAO_MAPEADA",detalhes=VALUES(detalhes)')->execute([$gid,$legacyId,$row['funcionalidade'],json_encode(['descricoes'=>$descriptions],JSON_UNESCAPED_UNICODE)]);
        continue;
    }
    foreach ($targets as $targetMenu) {
        $deleteProfileMenus[$profile][$targetMenu]=true;
        $permissionRows[]=['profile'=>$profile,'menu'=>(int)$targetMenu,'group'=>$gid,'legacy'=>$legacyId,'functionality'=>$row['funcionalidade'],'evidence'=>$mappingEvidence[$gid][$legacyId][$targetMenu]??[]];
        $target->prepare('INSERT INTO atlas_permissoes_legado(codgrupo,codmenu_legado,codmenu,funcionalidade,status,detalhes) VALUES(?,?,?,?,"MAPEADA",?) ON DUPLICATE KEY UPDATE codmenu=VALUES(codmenu),funcionalidade=VALUES(funcionalidade),status="MAPEADA",detalhes=VALUES(detalhes)')->execute([$gid,$legacyId,$targetMenu,$row['funcionalidade'],json_encode(['descricoes'=>$mappingEvidence[$gid][$legacyId][$targetMenu]??[]],JSON_UNESCAPED_UNICODE)]);
        $mapped++;
    }
}

// A reconstrução deve remover também permissões de execuções anteriores que
// deixaram de existir no legado; caso contrário o perfil acumula acessos.
foreach (array_values($groupProfiles) as $profile) {
    $target->prepare('DELETE FROM perfil_menus WHERE codperfil=?')->execute([(int)$profile]);
    $target->prepare('DELETE FROM permissoes WHERE codperfil=?')->execute([(int)$profile]);
}
$insertPM=$target->prepare('INSERT IGNORE INTO perfil_menus(codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf) VALUES(?,?,?,?,?,?,?,?)');
$insertP=$target->prepare('INSERT IGNORE INTO permissoes(codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf) VALUES(?,?,?,?,?,?,?,?)');
foreach ($permissionRows as $permission) {
    // gruposxmenus não possui flags CRUD: o vínculo legado representa acesso ao menu.
    $values=[$permission['profile'],$permission['menu'],0,1,0,0,0,0]; $insertPM->execute($values); $insertP->execute($values);
}

// Recalcula o perfil efetivo de cada usuário a partir dos grupos legados.
$userGroups=[];
foreach ($legacy->query('SELECT CODUSUARIO,CODGRUPO FROM usuariosxgrupos ORDER BY CODUSUARIO,CODGRUPO') as $row) $userGroups[(int)$row['CODUSUARIO']][]=(int)$row['CODGRUPO'];
$effectiveOverrides=[]; $protectedProfiles=[];
foreach ($target->query('SELECT codusuario,codperfil,codgrupo FROM atlas_usuarios_perfis_legado WHERE efetivo=1') as $row) {
    $effectiveOverrides[(int)$row['codusuario']][]=['codperfil'=>(int)$row['codperfil'],'codgrupo'=>$row['codgrupo']===null?null:(int)$row['codgrupo']];
    $protectedProfiles[(int)$row['codperfil']]=true;
}
$userUpdate=$target->prepare("UPDATE usuarios SET codperfil=? WHERE codusuario=? AND userid<>'admin'"); $assigned=0; $associated=0; $effectiveProfiles=0;
$target->exec('DELETE FROM atlas_usuarios_perfis_legado');
$protectedSql=$protectedProfiles ? ' AND codperfil NOT IN ('.implode(',',array_map('intval',array_keys($protectedProfiles))).')' : '';
$target->exec("UPDATE perfis SET ativo=0 WHERE codigo LIKE 'ATLAS_USUARIO_%'".$protectedSql);
$associationInsert=$target->prepare('INSERT IGNORE INTO atlas_usuarios_perfis_legado(codusuario,codperfil,codgrupo,efetivo) VALUES(?,?,?,?)');
$adminProfile=$groupProfiles[1]??0;
foreach ($userGroups as $user=>$groupsForUser) {
    $groupsForUser=array_values(array_unique($groupsForUser)); $profile=0;
    if ($adminProfile && in_array(1,$groupsForUser,true)) $profile=$adminProfile;
    elseif (!empty($groupsForUser)) $profile=$groupProfiles[$groupsForUser[0]]??0;
    foreach ($groupsForUser as $gid) if (!empty($groupProfiles[$gid])) { $associationInsert->execute([$user,$groupProfiles[$gid],$gid,0]); $associated++; }
    if (isset($effectiveOverrides[$user])) {
        foreach ($effectiveOverrides[$user] as $override) $associationInsert->execute([$user,$override['codperfil'],$override['codgrupo'],1]);
        $profile=(int)($effectiveOverrides[$user][0]['codperfil']??$profile);
    }
    if ($profile) { $userUpdate->execute([$profile,$user]); $assigned++; }
}

$detail=['grupos'=>count($groupProfiles),'vinculos_legados'=>count($sourceRows),'permissoes_refletidas'=>$mapped,'vinculos_nao_mapeados'=>count($unmapped),'usuarios_atribuidos'=>$assigned,'perfis_efetivos_multigrupo'=>$effectiveProfiles,'criterio'=>'descricoes da vgruposxmenus por segmento de caminho; sem correspondencia numérica de CODMENU','nao_mapeados'=>array_slice($unmapped,0,100)];
$detail['associacoes_usuario_grupo_perfil']=$associated;
$detail['sobrescritas_efetivas_preservadas']=count($effectiveOverrides);
atlasLog($target,$mapped,$detail,$unmapped?'PENDENTE':'CONCLUIDA');
echo json_encode($detail,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

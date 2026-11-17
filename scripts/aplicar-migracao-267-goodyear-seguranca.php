<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$target = GreenPortalDatabase::connect($env, 'ggdamericana_dbp', 'GPT');
$model = GreenPortalDatabase::connect($env, 'gmodelo_dbp', 'GPT');
$legacy = GreenPortalDatabase::connect($env, 'green_gd_americana_seguranca', 'LEGADO_SEGURANCA');
$target->beginTransaction();

try {
    foreach ([
        "INSERT IGNORE INTO menus (codmenu,codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo) SELECT codmenu,codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo FROM gmodelo_dbp.menus",
        "INSERT IGNORE INTO perfis (codperfil,codigo,nome,acesso_total,ativo) SELECT codperfil,codigo,nome,acesso_total,ativo FROM gmodelo_dbp.perfis",
        "INSERT IGNORE INTO perfil_menus (codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf) SELECT codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf FROM gmodelo_dbp.perfil_menus",
        "INSERT IGNORE INTO permissoes (codpermissao,codperfil,codmenu,pode_incluir,pode_visualizar,pode_visualizar_total,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf,pode_importar_arquivos,pode_visualizar_arquivos,pode_baixar_arquivos) SELECT codpermissao,codperfil,codmenu,pode_incluir,pode_visualizar,pode_visualizar_total,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf,pode_importar_arquivos,pode_visualizar_arquivos,pode_baixar_arquivos FROM gmodelo_dbp.permissoes",
    ] as $sql) $target->exec($sql);

    $groups = $legacy->query('SELECT CODGRUPO,DSCGRUPO FROM grupos ORDER BY CODGRUPO')->fetchAll(PDO::FETCH_ASSOC);
    $insertGroup = $target->prepare('INSERT INTO grupos (codgrupo,nome,ativo) VALUES (:id,:nome,1) ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1');
    foreach ($groups as $group) $insertGroup->execute(['id'=>(int)$group['CODGRUPO'],'nome'=>trim((string)$group['DSCGRUPO'])]);

    $groupNames = [];
    foreach ($groups as $group) $groupNames[(int)$group['CODGRUPO']] = strtoupper(trim((string)$group['DSCGRUPO']));
    $memberships = [];
    $membershipRows = $legacy->query('SELECT CODUSUARIO,CODGRUPO FROM usuariosxgrupos ORDER BY CODUSUARIO,CODGRUPO')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($membershipRows as $membership) $memberships[(int)$membership['CODUSUARIO']][] = (int)$membership['CODGRUPO'];
    $profileIds = [];
    foreach ($target->query('SELECT codperfil,codigo FROM perfis') as $profile) $profileIds[strtoupper((string)$profile['codigo'])] = (int)$profile['codperfil'];
    $users = $legacy->query('SELECT * FROM usuarios ORDER BY CODUSUARIO')->fetchAll(PDO::FETCH_ASSOC);
    $seenUserids = [];
    $useridConflicts = [];
    $insertUser = $target->prepare('INSERT INTO usuarios (codusuario,userid,nome,email,senha,senha_usa_hash,codperfil,codgrupo,codusuario_superior,ativo,trocar_senha,criado_em,ultimo_acesso,dtinclusao,emails,ativado,logado,ultimoacesso,ultimologin,codfornecedor,usuario_licensa,nrcelular,codunidade,codusuario_criador,token,token_data_criacao,token_validade_minutos,userid_ad,tipo_auth,nome_ad,email_ad,usuario_superior) VALUES (:id,:userid,:nome,:email,:senha,0,:perfil,:grupo,:superior,:ativo,0,:criado,:ultimo,:dtinclusao,:emails,:ativado,:logado,:ultimoacesso,:ultimologin,:fornecedor,:licensa,:celular,:unidade,:criador,:token,:token_data,:token_validade,:userid_ad,:tipo_auth,:nome_ad,:email_ad,:usuario_superior) ON DUPLICATE KEY UPDATE nome=VALUES(nome),email=VALUES(email),senha=VALUES(senha),senha_usa_hash=0,codperfil=VALUES(codperfil),codgrupo=VALUES(codgrupo),codusuario_superior=VALUES(codusuario_superior),ativo=VALUES(ativo),dtinclusao=VALUES(dtinclusao),emails=VALUES(emails),ativado=VALUES(ativado),logado=VALUES(logado),ultimoacesso=VALUES(ultimoacesso),ultimologin=VALUES(ultimologin),codfornecedor=VALUES(codfornecedor),usuario_licensa=VALUES(usuario_licensa),nrcelular=VALUES(nrcelular),codunidade=VALUES(codunidade),codusuario_criador=VALUES(codusuario_criador),token=VALUES(token),token_data_criacao=VALUES(token_data_criacao),token_validade_minutos=VALUES(token_validade_minutos),userid_ad=VALUES(userid_ad),tipo_auth=VALUES(tipo_auth),nome_ad=VALUES(nome_ad),email_ad=VALUES(email_ad),usuario_superior=VALUES(usuario_superior)');
    $profileCounts = [];
    foreach ($users as $user) {
        $id = (int)$user['CODUSUARIO'];
        $userid = trim((string)$user['USERID']);
        if (isset($seenUserids[$userid])) { $userid .= '_' . $id; $useridConflicts[] = ['codigo_legado'=>$id,'userid_legado'=>(string)$user['USERID'],'userid_novo'=>$userid]; }
        $seenUserids[(string)$user['USERID']] = true;
        $userGroups = $memberships[$id] ?? [];
        $profileCode = 'EMITENTE';
        foreach ($userGroups as $groupId) {
            $name = $groupNames[$groupId] ?? '';
            if ($name === 'ADMINISTRADOR') { $profileCode = 'ADMINISTRADOR'; break; }
            if ($name === 'ADM DA EMPRESA') $profileCode = 'ADM_DA_EMPRESA';
            elseif ($name === 'GESTORES' && $profileCode === 'EMITENTE') $profileCode = 'GESTOR';
            elseif ($name === 'VISITANTE' && $profileCode === 'EMITENTE') $profileCode = 'VISITANTE_DOCUMENTOS';
        }
        $groupId = $userGroups[0] ?? null;
        foreach ([1,16,15,17,12,40] as $preferred) if (in_array($preferred,$userGroups,true)) { $groupId=$preferred; break; }
        $created = trim((string)($user['DTINCLUSAO'] ?? ''));
        $created = $created !== '' ? $created . ' 00:00:00' : null;
        $email = trim((string)($user['EMAILS'] ?? ''));
        $last = $user['ultimologin'] ?? $user['ultimoacesso'] ?? null;
        $insertUser->execute(['id'=>$id,'userid'=>$userid,'nome'=>(string)$user['NOME'],'email'=>$email !== '' ? $email : null,'senha'=>(string)$user['SENHA'],'perfil'=>$profileIds[$profileCode] ?? $profileIds['EMITENTE'],'grupo'=>$groupId,'superior'=>null,'ativo'=>strtoupper((string)($user['ativado'] ?? 'N'))==='S'?1:0,'criado'=>$created,'ultimo'=>$last,'dtinclusao'=>$user['DTINCLUSAO'] ?: null,'emails'=>$user['EMAILS'] ?: null,'ativado'=>$user['ativado'] ?: null,'logado'=>$user['logado'] ?: null,'ultimoacesso'=>$user['ultimoacesso'] ?: null,'ultimologin'=>$user['ultimologin'] ?: null,'fornecedor'=>$user['codfornecedor'] ?: null,'licensa'=>$user['usuario_licensa'] ?: null,'celular'=>$user['nrcelular'] ?: null,'unidade'=>$user['codunidade'] ?: null,'criador'=>$user['codusuario_criador'] ?: null,'token'=>$user['token'] ?: null,'token_data'=>$user['token_data_criacao'] ?: null,'token_validade'=>$user['token_validade_minutos'] ?: null,'userid_ad'=>$user['USERID_AD'] ?: null,'tipo_auth'=>$user['TIPO_AUTH'] ?: 'LOCAL','nome_ad'=>$user['NOME_AD'] ?: null,'email_ad'=>$user['EMAIL_AD'] ?: null,'usuario_superior'=>$user['USUARIO_SUPERIOR'] ?: null]);
        $profileCounts[$profileCode] = ($profileCounts[$profileCode] ?? 0) + 1;
    }
    $target->exec('UPDATE usuarios u LEFT JOIN usuarios s ON s.codusuario=u.usuario_superior SET u.codusuario_superior=CASE WHEN s.codusuario IS NULL THEN NULL ELSE s.codusuario END');
    $quantity = count($users);
    $details = json_encode(['empresa'=>'Goodyear Americana','origem_seguranca'=>'green_gd_americana_seguranca','grupos'=>$groups ? count($groups) : 0,'usuarios'=>$quantity,'perfis'=>$profileCounts,'conflitos_userid'=>$useridConflicts,'regra_perfil'=>'ADMINISTRADOR, ADM DA EMPRESA, GESTORES, VISITANTE; demais EMITENTE','senha'=>'preservada_no_formato_legado_com_senha_usa_hash_0'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $audit = $target->prepare("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('GREEN_GOODYEAR_SEGURANCA_267','green_gd_americana_seguranca.usuarios;grupos;usuariosxgrupos','ggdamericana_dbp.usuarios;grupos;perfis;permissoes','DADOS','1.0.0','CONCLUIDA',:q,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:detalhes) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
    $audit->execute(['q'=>$quantity,'detalhes'=>$details]);
    $target->commit();
    echo json_encode(['ok'=>true,'migracao'=>267,'empresa'=>'Goodyear Americana','grupos'=>count($groups),'usuarios'=>$quantity,'perfis'=>$profileCounts,'status'=>'CONCLUIDA'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    if ($target->inTransaction()) $target->rollBack();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

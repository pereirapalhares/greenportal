<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = [
    ['target' => 'gatlas_dbp', 'legacy' => 'green_atlas_seguranca'],
    ['target' => 'gmodelo_dbp', 'legacy' => 'green_modelo_seguranca'],
];
$columns = [
    'dtinclusao' => 'DATE NULL',
    'emails' => 'VARCHAR(1000) NULL',
    'usuario_superior' => 'INT NULL',
    'ativado' => 'VARCHAR(1) NULL',
    'logado' => 'CHAR(1) NULL',
    'ultimoacesso' => 'DATETIME NULL',
    'ultimologin' => 'DATETIME NULL',
    'codfornecedor' => 'INT NULL DEFAULT 0',
    'usuario_licensa' => 'CHAR(1) NULL',
    'nrcelular' => 'VARCHAR(100) NULL',
    'codunidade' => 'INT NULL',
    'dataultimatrocasenha' => 'DATETIME NULL',
    'codusuario_criador' => 'INT NULL',
    'token' => 'VARCHAR(500) NULL',
    'token_data_criacao' => 'DATETIME NULL',
    'token_validade_minutos' => 'INT NULL',
    'userid_ad' => 'VARCHAR(200) NULL',
    'tipo_auth' => "ENUM('LOCAL','AZURE_AD') NULL DEFAULT 'LOCAL'",
    'nome_ad' => 'VARCHAR(200) NULL',
    'email_ad' => 'VARCHAR(500) NULL',
];
$result = [];

foreach ($targets as $pair) {
    $pdo = GreenPortalDatabase::connect($env, $pair['target'], 'GPT');
    $legacy = str_replace('`', '', $pair['legacy']);
    $target = str_replace('`', '', $pair['target']);
    $legacyUsers = "SELECT l.* FROM `$legacy`.usuarios l JOIN (SELECT USERID,MAX(CODUSUARIO) AS CODUSUARIO FROM `$legacy`.usuarios GROUP BY USERID) pick ON pick.USERID=l.USERID AND pick.CODUSUARIO=l.CODUSUARIO";
    $existing = $pdo->prepare("SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='usuarios'");
    $existing->execute([$target]);
    $existingColumns = array_fill_keys($existing->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ($columns as $name => $definition) {
        if (!isset($existingColumns[$name])) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN `$name` $definition");
        }
    }

    $pdo->exec("UPDATE usuarios SET dtinclusao=COALESCE(dtinclusao,DATE(criado_em)), dataultimatrocasenha=COALESCE(dataultimatrocasenha,criado_em)");
    $pdo->exec("UPDATE usuarios u JOIN ($legacyUsers) l ON l.USERID=u.userid
        SET u.dtinclusao=COALESCE(l.DTINCLUSAO,u.dtinclusao),
            u.emails=COALESCE(l.EMAILS,u.emails),
            u.usuario_superior=COALESCE(l.USUARIO_SUPERIOR,u.usuario_superior),
            u.ativado=COALESCE(l.ativado,u.ativado),
            u.logado=COALESCE(l.logado,u.logado),
            u.ultimoacesso=COALESCE(l.ultimoacesso,u.ultimoacesso),
            u.ultimologin=COALESCE(l.ultimologin,u.ultimologin),
            u.codfornecedor=COALESCE(l.codfornecedor,u.codfornecedor),
            u.usuario_licensa=COALESCE(l.usuario_licensa,u.usuario_licensa),
            u.nrcelular=COALESCE(l.nrcelular,u.nrcelular),
            u.codunidade=COALESCE(l.codunidade,u.codunidade),
            u.codusuario_criador=COALESCE(l.codusuario_criador,u.codusuario_criador),
            u.token=COALESCE(l.token,u.token),
            u.token_data_criacao=COALESCE(l.token_data_criacao,u.token_data_criacao),
            u.token_validade_minutos=COALESCE(l.token_validade_minutos,u.token_validade_minutos),
            u.userid_ad=COALESCE(l.USERID_AD,u.userid_ad),
            u.tipo_auth=COALESCE(l.TIPO_AUTH,u.tipo_auth),
            u.nome_ad=COALESCE(l.NOME_AD,u.nome_ad),
            u.email_ad=COALESCE(l.EMAIL_AD,u.email_ad),
            u.email=COALESCE(NULLIF(l.EMAILS,''),u.email),
            u.ativo=CASE WHEN l.ativado IN ('0','N','n') THEN 0 WHEN l.ativado IS NULL THEN u.ativo ELSE 1 END,
            u.ultimo_acesso=COALESCE(u.ultimo_acesso,l.ultimologin,l.ultimoacesso,u.ultimo_acesso)");
    $pdo->exec("UPDATE usuarios u JOIN ($legacyUsers) l ON l.USERID=u.userid
        JOIN ($legacyUsers) ls ON ls.CODUSUARIO=l.USUARIO_SUPERIOR
        JOIN usuarios us ON us.userid=ls.USERID
        SET u.codusuario_superior=us.codusuario
        WHERE l.USUARIO_SUPERIOR IS NOT NULL AND l.USUARIO_SUPERIOR<>0");

    $pdo->exec("CREATE OR REPLACE VIEW vw_usuarios_grid AS
        SELECT u.codusuario, u.userid, u.nome, COALESCE(NULLIF(u.emails,''),u.email) AS email,
               u.codperfil, u.codgrupo, u.codusuario_superior, u.usuario_superior, u.ativo,
               p.codigo AS perfil_codigo, p.nome AS perfil_nome,
               g.nome AS grupo_nome, us.nome AS usuario_superior_nome,
               u.criado_em, COALESCE(u.ultimo_acesso,u.ultimologin,u.ultimoacesso) AS ultimo_acesso,
               u.emails, u.ativado, u.logado, u.ultimoacesso, u.ultimologin,
               u.dtinclusao, u.codfornecedor, u.usuario_licensa, u.nrcelular,
               u.codunidade, u.dataultimatrocasenha, u.codusuario_criador,
               u.token, u.token_data_criacao, u.token_validade_minutos,
               u.userid_ad, u.tipo_auth, u.nome_ad, u.email_ad
          FROM usuarios u
          LEFT JOIN perfis p ON p.codperfil=u.codperfil
          LEFT JOIN grupos g ON g.codgrupo=u.codgrupo
          LEFT JOIN usuarios us ON us.codusuario=u.codusuario_superior");

    $count = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $matched = (int)$pdo->query("SELECT COUNT(DISTINCT u.codusuario) FROM usuarios u JOIN ($legacyUsers) l ON l.USERID=u.userid")->fetchColumn();
    $migration = $pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
        VALUES('SEGURANCA_USUARIOS_LEGADO_233',?, 'usuarios;vw_usuarios_grid','DADOS_ESTRUTURA','1.0.0','CONCLUIDA',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?)
        ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
    $migration->execute([$legacy . '.usuarios', $count, json_encode(['legado'=>$legacy,'correspondencias'=>$matched,'campos_adicionados'=>array_keys($columns),'criterio'=>'USERID'], JSON_UNESCAPED_UNICODE)]);
    $result[$target] = ['usuarios'=>$count,'correspondencias_legado'=>$matched,'view_atualizada'=>true];
}

echo json_encode(['ok'=>true,'migration'=>233,'targets'=>$result], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;

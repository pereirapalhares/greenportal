<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(__DIR__ . '/../database/215_menus_portaria_emails_alexsandro.sql');
if ($sql === false) {
    throw new RuntimeException('SQL da migração 215 não encontrado.');
}

$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$tenant = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$universal->exec($sql);
$tenant->exec($sql);

$tenant->exec("CREATE TABLE IF NOT EXISTS atlas_usuarios_perfis_legado (
    codusuario BIGINT NOT NULL,
    codperfil BIGINT NOT NULL,
    codgrupo BIGINT NULL,
    efetivo TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (codusuario, codperfil),
    KEY ix_atlas_upl_perfil (codperfil),
    KEY ix_atlas_upl_grupo (codgrupo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

$user = $tenant->query("SELECT codusuario FROM usuarios WHERE LOWER(userid)=LOWER('alexsandro.lima') LIMIT 1")->fetchColumn();
if (!$user) {
    throw new RuntimeException('Usuário alexsandro.lima não encontrado em gatlas_dbp.');
}
$userId = (int)$user;
$profileCode = 'ATLAS_USUARIO_' . $userId;
$profileName = 'Acesso personalizado: alexsandro.lima';

$tenant->prepare("INSERT INTO perfis (codigo,nome,acesso_total,ativo) VALUES (?,?,0,1)
    ON DUPLICATE KEY UPDATE nome=VALUES(nome),acesso_total=0,ativo=1")->execute([$profileCode, $profileName]);
$profileStatement = $tenant->prepare('SELECT codperfil FROM perfis WHERE codigo=? LIMIT 1');
$profileStatement->execute([$profileCode]);
$profileId = (int)$profileStatement->fetchColumn();
if (!$profileId) {
    throw new RuntimeException('Perfil personalizado não foi criado.');
}

$menus = [72, 73, 115, 120, 121, 122];
$tenant->prepare('DELETE FROM perfil_menus WHERE codperfil=?')->execute([$profileId]);
$tenant->prepare('DELETE FROM permissoes WHERE codperfil=?')->execute([$profileId]);
$insertProfileMenu = $tenant->prepare('INSERT INTO perfil_menus (codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf) VALUES (?,?,?,?,?,?,?,?)');
$insertPermission = $tenant->prepare('INSERT INTO permissoes (codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf) VALUES (?,?,?,?,?,?,?,?)');
foreach ($menus as $menuId) {
    $values = [$profileId, $menuId, 0, 1, 0, 0, 0, 0];
    $insertProfileMenu->execute($values);
    $insertPermission->execute($values);
}

// Mantém os grupos legados para rastreabilidade, mas marca o perfil personalizado
// como efetivo para que a sessão não herde os menus de EMAIL/FORNECEDOR/SPONSOR.
$tenant->prepare('DELETE FROM atlas_usuarios_perfis_legado WHERE codusuario=? AND efetivo=1')->execute([$userId]);
$tenant->prepare('INSERT INTO atlas_usuarios_perfis_legado (codusuario,codperfil,codgrupo,efetivo) VALUES (?,?,NULL,1)')->execute([$userId, $profileId]);
$tenant->prepare('UPDATE usuarios SET codperfil=? WHERE codusuario=?')->execute([$profileId, $userId]);

$details = [
    'usuario' => 'alexsandro.lima',
    'codusuario' => $userId,
    'codperfil' => $profileId,
    'perfil' => $profileCode,
    'menus' => $menus,
    'permissao' => 'somente_visualizacao',
    'grupos_legados_preservados' => true,
    'override_efetivo' => true,
];
$log = $tenant->prepare("INSERT INTO registros_migracao
    (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
    VALUES ('ATLAS_MENU_PORTARIA_ALEXSANDRO','gatlas_dbp.grupos;usuariosxgrupos;perfis','guniversal_dbp.menus;gatlas_dbp.perfis;gatlas_dbp.permissoes','DADOS','4.0.1','CONCLUIDA',?,?,?,?)
    ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
$log->execute([count($menus), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

echo json_encode(['ok' => true, 'migration' => '215', 'details' => $details], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

<?php
declare(strict_types=1);

/*
 * Migração 199 — Guia do usuário editável.
 * 1. Cria tabelas, views e procedures no guniversal_dbp e cadastra o menu
 *    Administração › Guia do usuário.
 * 2. Faz a carga inicial do conteúdo a partir de principal/guia_usuario_conteudo.php
 *    (somente se ainda não houver versão publicada).
 * 3. Concede a permissão ao perfil ADMINISTRADOR em cada tenant.
 * Pode ser executada mais de uma vez sem duplicar dados.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-199-guia-usuario.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
require_once __DIR__ . '/../classes/GuiaUsuarioService.php';

$env = GreenPortalDatabase::env();
$universalDb = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universalSql = file_get_contents(__DIR__ . '/../database/199_guia_usuario_universal.sql');
$tenantSql = file_get_contents(__DIR__ . '/../database/199_guia_usuario_tenant.sql');
if ($universalSql === false || $tenantSql === false) throw new RuntimeException('Scripts SQL da migração 199 não encontrados.');

$universal = GreenPortalDatabase::connect($env, $universalDb, 'GUNIVERSAL');
$universal->exec($universalSql);

if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);
$padrao = require __DIR__ . '/../principal/guia_usuario_conteudo.php';
$service = new GuiaUsuarioService($universal);
$carregado = $service->cargaInicial(['perfis' => $padrao['perfis'], 'modulos' => $padrao['modulos']], ['nome' => 'Migração 199', 'codusuario' => 0, 'banco' => $universalDb]);

$tenants = [];
try {
    foreach ($universal->query('SELECT DISTINCT banco_dados FROM empresa WHERE banco_dados IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $db) {
        if (preg_match('/^g[a-z0-9_]+_dbp$/', (string)$db)) $tenants[] = (string)$db;
    }
} catch (Throwable) {
    // Tabela de empresas indisponível: usa a lista homologada.
}
if (!$tenants) $tenants = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];

$resultado = ['ok' => true, 'migracao' => '199', 'carga_inicial' => $carregado ? 'REALIZADA' : 'JA_EXISTIA', 'tenants' => []];
foreach ($tenants as $database) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
        $pdo->exec($tenantSql);
        $resultado['tenants'][$database] = [
            'status' => 'CONCLUIDA',
            'permissoes' => (int)$pdo->query("SELECT COUNT(*) FROM permissoes p JOIN `" . $universalDb . "`.menus m ON m.codmenu = p.codmenu WHERE m.codigo = 'ADMINISTRACAO_GUIA_USUARIO'")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $resultado['ok'] = false;
        $resultado['tenants'][$database] = ['status' => 'ERRO', 'mensagem' => $e->getMessage()];
    }
}

$resultado['universal'] = [
    'menu' => (int)$universal->query("SELECT COUNT(*) FROM menus WHERE codigo = 'ADMINISTRACAO_GUIA_USUARIO' AND codmenu_pai IS NOT NULL")->fetchColumn(),
    'versoes' => (int)$universal->query('SELECT COUNT(*) FROM guia_usuario_versoes')->fetchColumn(),
    'rascunho' => (int)$universal->query('SELECT COUNT(*) FROM guia_usuario_rascunho')->fetchColumn(),
    'procedures' => (int)$universal->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_name LIKE 'sp\\_guia\\_usuario\\_%'")->fetchColumn(),
    'views' => (int)$universal->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE() AND table_name LIKE 'vw\\_guia\\_usuario\\_%'")->fetchColumn(),
];
if ($resultado['universal']['procedures'] < 4 || $resultado['universal']['views'] < 3 || $resultado['universal']['menu'] < 1) $resultado['ok'] = false;
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);

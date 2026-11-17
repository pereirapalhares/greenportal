<?php
declare(strict_types=1);

/*
 * Migração 211 — Textos e idiomas da interface (piloto: Módulo Legal › Controle).
 * 1. Cria tabelas, views e procedures no guniversal_dbp e o menu Administração › Textos e idiomas.
 * 2. Carga inicial: grupos COMUM e MODULOLEGAL_CONTROLE com os textos que estavam no código
 *    (database/211_idiomas_textos.php). Não sobrescreve textos já editados.
 * 3. Ajuda detalhada da tela (ajuda_transacoes), sem sobrescrever ajustes já feitos.
 * 4. Concede a permissão ao perfil ADMINISTRADOR em cada tenant.
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-211-idiomas.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$universalDb = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universalSql = file_get_contents(__DIR__ . '/../database/211_idiomas_universal.sql');
$ajudaSql = file_get_contents(__DIR__ . '/../database/211_ajuda_administracao_idiomas.sql');
$tenantSql = file_get_contents(__DIR__ . '/../database/211_idiomas_tenant.sql');
if ($universalSql === false || $ajudaSql === false || $tenantSql === false) throw new RuntimeException('Scripts SQL da migração 211 não encontrados.');
$carga = require __DIR__ . '/../database/211_idiomas_textos.php';

$universal = GreenPortalDatabase::connect($env, $universalDb, 'GUNIVERSAL');
$universal->exec($universalSql);

$usuario = 'Migração 211';
$codpublicacao = (int)(GreenPortalDatabase::call($universal, 'CALL sp_idioma_carga_inicial(?, ?)', [$usuario, $universalDb])[0]['codpublicacao'] ?? 0);
if ($codpublicacao <= 0) throw new RuntimeException('Publicação da carga inicial não criada.');

foreach ($carga['grupos'] as [$codigo, $pt, $en, $es, $transacao, $ordem]) {
    GreenPortalDatabase::call($universal, 'CALL sp_idioma_registrar_grupo(?, ?, ?, ?, ?, ?)', [$codigo, $pt, $en, $es, $transacao, $ordem]);
}
$registrados = 0;
foreach ($carga['textos'] as $grupo => $textos) {
    foreach ($textos as $ordem => [$chave, $tipo, $descricao, $pt, $en, $es]) {
        GreenPortalDatabase::call($universal, 'CALL sp_idioma_registrar_texto(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$chave, $grupo, $tipo, $descricao, ($ordem + 1) * 10, $pt, $en, $es, $codpublicacao, $usuario]);
        $registrados++;
    }
}
$universal->exec($ajudaSql);

$tenants = [];
try {
    foreach ($universal->query('SELECT DISTINCT banco_dados FROM empresa WHERE banco_dados IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $db) {
        if (preg_match('/^g[a-z0-9_]+_dbp$/', (string)$db)) $tenants[] = (string)$db;
    }
} catch (Throwable) {
    // Tabela de empresas indisponível: usa a lista homologada.
}
if (!$tenants) $tenants = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];

$resultado = ['ok' => true, 'migracao' => '211', 'textos_registrados' => $registrados, 'tenants' => []];
foreach ($tenants as $database) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
        $pdo->exec($tenantSql);
        $resultado['tenants'][$database] = [
            'status' => 'CONCLUIDA',
            'permissoes' => (int)$pdo->query("SELECT COUNT(*) FROM permissoes p JOIN `" . $universalDb . "`.menus m ON m.codmenu = p.codmenu WHERE m.codigo = 'ADMINISTRACAO_IDIOMAS'")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $resultado['ok'] = false;
        $resultado['tenants'][$database] = ['status' => 'ERRO', 'mensagem' => $e->getMessage()];
    }
}

$resultado['universal'] = [
    'menu' => (int)$universal->query("SELECT COUNT(*) FROM menus WHERE codigo = 'ADMINISTRACAO_IDIOMAS' AND codmenu_pai IS NOT NULL")->fetchColumn(),
    'grupos' => (int)$universal->query('SELECT COUNT(*) FROM idioma_grupos')->fetchColumn(),
    'textos' => (int)$universal->query('SELECT COUNT(*) FROM idioma_textos')->fetchColumn(),
    'traducoes_publicadas' => (int)$universal->query('SELECT COUNT(*) FROM idioma_traducoes')->fetchColumn(),
    'traducoes_rascunho' => (int)$universal->query('SELECT COUNT(*) FROM idioma_traducoes_rascunho')->fetchColumn(),
    'procedures' => (int)$universal->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_name LIKE 'sp\\_idioma\\_%'")->fetchColumn(),
    'views' => (int)$universal->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE() AND table_name LIKE 'vw\\_idioma\\_%'")->fetchColumn(),
    'ajuda' => (int)$universal->query("SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = 'administracao_idiomas' AND ativo = 1")->fetchColumn(),
];
if ($resultado['universal']['procedures'] < 6 || $resultado['universal']['views'] < 7 || $resultado['universal']['menu'] < 1
    || $resultado['universal']['textos'] < $registrados || $resultado['universal']['traducoes_publicadas'] < $registrados * 3) $resultado['ok'] = false;
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);

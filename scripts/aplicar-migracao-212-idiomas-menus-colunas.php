<?php
declare(strict_types=1);

/*
 * Migração 212 — Textos e idiomas: menus do portal (grupo MENUS) e cadastro automático das colunas dos grids.
 * Requer a migração 211. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-212-idiomas-menus-colunas.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$universalDb = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$sql = file_get_contents(__DIR__ . '/../database/212_idiomas_menus_colunas.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 212 não encontrado.');

$universal = GreenPortalDatabase::connect($env, $universalDb, 'GUNIVERSAL');
if ((int)$universal->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'idioma_textos'")->fetchColumn() === 0) {
    fwrite(STDERR, "Aplique antes a migração 211 (scripts/aplicar-migracao-211-idiomas.php).\n");
    exit(1);
}
$universal->exec($sql);
$sync = GreenPortalDatabase::call($universal, 'CALL sp_idioma_sincronizar_menus(?)', ['Migração 212'])[0] ?? [];

$resultado = [
    'ok' => true,
    'migracao' => '212',
    'menus_sincronizados' => ['novos' => (int)($sync['novos'] ?? 0), 'atualizados' => (int)($sync['atualizados'] ?? 0)],
    'universal' => [
        'menus' => (int)$universal->query("SELECT COUNT(*) FROM menus WHERE codigo REGEXP '^[A-Za-z0-9_]+$'")->fetchColumn(),
        'textos_menus' => (int)$universal->query("SELECT COUNT(*) FROM idioma_textos WHERE codgrupo = 'MENUS'")->fetchColumn(),
        'procedures' => (int)$universal->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_name IN ('sp_idioma_sincronizar_menus','sp_idioma_registrar_lote','sp_idioma_salvar_rascunho','sp_idioma_publicar')")->fetchColumn(),
    ],
];
if ($resultado['universal']['procedures'] < 4 || $resultado['universal']['textos_menus'] < $resultado['universal']['menus']) $resultado['ok'] = false;
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);

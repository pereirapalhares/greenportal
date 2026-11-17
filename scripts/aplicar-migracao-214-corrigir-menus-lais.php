<?php
declare(strict_types=1);

/*
 * Migração 214 — Corrige os menus do LAIS (Requisitos legais, Controles e documentos, Objetivos e metas)
 * que apontavam para arquivos inexistentes. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-214-corrigir-menus-lais.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$universalDb = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$sql = file_get_contents(__DIR__ . '/../database/214_corrigir_menus_lais.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 214 não encontrado.');

$universal = GreenPortalDatabase::connect($env, $universalDb, 'GUNIVERSAL');
$universal->exec($sql);

$arquivos = ['modulolegallais_requisitoslegais.php', 'modulolegallais_controlesdocumentos.php', 'modulolegallais_objetivosmetas.php'];
$menus = $universal->query("SELECT codigo, url FROM menus WHERE url LIKE '%modulolegallais\\_%' OR url LIKE '%moduloleglais\\_requisitoslegais%' OR url LIKE '%moduloleglais\\_controlesdocumentos%' OR url LIKE '%moduloleglais\\_objetivosmetas%' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
$erros = [];
foreach ($menus as $menu) {
    $arquivo = basename((string)parse_url((string)$menu['url'], PHP_URL_PATH));
    if (!is_file(__DIR__ . '/../principal/' . $arquivo)) $erros[] = $menu['codigo'] . ' -> ' . $menu['url'];
}
$resultado = ['ok' => !$erros && count($menus) >= 3, 'migracao' => '214', 'menus' => $menus, 'arquivos_inexistentes' => $erros];
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);

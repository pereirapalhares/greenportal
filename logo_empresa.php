<?php
declare(strict_types=1);

/*
 * Logo da empresa na tela de login (GER-1, 12/11/2026): ?e=<código da empresa>. Público, como a própria lista de empresas
 * do login: só empresas de vw_empresas_login_migradas; o banco vem da view, nunca da URL. Sem logo: 404.
 */

require_once __DIR__ . '/classes/GreenPortalDatabase.php';
require_once __DIR__ . '/classes/UsuarioImagem.php';
$codigo = trim((string)($_GET['e'] ?? ''));
if ($codigo === '' || !preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $codigo)) { http_response_code(404); exit; }
try {
    $env = GreenPortalDatabase::env();
    $pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $banco = '';
    foreach (GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_empresas_login_migradas', 'filtros' => '{}']) as $emp)
        if (strcasecmp((string)($emp['codigo'] ?? ''), $codigo) === 0) { $banco = (string)($emp['banco_dados'] ?? ''); break; }
    if ($banco === '' || !preg_match('/^[A-Za-z0-9_]+$/', $banco)) { http_response_code(404); exit; }
    UsuarioImagem::enviar(UsuarioImagem::logoArquivo($banco), 'image/png');
} catch (Throwable $e) {
    error_log('logo_empresa: ' . $e->getMessage());
    http_response_code(404);
}

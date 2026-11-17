<?php
declare(strict_types=1);

/* Logo da empresa logada (cabeçalho do portal; GER-1, 12/11/2026). Sem logo: 404 — o cabeçalho mostra a inicial. */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit; }
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';
$banco = TenantContext::database($_SESSION);
session_write_close();
UsuarioImagem::enviar(UsuarioImagem::logoArquivo($banco), 'image/png');

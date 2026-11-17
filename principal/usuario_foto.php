<?php
declare(strict_types=1);

/*
 * Foto do usuário (GER-1, 12/11/2026): ?u=<codusuario>&v=<versão>. Só para quem está logado e só da própria empresa
 * (o banco vem da sessão). Sem foto: 404 — a tela mostra a inicial do nome.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit; }
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';
$banco = TenantContext::database($_SESSION);
$cod = max(0, (int)($_GET['u'] ?? 0));
session_write_close();
if ($cod <= 0) { http_response_code(404); exit; }
UsuarioImagem::enviar(UsuarioImagem::fotoArquivo($banco, $cod), 'image/jpeg');

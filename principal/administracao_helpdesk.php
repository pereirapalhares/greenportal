<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessão expirada.'); }
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Help Desk</title><link rel="stylesheet" href="../assets/css/design-system.css?v=1"></head><body><main class="container-fluid py-3"><section class="card shadow-sm"><div class="card-body text-center py-5"><i class="bi bi-headset fs-1 text-success"></i><h1 class="h4 mt-3">Help Desk</h1><p class="text-muted mb-0">Em construção</p><small class="text-muted">Esta transação será disponibilizada em uma próxima etapa.</small></div></section></main></body></html>

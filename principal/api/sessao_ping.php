<?php
declare(strict_types=1);
/*
 * Mantém a sessão do servidor viva enquanto o usuário usa o portal (26/11/2026; chamado pelo main.js a cada 4 min,
 * só quando houve uso da tela). Não lê nem grava dados: só abre a sessão, o que renova o prazo dela no servidor.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => !empty($_SESSION['autenticado']), 'duracao_segundos' => (int)ini_get('session.gc_maxlifetime')]);

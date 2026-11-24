<?php
declare(strict_types=1);

/*
 * Situação legal de cada item de Controles, Treinamentos e Infraestrutura para os ícones de status dos grids
 * (assets/js/status-icones.js, onda 1 da Revisão G — 24/11/2026). Mesma fonte da Home do módulo (classes/HomePilar.php):
 * vw_sl_ev_<pilar>, uma linha por item × requisito legal × unidade. Devolve, por item, a pior situação e o motivo dela.
 * Só leitura de view. Resposta: {"ok":true,"itens":{"<ITEM_ID>":["CRITICO|FALHA|ATENCAO|OK","motivo"]}}.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$VIEWS = ['controles' => 'vw_sl_ev_controles', 'treinamentos' => 'vw_sl_ev_treinamentos', 'infraestrutura' => 'vw_sl_ev_infraestrutura'];
$pilar = strtolower(trim((string)($_GET['pilar'] ?? '')));
if (!isset($VIEWS[$pilar])) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/HomeModulo.php';

try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
    session_write_close();
    $ordem = ['CRITICO' => 4, 'FALHA' => 3, 'ATENCAO' => 2, 'OK' => 1];
    $itens = [];
    foreach (HomeModulo::consultar($pdo, $VIEWS[$pilar], ['ITEM_ID', 'STATUS', 'MOTIVO']) ?? [] as $l) {
        $id = (int)($l['ITEM_ID'] ?? 0);
        $st = strtoupper(trim((string)($l['STATUS'] ?? '')));
        if ($id <= 0 || !isset($ordem[$st])) continue;
        if (!isset($itens[$id]) || $ordem[$st] > $ordem[$itens[$id][0]]) $itens[$id] = [$st, mb_substr(trim((string)($l['MOTIVO'] ?? '')), 0, 160)];
    }
    echo json_encode(['ok' => true, 'itens' => (object)$itens], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('status_itens: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}

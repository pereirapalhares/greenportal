<?php
declare(strict_types=1);

/*
 * Resumo das pendências do usuário logado para o sino do cabeçalho (GER-15, 12/11/2026).
 * Mesma regra de Home › Minhas pendências (classes/MinhasPendencias.php): total, vencidas, a vencer (30 dias) e as
 * 8 mais urgentes (prazo mais antigo primeiro). Só leitura; cada pilar respeita a permissão de Visualizar da transação.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__, 2) . '/classes/MinhasPendencias.php';

try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
    $pend = new MinhasPendencias($pdo, (int)($_SESSION['usuario']['codusuario'] ?? 0), HomologacaoFornecedorAcesso::externo());
    $itens = $pend->itens();
    session_write_close();
    $vencidas = 0; $aVencer = 0; $lista = [];
    foreach ($itens as $i => $it) {
        $sinal = MinhasPendencias::sinal($it['prazo']);
        if ($sinal === 'vencido') $vencidas++;
        elseif ($sinal === 'a-vencer') $aVencer++;
        if ($i < 8) $lista[] = ['pilar' => (string)$it['pilar'], 'titulo' => mb_substr((string)$it['titulo'], 0, 90), 'motivo' => (string)$it['motivo'],
            'prazo' => MinhasPendencias::formatar($it['prazo']), 'sinal' => $sinal, 'url' => '../principal/' . ltrim((string)$it['url'], '/')];
    }
    echo json_encode(['ok' => true, 'total' => count($itens), 'vencidas' => $vencidas, 'a_vencer' => $aVencer, 'itens' => $lista], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('pendencias_resumo: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}

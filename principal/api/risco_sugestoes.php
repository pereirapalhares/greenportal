<?php
declare(strict_types=1);

/*
 * Requisitos legais, etapa 2 (migração 342) — pedidos e decisões das sugestões de risco da IA.
 * POST JSON {acao, ...} com o cabeçalho X-CSRF-Token (RequisitoRiscoIa::csrf). Só procedures e views.
 *   sugerir_item  {area, item}          quem pode alterar o cadastro do item (edição na transação do pilar)
 *   sugerir_lote  {area?}               quem pode incluir em Sugestões de risco (IA) (o lote só roda quando alguém pede)
 *   decidir       {itens:[{codsugestao, decisao: ACEITAR|AJUSTAR|RECUSAR, severidade?, probabilidade?}], motivo?}
 *                                       cada item conferido com a permissão de edição do pilar
 */

require_once dirname(__DIR__, 2) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__, 2) . '/classes/RequisitoRiscoIa.php';
require_once dirname(__DIR__, 2) . '/classes/AuditService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$responder = static function (array $r, int $http = 200): never { http_response_code($http); echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $responder(['ok' => false, 'erro' => 'Método não permitido.'], 405);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) $responder(['ok' => false, 'erro' => 'Sessão expirada.'], 401);
if (!RequisitoRiscoIa::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) $responder(['ok' => false, 'erro' => 'Sessão da tela expirou: recarregue a página.'], 403);
$d = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($d)) $responder(['ok' => false, 'erro' => 'Requisição inválida.'], 400);
$acao = (string)($d['acao'] ?? '');
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';

try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $responder(['ok' => false, 'erro' => 'Sessão expirada.'], 401);
    if (!RequisitoRiscoIa::disponivel($pdo)) $responder(['ok' => false, 'erro' => 'A sugestão de risco pela IA ainda não está instalada nesta empresa (migração 342).'], 409);

    if ($acao === 'sugerir_item') {
        $area = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($d['area'] ?? '')) ?? '');
        $item = (int)($d['item'] ?? 0);
        if (!isset(RequisitoRisco::AREAS[$area]) || $item <= 0) $responder(['ok' => false, 'erro' => 'Selecione um item gravado.'], 400);
        if (!RequisitoRiscoIa::podeDecidir($pdo, $area, $item, $uid)) $responder(['ok' => false, 'erro' => 'Seu perfil não pode alterar o cadastro deste item, então não pode pedir a sugestão.'], 403);
        $r = RequisitoRiscoIa::solicitarItem($pdo, $uid, $idioma, $area, $item);
        AuditService::record($pdo, 'modulolegal_risco_sugestoes', 'SUGERIR_RISCO_ITEM', 'SUCESSO', 'ml_risco_sugestoes', (string)$r['sugestao'], ['area' => $area, 'item' => $item]);
        $responder(['ok' => true, 'resultado' => $r + ['mensagem' => 'Pedido enviado à IA. Quando a sugestão ficar pronta, chega um alerta; nada é gravado no item sem você aceitar.']]);
    }
    if ($acao === 'sugerir_lote') {
        $p = PermissaoTransacao::obter(RequisitoRiscoIa::MENU);
        if (empty($p['pode_incluir'])) $responder(['ok' => false, 'erro' => 'Seu perfil não pode pedir sugestões em lote (Sugestões de risco (IA): incluir).'], 403);
        $area = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($d['area'] ?? '')) ?? '');
        $r = RequisitoRiscoIa::solicitarLote($pdo, $uid, $idioma, $area);
        AuditService::record($pdo, 'modulolegal_risco_sugestoes', 'SUGERIR_RISCO_LOTE', 'SUCESSO', 'ml_risco_lotes', (string)$r['lote'], $r + ['area' => $area]);
        $msg = $r['itens'] === 0 ? 'Nenhum item a pedir: todos os itens ligados a requisitos já têm risco ou uma sugestão aguardando/a revisar.'
            : 'Pedido ' . $r['lote'] . ' enviado à IA: ' . $r['itens'] . ' item(ns) em ' . $r['pedidos'] . ' pedido(s). Quando terminar, chega um alerta.'
              . ($r['restantes'] > 0 ? ' Ficaram ' . $r['restantes'] . ' item(ns) para um próximo pedido (máximo de ' . RequisitoRiscoIa::MAX_LOTE . ' por vez).' : '');
        $responder(['ok' => true, 'resultado' => $r + ['mensagem' => $msg]]);
    }
    if ($acao === 'decidir') {
        $itens = is_array($d['itens'] ?? null) ? $d['itens'] : [];
        if (!$itens) $responder(['ok' => false, 'erro' => 'Marque as sugestões.'], 400);
        $motivo = mb_substr(trim((string)($d['motivo'] ?? '')), 0, 1000);
        $cont = ['ACEITA' => 0, 'AJUSTADA' => 0, 'RECUSADA' => 0]; $erros = [];
        foreach (array_slice($itens, 0, 500) as $i) {
            $cod = (int)($i['codsugestao'] ?? 0);
            $dec = strtoupper((string)($i['decisao'] ?? ''));
            $sev = isset($i['severidade']) && $i['severidade'] !== '' ? (int)$i['severidade'] : null;
            $prob = isset($i['probabilidade']) && $i['probabilidade'] !== '' ? (int)$i['probabilidade'] : null;
            try {
                $r = RequisitoRiscoIa::decidir($pdo, $uid, $cod, $dec, $sev, $prob, $motivo);
                $cont[(string)($r['STATUS'] ?? '')] = ($cont[(string)($r['STATUS'] ?? '')] ?? 0) + 1;
            } catch (Throwable $e) {
                $erros[] = '#' . $cod . ': ' . (($e instanceof PDOException && isset($e->errorInfo[2])) ? (string)$e->errorInfo[2] : $e->getMessage());
            }
        }
        AuditService::record($pdo, 'modulolegal_risco_sugestoes', 'DECIDIR_SUGESTAO_RISCO', $erros ? 'PARCIAL' : 'SUCESSO', 'ml_risco_sugestoes', null, $cont + ['erros' => count($erros)]);
        $responder(['ok' => !$erros || array_sum($cont) > 0, 'resultado' => $cont + ['erros' => $erros], 'erro' => $erros ? implode(' ', array_slice($erros, 0, 3)) : null]);
    }
    $responder(['ok' => false, 'erro' => 'Ação inválida.'], 400);
} catch (IaLimiteExcedido $e) {
    $responder(['ok' => false, 'erro' => $e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('[risco_sugestoes] ' . $e->getMessage());
    $responder(['ok' => false, 'erro' => ($e instanceof PDOException && isset($e->errorInfo[2])) ? (string)$e->errorInfo[2] : $e->getMessage()], 422);
}

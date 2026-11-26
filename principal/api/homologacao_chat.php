<?php
declare(strict_types=1);

/*
 * Mensagens sobre documentos da homologação (migração 420; Revisão G itens 146 e 147 — 26/11/2026).
 * GET  contexto, registro           → {ok, mensagens:[{id,lado,autor,texto,quando,automatica,lida}], meu_lado, csrf}  (marca como lidas)
 * GET  contexto, acao=resumo, ids   → {ok, contagem:{id:{nao_lidas,total}}}
 * POST contexto, registro, texto, csrf → grava a mensagem do meu lado
 * Acesso: o mesmo dos arquivos do documento (GedPermissaoTipo: tela e, para o fornecedor externo, só os próprios registros).
 */
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/GedPermissaoTipo.php';
require_once dirname(__DIR__, 2) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__, 2) . '/classes/HomologacaoChat.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$json = static function (array $d, int $s = 200): never { http_response_code($s); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$T = static fn(string $k, array $p): string => IdiomaService::t('homologacao_chat.' . $k, $p);
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);
$contexto = (string)($_POST['contexto'] ?? $_GET['contexto'] ?? '');
if (!in_array($contexto, HomologacaoChat::CONTEXTOS, true)) $json(['ok' => false, 'message' => 'Contexto inválido.'], 400);
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);
    if (!HomologacaoChat::disponivel($pdo)) $json(['ok' => false, 'message' => $T('sem_migracao', ['pt' => 'Mensagens ainda não instaladas nesta empresa (migração 420).', 'en' => 'Messages not installed (migration 420).', 'es' => 'Mensajes no instalados (migración 420).'])], 503);
    $perm = GedPermissaoTipo::obter($contexto);
    if (empty($perm['pode_visualizar'])) $json(['ok' => false, 'message' => $T('sem_permissao', ['pt' => 'Sem permissão para este documento.', 'en' => 'No permission for this document.', 'es' => 'Sin permiso para este documento.'])], 403);
    $lado = HomologacaoFornecedorAcesso::externo() ? 'FORNECEDOR' : 'EMPRESA';
    $uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
    if (($_GET['acao'] ?? '') === 'resumo') {
        $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
        $ids = array_values(array_filter($ids, static fn($id) => GedPermissaoTipo::registroPermitido($pdo, $contexto, $id)));
        $json(['ok' => true, 'contagem' => HomologacaoChat::naoLidas($pdo, $contexto, $ids, $lado)]);
    }
    $registro = (int)($_POST['registro'] ?? $_GET['registro'] ?? 0);
    if ($registro <= 0 || !GedPermissaoTipo::registroPermitido($pdo, $contexto, $registro)) $json(['ok' => false, 'message' => $T('registro', ['pt' => 'Documento não encontrado ou sem acesso.', 'en' => 'Document not found or no access.', 'es' => 'Documento no encontrado o sin acceso.'])], 403);
    if (empty($_SESSION['homologacao_chat_csrf'])) $_SESSION['homologacao_chat_csrf'] = bin2hex(random_bytes(24));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals((string)$_SESSION['homologacao_chat_csrf'], (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => $T('csrf', ['pt' => 'A janela expirou: abra as mensagens de novo.', 'en' => 'The window expired: open the messages again.', 'es' => 'La ventana expiró: abra los mensajes de nuevo.'])], 419);
        if (($_POST['acao'] ?? '') === 'excluir') {
            if (!HomologacaoChat::excluir($pdo, $contexto, $registro, (int)($_POST['id'] ?? 0), $uid, $lado)) $json(['ok' => false, 'message' => $T('nao_exclui', ['pt' => 'Só dá para excluir a sua própria mensagem enquanto ela não foi lida.', 'en' => 'You can only delete your own message while it is unread.', 'es' => 'Solo puede eliminar su propio mensaje mientras no fue leído.'])], 422);
            try { GreenPortalDatabase::call($pdo, 'CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $uid, 't' => 'homologacao_chat', 'a' => 'EXCLUIR_MENSAGEM', 'en' => $contexto, 'r' => (string)$registro, 'res' => 'SUCESSO', 'd' => json_encode(['id' => (int)($_POST['id'] ?? 0)])]); } catch (Throwable) {}
        } else {
        $texto = trim((string)($_POST['texto'] ?? ''));
        if ($texto === '') $json(['ok' => false, 'message' => $T('vazio', ['pt' => 'Escreva a mensagem.', 'en' => 'Write the message.', 'es' => 'Escriba el mensaje.'])], 422);
        HomologacaoChat::enviar($pdo, $contexto, $registro, $uid, $lado, $texto);
        try { GreenPortalDatabase::call($pdo, 'CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $uid, 't' => 'homologacao_chat', 'a' => 'MENSAGEM', 'en' => $contexto, 'r' => (string)$registro, 'res' => 'SUCESSO', 'd' => json_encode(['lado' => $lado])]); } catch (Throwable) {}
        }
    } else HomologacaoChat::marcarLidas($pdo, $contexto, $registro, $lado);
    $msgs = array_map(static fn($m) => ['id' => (int)$m['CODMENSAGEM'], 'lado' => (string)$m['LADO'], 'autor' => (int)$m['AUTOMATICA'] ? $T('automatica', ['pt' => 'Análise automática (IA)', 'en' => 'Automatic analysis (AI)', 'es' => 'Análisis automático (IA)']) : (string)($m['USUARIO'] ?? ''),
        'texto' => (string)$m['TEXTO'], 'quando' => (string)$m['CRIADO_EM'], 'automatica' => (int)$m['AUTOMATICA'] === 1, 'lida' => !empty($m['LIDA_EM']), 'pode_excluir' => (string)$m['LADO'] === $lado && (int)$m['CODUSUARIO'] === $uid && $uid > 0 && empty($m['LIDA_EM']) && (int)$m['AUTOMATICA'] !== 1], HomologacaoChat::mensagens($pdo, $contexto, $registro));
    $json(['ok' => true, 'mensagens' => $msgs, 'meu_lado' => $lado, 'csrf' => (string)$_SESSION['homologacao_chat_csrf']]);
} catch (Throwable $e) {
    $json(['ok' => false, 'message' => $T('erro', ['pt' => 'Não foi possível carregar as mensagens.', 'en' => 'Could not load the messages.', 'es' => 'No fue posible cargar los mensajes.']) . ' (' . mb_strimwidth($e->getMessage(), 0, 160, '…') . ')'], 500);
}

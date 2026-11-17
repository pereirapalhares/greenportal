<?php
declare(strict_types=1);

/*
 * Assistente da aplicação ("Como posso ajudar?") — painel lateral do portal (main.php).
 *
 * Ações (POST, com o token csrf do assistente gerado em main.php):
 *   estado    — se o assistente está liberado para o usuário, perguntas usadas hoje, sugestões para a tela aberta
 *               e a conversa em andamento (para reabrir o painel onde parou).
 *   perguntar — envia a pergunta (pergunta, transacao = código do menu da tela aberta, codconversa).
 *   avaliar   — marca a resposta como útil (nota = 1) ou não (nota = -1).
 *   nova      — encerra a conversa atual; a próxima pergunta abre outra.
 *   rascunho_solicitacao  — (etapa 2) a IA redige a solicitação de melhoria a partir de uma resposta (codmensagem); nada é gravado.
 *   registrar_solicitacao — (etapa 2) grava a solicitação revisada e confirmada pelo usuário.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/AssistenteAplicacaoService.php';
require_once dirname(__DIR__, 2) . '/classes/SolicitacaoMelhoriaService.php';

$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$sessaoExpirada = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $json(['ok' => false, 'message' => 'Método não permitido.'], 405);
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
$csrf = (string)($_SESSION['assistente_ia_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);

$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
// A pergunta pode demorar (resposta da IA): a sessão não precisa ficar presa enquanto isso.
$usuario = (array)$_SESSION['usuario'];
$permissoes = (array)($_SESSION['permissoes'] ?? []);
$idioma = IdiomaService::idioma();
session_write_close();

$servico = new AssistenteAplicacaoService($pdo, $usuario, $permissoes, $idioma);
if (!$servico->config()) $json(['ok' => false, 'message' => IdiomaService::t('assistente.erro.migracao', ['pt' => 'O assistente ainda não foi instalado nesta empresa (migração 225).', 'en' => 'The assistant is not installed for this company yet (migration 225).', 'es' => 'El asistente aún no fue instalado en esta empresa (migración 225).'])], 503);
if (!$servico->liberado()) $json(['ok' => false, 'message' => IdiomaService::t('assistente.erro.perfil', ['pt' => 'O assistente não está liberado para o seu perfil.', 'en' => 'The assistant is not available for your profile.', 'es' => 'El asistente no está habilitado para su perfil.'])], 403);

$auditar = static function (string $acao, array $detalhes) use ($pdo, $usuario): void {
    try {
        AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_auditoria_registrar', [(int)($usuario['codusuario'] ?? 0), 'ASSISTENTE_IA', $acao, 'ia_assistente_mensagens', (string)($detalhes['codmensagem'] ?? ''), 'SUCESSO', json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log($e->getMessage()); }
};

$acao = strtolower(trim((string)($_POST['action'] ?? 'estado')));
$transacao = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_POST['transacao'] ?? '')) ?? '';
try {
    switch ($acao) {
        case 'estado':
            $limite = (int)$servico->config()['limite_diario'];
            $json(['ok' => true, 'usados' => $servico->usadosHoje(), 'limite' => $limite, 'sugestoes' => $servico->sugestoes($transacao), 'solicitacoes' => $servico->solicitacoesDisponiveis()] + $servico->conversaAtual());
        case 'perguntar':
            $codconversa = (int)($_POST['codconversa'] ?? 0) ?: null;
            $resultado = $servico->perguntar((string)($_POST['pergunta'] ?? ''), $transacao, $codconversa);
            $auditar('PERGUNTAR', ['codmensagem' => $resultado['codmensagem'], 'categoria' => $resultado['categoria'], 'tela' => $transacao, 'acoes' => array_column($resultado['acoes'], 'codigo')]);
            $json(['ok' => true] + $resultado);
        case 'avaliar':
            $ok = $servico->avaliar((int)($_POST['codmensagem'] ?? 0), (int)($_POST['nota'] ?? 0));
            $json(['ok' => $ok]);
        case 'nova':
            $codconversa = (int)($_POST['codconversa'] ?? 0);
            if ($codconversa > 0) $servico->encerrar($codconversa);
            $json(['ok' => true, 'sugestoes' => $servico->sugestoes($transacao)]);
        case 'rascunho_solicitacao':
        case 'registrar_solicitacao':
            if (!$servico->solicitacoesDisponiveis()) $json(['ok' => false, 'message' => IdiomaService::t('assistente.erro.solicitacoes', ['pt' => 'As solicitações de melhoria ainda não foram instaladas nesta empresa (migração 230).', 'en' => 'Improvement requests are not installed for this company yet (migration 230).', 'es' => 'Las solicitudes de mejora aún no fueron instaladas en esta empresa (migración 230).'])], 503);
            $solicitacoes = new SolicitacaoMelhoriaService($pdo, $usuario, $permissoes, $idioma);
            $codmensagem = (int)($_POST['codmensagem'] ?? 0);
            if ($acao === 'rascunho_solicitacao') $json(['ok' => true] + $solicitacoes->rascunhoDaMensagem($codmensagem));
            $cod = $solicitacoes->registrar($_POST, 'ASSISTENTE', $codmensagem);
            $json(['ok' => true, 'codsolicitacao' => $cod, 'message' => IdiomaService::f('solicitacoes.msg.registrada', ['pt' => 'Solicitação #{n} registrada. Você será avisado quando a situação mudar.', 'en' => 'Request #{n} registered. You will be notified when its status changes.', 'es' => 'Solicitud #{n} registrada. Se le avisará cuando cambie su situación.'], ['n' => $cod])]);
        default:
            $json(['ok' => false, 'message' => 'Ação inválida.'], 400);
    }
} catch (RuntimeException $e) {
    // PDOException também é RuntimeException: erro de banco não vai com a mensagem técnica para a tela.
    if ($e instanceof PDOException) { error_log('assistente_ia: ' . $e->getMessage()); $json(['ok' => false, 'message' => IdiomaService::t('assistente.erro.geral', ['pt' => 'Não foi possível concluir. Tente novamente.', 'en' => 'Could not complete. Please try again.', 'es' => 'No fue posible completar. Inténtelo de nuevo.'])], 500); }
    $json(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('assistente_ia: ' . $e->getMessage());
    $json(['ok' => false, 'message' => IdiomaService::t('assistente.erro.geral', ['pt' => 'Não foi possível concluir. Tente novamente.', 'en' => 'Could not complete. Please try again.', 'es' => 'No fue posible completar. Inténtelo de nuevo.'])], 500);
}

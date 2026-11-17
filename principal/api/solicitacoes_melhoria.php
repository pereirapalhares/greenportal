<?php
declare(strict_types=1);

/*
 * Solicitações de melhoria — telas Minhas solicitações e Gestão de solicitações.
 *
 * Ações (POST, com o token csrf das telas de solicitações):
 *   minhas    — solicitações do usuário logado (qualquer usuário).
 *   criar     — nova solicitação pela tela (qualquer usuário).
 *   detalhe   — uma solicitação com o histórico (dono ou gestão).
 *   listar    — todas as solicitações da empresa, com filtros (gestão).
 *   atualizar — muda situação, escopo e resposta; avisa quem pediu (gestão com permissão de edição).
 *   sinais    — sinais do assistente: "não ajudou", dificuldades, erros e funcionalidades inexistentes (gestão).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/SolicitacaoMelhoriaService.php';

$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$sessaoExpirada = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
$erroGeral = ['pt' => 'Não foi possível concluir. Tente novamente.', 'en' => 'Could not complete. Please try again.', 'es' => 'No fue posible completar. Inténtelo de nuevo.'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $json(['ok' => false, 'message' => 'Método não permitido.'], 405);
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
$csrf = (string)($_SESSION['solicitacoes_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);

$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
$servico = new SolicitacaoMelhoriaService($pdo, (array)$_SESSION['usuario'], (array)($_SESSION['permissoes'] ?? []), IdiomaService::idioma());
session_write_close();

$semPermissao = static fn() => $json(['ok' => false, 'message' => IdiomaService::t('solicitacoes.erro.permissao', ['pt' => 'Seu perfil não pode gerir as solicitações.', 'en' => 'Your profile cannot manage requests.', 'es' => 'Su perfil no puede gestionar las solicitudes.'])], 403);
$acao = strtolower(trim((string)($_POST['action'] ?? '')));
try {
    switch ($acao) {
        case 'minhas':
            $json(['ok' => true, 'itens' => $servico->minhas()]);
        case 'criar':
            $cod = $servico->registrar($_POST, 'TELA');
            $json(['ok' => true, 'codsolicitacao' => $cod, 'message' => IdiomaService::f('solicitacoes.msg.registrada', ['pt' => 'Solicitação #{n} registrada. Você será avisado quando a situação mudar.', 'en' => 'Request #{n} registered. You will be notified when its status changes.', 'es' => 'Solicitud #{n} registrada. Se le avisará cuando cambie su situación.'], ['n' => $cod])]);
        case 'detalhe':
            $json(['ok' => true, 'item' => $servico->detalhe((int)($_POST['id'] ?? 0), $servico->podeGerir() && !empty($_POST['gestao']))]);
        case 'listar':
            if (!$servico->podeGerir()) $semPermissao();
            $json(['ok' => true] + $servico->listar($_POST));
        case 'atualizar':
            if (!$servico->podeGerir(true)) $semPermissao();
            $json(['ok' => true, 'item' => $servico->atualizar((int)($_POST['id'] ?? 0), $_POST), 'message' => IdiomaService::t('solicitacoes.msg.atualizada', ['pt' => 'Solicitação atualizada.', 'en' => 'Request updated.', 'es' => 'Solicitud actualizada.'])]);
        case 'sinais':
            if (!$servico->podeGerir()) $semPermissao();
            $json(['ok' => true] + $servico->sinais((int)($_POST['dias'] ?? 30)));
        default:
            $json(['ok' => false, 'message' => 'Ação inválida.'], 400);
    }
} catch (RuntimeException $e) {
    if ($e instanceof PDOException) { error_log('solicitacoes_melhoria: ' . $e->getMessage()); $json(['ok' => false, 'message' => IdiomaService::t('solicitacoes.erro.geral', $erroGeral)], 500); }
    $json(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('solicitacoes_melhoria: ' . $e->getMessage());
    $json(['ok' => false, 'message' => IdiomaService::t('solicitacoes.erro.geral', $erroGeral)], 500);
}

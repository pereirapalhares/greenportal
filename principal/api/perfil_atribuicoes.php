<?php
declare(strict_types=1);

/*
 * API do perfil de atribuições (migração 268).
 *   GET  action=status           situação do usuário logado (popup no login), adiado nesta sessão, pendências da equipe
 *   POST action=adiar            "Lembrar depois": não abre de novo nesta sessão (volta no próximo login)
 *   POST action=salvar           respostas = JSON {codigo_pergunta: valor | [valores]} → envia para aprovação do gestor
 *   GET  action=equipe           situação da equipe (gestor) ou de todos (permissão da gestão)
 *   GET  action=detalhe&id=N     respostas de um envio, com os textos das perguntas
 *   POST action=decidir          id, acao APROVAR | DEVOLVER, motivo
 * Gestão: menu PERFIL_ATRIBUICOES_GESTAO — para ADM_DA_EMPRESA, Visualizar = vê todos e Editar = decide qualquer perfil. O gestor
 * (usuarios.codusuario_superior) vê e decide só a sua equipe. Ninguém decide o próprio perfil (exceto ADMINISTRADOR).
 */

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/PerfilAtribuicoesService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$json = static function (array $dados, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
};
$t = static fn(string $k, array $p): string => IdiomaService::t('perfil_atribuicoes.' . $k, $p);
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
} catch (Throwable) {
    $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);
}
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$admin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
$perm = PermissaoTransacao::obter('PERFIL_ATRIBUICOES_GESTAO');
// Ver / decidir o perfil de qualquer usuário: só ADMINISTRADOR e ADM_DA_EMPRESA (com a permissão da tela). O ADM_DA_EMPRESA
// também faz o papel de gestor; o perfil GESTOR (ou qualquer outro) nunca faz o papel do ADM_DA_EMPRESA: decide só a sua equipe
// (usuários que o têm como superior), mesmo que receba a permissão da tela.
$admEmpresa = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADM_DA_EMPRESA';
$verTodos = $admEmpresa && !empty($perm['pode_visualizar']);
$decideTodos = $admEmpresa && !empty($perm['pode_editar']);
if (empty($_SESSION['perfil_atribuicoes_csrf'])) $_SESSION['perfil_atribuicoes_csrf'] = bin2hex(random_bytes(32));
$action = (string)($_REQUEST['action'] ?? 'status');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals((string)$_SESSION['perfil_atribuicoes_csrf'], (string)($_POST['csrf'] ?? '')))
    $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);

try { $svc = new PerfilAtribuicoesService($pdo, PerfilAtribuicoesService::universal()); }
catch (Throwable $e) { error_log('perfil_atribuicoes: ' . $e->getMessage()); $json(['ok' => false, 'message' => 'Erro'], 500); }

$alertar = static function (int $para, string $titulo, string $mensagem, string $link) use ($pdo): void {
    if ($para < 1) return;
    try {
        GreenPortalDatabase::call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'alertas', 'dados' => json_encode(
            ['codusuario' => $para, 'titulo' => mb_substr($titulo, 0, 180), 'mensagem' => $mensagem, 'link' => $link, 'lido' => 0], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log('perfil_atribuicoes alerta: ' . $e->getMessage()); }
};
$nome = (string)($_SESSION['usuario']['nome'] ?? '');

try {
    switch ($action) {
        case 'status':
            $s = $svc->situacao($_SESSION);
            $equipe = 0;
            foreach ($svc->equipe($usuario, false) as $r) if (($r['ultimo_status'] ?? '') === 'PENDENTE_APROVACAO') $equipe++;
            $json(['ok' => true] + $s + ['adiado' => !empty($_SESSION['perfil_atribuicoes_adiado']), 'equipe_pendentes' => $equipe]);

        case 'adiar':
            $_SESSION['perfil_atribuicoes_adiado'] = 1;
            $json(['ok' => true]);

        case 'salvar':
            if (empty($svc->situacao($_SESSION)['aplica'])) $json(['ok' => false, 'message' => $t('msg.nao_aplica', ['pt' => 'O questionário de atribuições não se aplica ao seu perfil de acesso.', 'en' => 'The responsibilities questionnaire does not apply to your access profile.', 'es' => 'El cuestionario de atribuciones no se aplica a su perfil de acceso.'])], 422);
            $respostas = json_decode((string)($_POST['respostas'] ?? '{}'), true);
            $r = $svc->enviar($_SESSION, is_array($respostas) ? $respostas : []);
            if (!$r['ok']) $json(['ok' => false, 'faltando' => $r['faltando'], 'message' => $t('msg.faltando', ['pt' => 'Responda as perguntas obrigatórias:', 'en' => 'Answer the required questions:', 'es' => 'Responda las preguntas obligatorias:'])], 422);
            unset($_SESSION['perfil_atribuicoes_adiado']);
            $titulo = $t('alerta.enviado_titulo', ['pt' => 'Perfil de atribuições para aprovar', 'en' => 'Responsibilities profile to approve', 'es' => 'Perfil de atribuciones para aprobar']);
            $msg = $nome . ' ' . $t('alerta.enviado_msg', ['pt' => 'enviou o perfil de atribuições para a sua aprovação.', 'en' => 'submitted the responsibilities profile for your approval.', 'es' => 'envió el perfil de atribuciones para su aprobación.']);
            if ($r['codgestor']) $alertar((int)$r['codgestor'], $titulo, $msg, '/principal/perfil_atribuicoes_gestao.php?id=' . $r['codperfilatrib']);
            else foreach (GreenPortalDatabase::call($pdo, "SELECT codusuario FROM vw_usuarios_grid WHERE ativo = 1 AND UPPER(perfil_codigo) = 'ADM_DA_EMPRESA' AND codusuario <> ?", [$usuario]) as $a)
                $alertar((int)$a['codusuario'], $titulo, $msg . ' ' . $t('alerta.sem_gestor', ['pt' => '(usuário sem gestor cadastrado)', 'en' => '(user has no manager)', 'es' => '(usuario sin gestor registrado)']), '/principal/perfil_atribuicoes_gestao.php?id=' . $r['codperfilatrib']);
            $json(['ok' => true, 'codperfilatrib' => $r['codperfilatrib'], 'tem_gestor' => (bool)$r['codgestor'],
                'message' => $r['codgestor'] ? $t('msg.enviado', ['pt' => 'Perfil enviado para a aprovação do seu gestor.', 'en' => 'Profile submitted to your manager for approval.', 'es' => 'Perfil enviado para la aprobación de su gestor.'])
                    : $t('msg.enviado_sem_gestor', ['pt' => 'Perfil enviado. Você não tem gestor cadastrado: o administrador da empresa fará a aprovação.', 'en' => 'Profile submitted. You have no manager registered: the company administrator will approve it.', 'es' => 'Perfil enviado. No tiene gestor registrado: el administrador de la empresa hará la aprobación.'])]);

        case 'equipe':
            $todos = $verTodos || $admin;
            $linhas = $svc->equipe($usuario, $todos);
            $hoje = date('Y-m-d');
            $saida = array_map(static function ($r) use ($hoje, $usuario, $decideTodos, $admin) {
                $st = (string)($r['ultimo_status'] ?? '');
                $sit = $st === '' ? 'NUNCA_RESPONDEU' : ($st === 'APROVADO' && !empty($r['valido_ate']) && (string)$r['valido_ate'] < $hoje ? 'VENCIDO' : $st);
                $pode = $st === 'PENDENTE_APROVACAO' && ((int)$r['codusuario'] !== $usuario || $admin) && ($decideTodos || $admin || (int)($r['codgestor'] ?? 0) === $usuario);
                return ['codusuario' => (int)$r['codusuario'], 'usuario' => $r['usuario_nome'], 'login' => $r['usuario_login'], 'gestor' => $r['gestor_nome'],
                    'situacao' => $sit, 'enviado_em' => $r['ultimo_enviado_em'], 'aprovado_em' => $r['aprovado_em'], 'valido_ate' => $r['valido_ate'],
                    'motivo' => $r['ultimo_motivo'], 'codperfilatrib' => $r['ultimo_codperfilatrib'] ? (int)$r['ultimo_codperfilatrib'] : null,
                    'vigente' => $r['vigente_codperfilatrib'] ? (int)$r['vigente_codperfilatrib'] : null, 'pode_decidir' => $pode];
            }, $linhas);
            $json(['ok' => true, 'todos' => $todos, 'itens' => $saida]);

        case 'detalhe':
            $env = $svc->envio((int)($_GET['id'] ?? 0));
            if (!$env) $json(['ok' => false, 'message' => $t('msg.nao_encontrado', ['pt' => 'Perfil não encontrado.', 'en' => 'Profile not found.', 'es' => 'Perfil no encontrado.'])], 404);
            $dono = (int)$env['codusuario'];
            $daEquipe = in_array($dono, array_map('intval', array_column($svc->equipe($usuario, false), 'codusuario')), true);
            if ($dono !== $usuario && !$verTodos && !$admin && (int)$env['codgestor'] !== $usuario && !$daEquipe)
                $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.'])], 403);
            $resp = json_decode((string)$env['respostas'], true) ?: [];
            $blocos = [];
            // Perguntas da versão que o usuário respondeu (não da versão atual): o gestor vê o que ele viu
            $catEnvio = (int)($env['codversao'] ?? 0) > 0 ? $svc->catalogoVersao((int)$env['codversao']) : null;
            $catAtual = $svc->catalogoCompleto();
            foreach ((($catEnvio ?? $catAtual)['blocos'] ?? []) as $b) {
                if (!in_array($b['codigo'], explode(',', (string)$env['modulos']), true)) continue;
                $ps = [];
                foreach ($b['perguntas'] as $p) {
                    $v = $resp[$p['codigo']] ?? null;
                    $rot = array_column($p['opcoes'], 'rotulo', 'codigo');
                    $txt = array_map(static fn($x) => $rot[$x] ?? ($x === 'SIM' ? IdiomaService::t('comum.sim', ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí']) : ($x === 'NAO' ? IdiomaService::t('comum.nao', ['pt' => 'Não', 'en' => 'No', 'es' => 'No']) : $x)), (array)($v ?? []));
                    $ps[] = ['codigo' => $p['codigo'], 'texto' => $p['texto'], 'resposta' => $txt, 'pilar' => str_contains($p['codigo'], '.PILAR.')];
                }
                $blocos[] = ['codigo' => $b['codigo'], 'titulo' => $b['titulo'], 'perguntas' => $ps];
            }
            $pode = $env['status'] === 'PENDENTE_APROVACAO' && ($dono !== $usuario || $admin) && ($decideTodos || $admin || (int)$env['codgestor'] === $usuario);
            $json(['ok' => true, 'envio' => ['id' => (int)$env['codperfilatrib'], 'usuario' => $env['usuario_nome'], 'gestor' => $env['gestor_nome'], 'status' => $env['status'],
                'enviado_em' => $env['enviado_em'], 'decidido_por' => $env['decidido_por_nome'], 'decidido_em' => $env['decidido_em'], 'motivo' => $env['motivo'],
                'valido_ate' => $env['valido_ate'], 'versao' => (int)$env['versao'], 'versao_atual' => (int)($catAtual['versao'] ?? 0)], 'blocos' => $blocos, 'pode_decidir' => $pode]);

        case 'decidir':
            $id = (int)($_POST['id'] ?? 0);
            $acao = strtoupper((string)($_POST['acao'] ?? ''));
            $motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 1000);
            $env = $svc->envio($id);
            if (!$env) $json(['ok' => false, 'message' => $t('msg.nao_encontrado', ['pt' => 'Perfil não encontrado.', 'en' => 'Profile not found.', 'es' => 'Perfil no encontrado.'])], 404);
            try { $r = $svc->decidir($id, $usuario, $decideTodos || $admin, $admin, $acao, $motivo); }
            catch (PDOException $e) {
                $erros = [
                    'MOTIVO_OBRIGATORIO' => $t('erro.motivo', ['pt' => 'Escreva o motivo da devolução.', 'en' => 'Write the reason for returning it.', 'es' => 'Escriba el motivo de la devolución.']),
                    'PERFIL_NAO_PENDENTE' => $t('erro.nao_pendente', ['pt' => 'Este envio não está mais aguardando aprovação.', 'en' => 'This submission is no longer waiting for approval.', 'es' => 'Este envío ya no espera aprobación.']),
                    'PROPRIO_PERFIL' => $t('erro.proprio', ['pt' => 'Você não pode aprovar o seu próprio perfil.', 'en' => 'You cannot approve your own profile.', 'es' => 'No puede aprobar su propio perfil.']),
                    'SEM_PERMISSAO' => IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']),
                ];
                foreach ($erros as $cod => $m) if (str_contains($e->getMessage(), $cod)) $json(['ok' => false, 'message' => $m], 422);
                throw $e;
            }
            $dono = (int)($r['codusuario'] ?? $env['codusuario']);
            if ($acao === 'APROVAR') $alertar($dono, $t('alerta.aprovado_titulo', ['pt' => 'Perfil de atribuições aprovado', 'en' => 'Responsibilities profile approved', 'es' => 'Perfil de atribuciones aprobado']),
                $nome . ' ' . $t('alerta.aprovado_msg', ['pt' => 'aprovou o seu perfil de atribuições.', 'en' => 'approved your responsibilities profile.', 'es' => 'aprobó su perfil de atribuciones.']), '/principal/perfil_atribuicoes.php');
            else $alertar($dono, $t('alerta.devolvido_titulo', ['pt' => 'Perfil de atribuições devolvido', 'en' => 'Responsibilities profile returned', 'es' => 'Perfil de atribuciones devuelto']),
                $nome . ' ' . $t('alerta.devolvido_msg', ['pt' => 'devolveu o seu perfil de atribuições:', 'en' => 'returned your responsibilities profile:', 'es' => 'devolvió su perfil de atribuciones:']) . ' ' . $motivo, '/principal/perfil_atribuicoes.php');
            $json(['ok' => true, 'message' => $acao === 'APROVAR' ? $t('msg.aprovado', ['pt' => 'Perfil aprovado.', 'en' => 'Profile approved.', 'es' => 'Perfil aprobado.']) : $t('msg.devolvido', ['pt' => 'Perfil devolvido ao usuário.', 'en' => 'Profile returned to the user.', 'es' => 'Perfil devuelto al usuario.'])]);
    }
    $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);
} catch (Throwable $e) {
    error_log('perfil_atribuicoes: ' . $e->getMessage());
    $json(['ok' => false, 'message' => $t('erro.geral', ['pt' => 'Não foi possível concluir agora. Tente de novo em instantes.', 'en' => 'Could not complete now. Try again shortly.', 'es' => 'No fue posible concluir ahora. Intente de nuevo en instantes.'])], 500);
}

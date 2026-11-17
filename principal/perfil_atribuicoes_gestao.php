<?php
declare(strict_types=1);

/*
 * Perfis de atribuições — gestão (menu PERFIL_ATRIBUICOES_GESTAO, Administração; migração 268).
 * Gestor: a sua equipe (usuários com ele como superior), aprova ou devolve. Com a permissão da tela: todos os usuários
 * (Visualizar) e decisão de qualquer perfil (Editar). Dados: principal/api/perfil_atribuicoes.php (equipe, detalhe, decidir).
 * ?id=N abre direto o envio (link do alerta).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/PerfilAtribuicoesService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$t = static fn(string $k, array $p): string => IdiomaService::t('perfil_atribuicoes.' . $k, $p);
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
    $svc = new PerfilAtribuicoesService($pdo, PerfilAtribuicoesService::universal());
    $usuario = (int)$_SESSION['usuario']['codusuario'];
    $admin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
    // Todos os usuários: ADMINISTRADOR e ADM_DA_EMPRESA (com a permissão da tela). GESTOR e demais: só a própria equipe.
    $verTodos = $admin || (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADM_DA_EMPRESA'
        && !empty(PermissaoTransacao::obter('PERFIL_ATRIBUICOES_GESTAO')['pode_visualizar']));
    $temEquipe = $svc->temEquipe($usuario);
} catch (Throwable $e) {
    error_log('perfil_atribuicoes_gestao: ' . $e->getMessage());
    http_response_code(500);
    exit($h(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])));
}
if (!$verTodos && !$temEquipe) {
    http_response_code(403);
    exit($h(IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.'])));
}
if (empty($_SESSION['perfil_atribuicoes_csrf'])) $_SESSION['perfil_atribuicoes_csrf'] = bin2hex(random_bytes(32));
$T = [
    'titulo' => $t('gestao.titulo', ['pt' => 'Perfis de atribuições', 'en' => 'Responsibility profiles', 'es' => 'Perfiles de atribuciones']),
    'sub_todos' => $t('gestao.sub_todos', ['pt' => 'Todos os usuários ativos da empresa.', 'en' => 'All active users in the company.', 'es' => 'Todos los usuarios activos de la empresa.']),
    'sub_equipe' => $t('gestao.sub_equipe', ['pt' => 'Usuários da sua equipe (que têm você como superior).', 'en' => 'Your team (users who have you as superior).', 'es' => 'Usuarios de su equipo (que lo tienen como superior).']),
    'buscar' => $t('gestao.buscar', ['pt' => 'Buscar usuário ou gestor', 'en' => 'Search user or manager', 'es' => 'Buscar usuario o gestor']),
    'todas' => $t('gestao.todas', ['pt' => 'Todas as situações', 'en' => 'All statuses', 'es' => 'Todas las situaciones']),
    'c_usuario' => $t('gestao.c_usuario', ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario']),
    'c_gestor' => $t('gestao.c_gestor', ['pt' => 'Gestor', 'en' => 'Manager', 'es' => 'Gestor']),
    'c_situacao' => $t('gestao.c_situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
    'c_enviado' => $t('gestao.c_enviado', ['pt' => 'Enviado em', 'en' => 'Submitted on', 'es' => 'Enviado el']),
    'c_aprovado' => $t('gestao.c_aprovado', ['pt' => 'Aprovado em', 'en' => 'Approved on', 'es' => 'Aprobado el']),
    'c_valido' => $t('gestao.c_valido', ['pt' => 'Válido até', 'en' => 'Valid until', 'es' => 'Válido hasta']),
    's_NUNCA_RESPONDEU' => $t('situacao.nunca', ['pt' => 'Não respondeu', 'en' => 'Not answered', 'es' => 'No respondió']),
    's_PENDENTE_APROVACAO' => $t('situacao.pendente', ['pt' => 'Aguardando aprovação', 'en' => 'Waiting for approval', 'es' => 'Esperando aprobación']),
    's_DEVOLVIDO' => $t('situacao.devolvido', ['pt' => 'Devolvido', 'en' => 'Returned', 'es' => 'Devuelto']),
    's_APROVADO' => $t('situacao.aprovado', ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado']),
    's_VENCIDO' => $t('situacao.vencido', ['pt' => 'Revisão vencida', 'en' => 'Review overdue', 'es' => 'Revisión vencida']),
    'sem_gestor' => $t('gestao.sem_gestor', ['pt' => '(sem gestor)', 'en' => '(no manager)', 'es' => '(sin gestor)']),
    'ver' => $t('gestao.ver', ['pt' => 'Ver respostas', 'en' => 'View answers', 'es' => 'Ver respuestas']),
    'decidir' => $t('gestao.decidir', ['pt' => 'Aprovar / devolver', 'en' => 'Approve / return', 'es' => 'Aprobar / devolver']),
    'aprovar' => $t('gestao.aprovar', ['pt' => 'Aprovar', 'en' => 'Approve', 'es' => 'Aprobar']),
    'devolver' => $t('gestao.devolver', ['pt' => 'Devolver', 'en' => 'Return', 'es' => 'Devolver']),
    'motivo' => $t('gestao.motivo', ['pt' => 'Observação (obrigatória para devolver)', 'en' => 'Note (required to return)', 'es' => 'Observación (obligatoria para devolver)']),
    'fechar' => $t('gestao.fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar']),
    'nada' => $t('gestao.nada', ['pt' => 'Nenhum usuário encontrado.', 'en' => 'No users found.', 'es' => 'Ningún usuario encontrado.']),
    'sem_resposta' => $t('gestao.sem_resposta', ['pt' => 'não participa', 'en' => 'does not take part', 'es' => 'no participa']),
    'versao_respondida' => $t('gestao.versao_respondida', ['pt' => 'Respostas na versão {versao}, a que o usuário respondeu. A versão em uso hoje é a {atual}.', 'en' => 'Answers shown in version {versao}, the one the user answered. The version in use today is {atual}.', 'es' => 'Respuestas en la versión {versao}, la que el usuario respondió. La versión en uso hoy es la {atual}.']),
    'info_envio' => $t('gestao.info_envio', ['pt' => 'Enviado em {enviado} · gestor: {gestor} · versão {versao}', 'en' => 'Submitted on {enviado} · manager: {gestor} · version {versao}', 'es' => 'Enviado el {enviado} · gestor: {gestor} · versión {versao}']),
    'info_decisao' => $t('gestao.info_decisao', ['pt' => '{situacao} por {quem} em {quando}', 'en' => '{situacao} by {quem} on {quando}', 'es' => '{situacao} por {quem} el {quando}']),
    'sem_envio' => $t('gestao.sem_envio', ['pt' => 'Este usuário ainda não enviou o perfil.', 'en' => 'This user has not submitted the profile yet.', 'es' => 'Este usuario aún no envió el perfil.']),
    'erro' => $t('erro.geral', ['pt' => 'Não foi possível concluir agora. Tente de novo em instantes.', 'en' => 'Could not complete now. Try again shortly.', 'es' => 'No fue posible concluir ahora. Intente de nuevo en instantes.']),
    'total' => $t('gestao.total', ['pt' => '{n} usuário(s)', 'en' => '{n} user(s)', 'es' => '{n} usuario(s)']),
];
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $h(IdiomaService::idioma()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($T['titulo']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5">
<link rel="stylesheet" href="../assets/css/main.css?v=18">
<link rel="stylesheet" href="../assets/css/perfil-atribuicoes.css?v=1">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
</head>
<body class="pa-pagina">
<main class="pag" data-pag data-csrf="<?= $h($_SESSION['perfil_atribuicoes_csrf']) ?>" data-abrir="<?= (int)($_GET['id'] ?? 0) ?>">
    <div class="pag-barra">
        <div class="me-auto"><strong><?= $h($T['titulo']) ?></strong> <span class="text-muted small" data-pag-sub></span>
            <button type="button" class="btn btn-sm btn-link" data-open-transacao-ajuda data-help-transaction="perfil_atribuicoes_gestao" title="<?= $h($T['titulo']) ?>"><i class="bi bi-question-circle"></i></button></div>
        <input type="search" class="form-control form-control-sm" data-pag-busca placeholder="<?= $h($T['buscar']) ?>" aria-label="<?= $h($T['buscar']) ?>">
    </div>
    <div class="pag-resumo" data-pag-resumo></div>
    <div class="pag-caixa">
        <table class="table table-sm table-hover pag-grid">
            <thead><tr><th><?= $h($T['c_usuario']) ?></th><th><?= $h($T['c_gestor']) ?></th><th><?= $h($T['c_situacao']) ?></th><th><?= $h($T['c_enviado']) ?></th><th><?= $h($T['c_aprovado']) ?></th><th><?= $h($T['c_valido']) ?></th><th></th></tr></thead>
            <tbody data-pag-linhas></tbody>
        </table>
    </div>
    <div class="pag-detalhe" data-pag-detalhe hidden>
        <div class="pag-painel" role="dialog" aria-modal="true">
            <div class="pag-painel-topo"><h2 data-pag-det-titulo></h2><button type="button" class="btn-close" data-pag-fechar aria-label="<?= $h($T['fechar']) ?>"></button></div>
            <div class="pag-painel-corpo" data-pag-det-corpo></div>
            <div class="pag-painel-rodape" data-pag-det-rodape hidden>
                <label class="small" for="pagMotivo"><?= $h($T['motivo']) ?></label>
                <textarea id="pagMotivo" class="form-control" rows="2" maxlength="1000" data-pag-motivo></textarea>
                <div class="pag-mensagem" data-pag-msg></div>
                <div class="pag-botoes">
                    <button type="button" class="btn btn-outline-danger btn-sm" data-pag-decidir="DEVOLVER"><i class="bi bi-arrow-return-left"></i> <?= $h($T['devolver']) ?></button>
                    <button type="button" class="btn btn-success btn-sm" data-pag-decidir="APROVAR"><i class="bi bi-check2-circle"></i> <?= $h($T['aprovar']) ?></button>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script>
<script>window.PAG_TEXTOS = <?= json_encode($T, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="../assets/js/ajuda-transacao.js?v=2"></script>
<script src="../assets/js/perfil-atribuicoes-gestao.js?v=2"></script>
</body>
</html>

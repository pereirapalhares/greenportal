<?php
declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/UsuarioContaService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) {
    http_response_code(401);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], JSON_UNESCAPED_UNICODE);
    } else echo htmlspecialchars(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']), ENT_QUOTES, 'UTF-8');
    exit;
}

$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])); }
$service = new UsuarioContaService($pdo, (int)$_SESSION['usuario']['codusuario']);
// Perfil de atribuições (migração 268): situação do usuário e se ele é gestor de alguém
$paSituacao = null; $paEquipe = false;
try {
    require_once dirname(__DIR__) . '/classes/PerfilAtribuicoesService.php';
    $paSvc = new PerfilAtribuicoesService($pdo, PerfilAtribuicoesService::universal());
    $paSituacao = $paSvc->situacao($_SESSION);
    if (empty($paSituacao['aplica'])) $paSituacao = null;   // perfil de acesso sem "Aplicar questionário"
    $paEquipe = $paSvc->temEquipe((int)$_SESSION['usuario']['codusuario']);
} catch (Throwable) { /* migração 268 ainda não aplicada */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = (string)($_POST['acao'] ?? '');
        if ($action === 'senha') {
            $service->alterarSenha((string)($_POST['senha_atual'] ?? ''), (string)($_POST['nova_senha'] ?? ''));
            echo json_encode(['ok' => true, 'message' => IdiomaService::t('portal.conta.senha_alterada', ['pt' => 'Senha alterada com sucesso.', 'en' => 'Password changed successfully.', 'es' => 'Contraseña cambiada con éxito.'])], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($action === 'layout') {
            $theme = $service->salvarTema((string)($_POST['layout_tema'] ?? 'verde'));
            echo json_encode(['ok' => true, 'tema' => $theme, 'message' => IdiomaService::t('portal.conta.layout_salvo', ['pt' => 'Layout salvo com sucesso.', 'en' => 'Layout saved successfully.', 'es' => 'Diseño guardado con éxito.'])], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($action === 'transacao_padrao') {
            $transaction = $service->salvarTransacaoPadrao((string)($_POST['default_transacao'] ?? 'menu-dashboard'));
            echo json_encode(['ok' => true, 'transacao' => $transaction, 'message' => IdiomaService::t('portal.conta.tela_inicial_salva', ['pt' => 'Tela inicial salva com sucesso.', 'en' => 'Home screen saved successfully.', 'es' => 'Pantalla inicial guardada con éxito.'])], JSON_UNESCAPED_UNICODE); exit;
        }
        http_response_code(422); echo json_encode(['ok' => false, 'message' => IdiomaService::t('portal.conta.selecione_opcao', ['pt' => 'Selecione uma opção para continuar.', 'en' => 'Select an option to continue.', 'es' => 'Seleccione una opción para continuar.'])], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
// ?aba=senha|layout|atribuicoes: aberto pelo menu do usuário no cabeçalho — mostra só aquela seção, sem as abas
$abaUnica = ['senha' => 'account-password', 'layout' => 'account-layout', 'atribuicoes' => 'account-atribuicoes'][(string)($_GET['aba'] ?? '')] ?? '';
$theme = 'verde'; $defaultTransaction = 'menu-dashboard';
try {
    $preferences = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'preferencias_usuario', 'filtros' => json_encode(['codusuario' => (int)$_SESSION['usuario']['codusuario']], JSON_UNESCAPED_UNICODE)]);
    foreach ($preferences as $preference) {
        if ((string)($preference['chave'] ?? '') === 'layout_tema' && in_array((string)$preference['valor'], ['verde', 'azul', 'cinza'], true)) $theme = (string)$preference['valor'];
        if ((string)($preference['chave'] ?? '') === 'default_transacao') $defaultTransaction = (string)$preference['valor'];
    }
} catch (Throwable) {
    // Usa os valores padrao se a preferencia ainda nao existir.
}
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e(IdiomaService::t('portal.conta.titulo', ['pt' => 'Minha conta', 'en' => 'My account', 'es' => 'Mi cuenta'])) ?></title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
    <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
    <link rel="stylesheet" href="../assets/css/app.css?v=4">
    <link rel="stylesheet" href="../assets/css/main.css?v=18">
    <link rel="stylesheet" href="../assets/css/minha-conta.css?v=3">
</head>
<body class="account-iframe-page theme-<?= $e($theme) ?><?= $abaUnica !== '' ? ' account-uma-aba' : '' ?>" data-default-transaction="<?= $e($defaultTransaction) ?>">
<main class="account-iframe-content" data-account-iframe>
    <ul class="nav nav-tabs account-tabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#account-password" aria-selected="true"><?= $e(IdiomaService::t('portal.conta.aba_senha', ['pt' => 'Alterar senha', 'en' => 'Change password', 'es' => 'Cambiar contraseña'])) ?></button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#account-layout" aria-selected="false"><?= $e(IdiomaService::t('portal.conta.aba_layout', ['pt' => 'Meu layout', 'en' => 'My layout', 'es' => 'Mi diseño'])) ?></button></li>
        <?php if ($paSituacao !== null): ?><li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#account-atribuicoes" aria-selected="false"><?= $e(IdiomaService::t('portal.conta.aba_atribuicoes', ['pt' => 'Perfil de atribuições', 'en' => 'Responsibilities profile', 'es' => 'Perfil de atribuciones'])) ?><?php if (!empty($paSituacao['precisa_responder'])): ?> <span class="badge rounded-pill text-bg-warning">!</span><?php endif; ?></button></li><?php endif; ?>
    </ul>
    <div class="tab-content account-tab-content">
        <section class="tab-pane fade show active" id="account-password" role="tabpanel"><h6><?= $e(IdiomaService::t('portal.conta.aba_senha', ['pt' => 'Alterar senha', 'en' => 'Change password', 'es' => 'Cambiar contraseña'])) ?></h6><form id="accountPasswordForm"><input type="text" name="username" autocomplete="username" tabindex="-1" aria-hidden="true" class="visually-hidden"><label class="form-label" for="accountCurrentPassword"><?= $e(IdiomaService::t('portal.conta.senha_atual', ['pt' => 'Senha atual', 'en' => 'Current password', 'es' => 'Contraseña actual'])) ?></label><input id="accountCurrentPassword" type="password" name="senha_atual" class="form-control form-control-sm mb-2" autocomplete="current-password" required><label class="form-label" for="accountNewPassword"><?= $e(IdiomaService::t('portal.conta.nova_senha', ['pt' => 'Nova senha', 'en' => 'New password', 'es' => 'Nueva contraseña'])) ?></label><input id="accountNewPassword" type="password" name="nova_senha" class="form-control form-control-sm mb-2" minlength="4" autocomplete="new-password" required><label class="form-label" for="accountConfirmPassword"><?= $e(IdiomaService::t('portal.conta.confirmar_senha', ['pt' => 'Confirmar nova senha', 'en' => 'Confirm new password', 'es' => 'Confirmar nueva contraseña'])) ?></label><input id="accountConfirmPassword" type="password" name="confirmar_senha" class="form-control form-control-sm" minlength="4" autocomplete="new-password" required><button class="btn btn-sm btn-success mt-3" type="submit"><?= $e(IdiomaService::t('portal.conta.salvar_senha', ['pt' => 'Salvar senha', 'en' => 'Save password', 'es' => 'Guardar contraseña'])) ?></button></form></section>
        <section class="tab-pane fade" id="account-layout" role="tabpanel"><h6><?= $e(IdiomaService::t('portal.conta.aba_layout', ['pt' => 'Meu layout', 'en' => 'My layout', 'es' => 'Mi diseño'])) ?></h6><div class="account-themes"><label><input type="radio" name="account_layout_tema" value="verde" <?= $theme === 'verde' ? 'checked' : '' ?>> <?= $e(IdiomaService::t('portal.conta.tema_verde', ['pt' => 'Verde pastel', 'en' => 'Pastel green', 'es' => 'Verde pastel'])) ?></label><label><input type="radio" name="account_layout_tema" value="azul" <?= $theme === 'azul' ? 'checked' : '' ?>> <?= $e(IdiomaService::t('portal.conta.tema_azul', ['pt' => 'Azul pastel', 'en' => 'Pastel blue', 'es' => 'Azul pastel'])) ?></label><label><input type="radio" name="account_layout_tema" value="cinza" <?= $theme === 'cinza' ? 'checked' : '' ?>> <?= $e(IdiomaService::t('portal.conta.tema_cinza', ['pt' => 'Cinza pastel', 'en' => 'Pastel gray', 'es' => 'Gris pastel'])) ?></label></div><button type="button" class="btn btn-sm btn-outline-success mt-3" data-save-account-layout><?= $e(IdiomaService::t('portal.conta.salvar_layout', ['pt' => 'Salvar layout', 'en' => 'Save layout', 'es' => 'Guardar diseño'])) ?></button></section>
        <?php if ($paSituacao !== null):
            $paTexto = !empty($paSituacao['precisa_responder']) ? IdiomaService::t('portal.conta.atrib_pendente', ['pt' => 'O seu perfil de atribuições está pendente. Responda para que o sistema indique as tarefas certas para você.', 'en' => 'Your responsibilities profile is pending. Answer it so the system can assign you the right tasks.', 'es' => 'Su perfil de atribuciones está pendiente. Respóndalo para que el sistema le indique las tareas correctas.'])
                : (($paSituacao['ultimo_status'] ?? '') === 'PENDENTE_APROVACAO' ? IdiomaService::t('portal.conta.atrib_aguardando', ['pt' => 'O seu perfil foi enviado e aguarda a aprovação do gestor.', 'en' => 'Your profile was submitted and is waiting for manager approval.', 'es' => 'Su perfil fue enviado y espera la aprobación del gestor.'])
                : IdiomaService::t('portal.conta.atrib_ok', ['pt' => 'O seu perfil de atribuições está aprovado. Você pode revisá-lo a qualquer momento.', 'en' => 'Your responsibilities profile is approved. You can review it at any time.', 'es' => 'Su perfil de atribuciones está aprobado. Puede revisarlo en cualquier momento.'])); ?>
        <section class="tab-pane fade" id="account-atribuicoes" role="tabpanel"><h6><?= $e(IdiomaService::t('portal.conta.aba_atribuicoes', ['pt' => 'Perfil de atribuições', 'en' => 'Responsibilities profile', 'es' => 'Perfil de atribuciones'])) ?></h6>
            <p class="small"><?= $e($paTexto) ?></p>
            <button type="button" class="btn btn-sm btn-success" data-pa-abrir="perfil_atribuicoes.php" data-pa-titulo="<?= $e(IdiomaService::t('portal.perfil_atribuicoes', ['pt' => 'Meu perfil de atribuições', 'en' => 'My responsibilities profile', 'es' => 'Mi perfil de atribuciones'])) ?>"><i class="bi bi-person-lines-fill"></i> <?= $e(IdiomaService::t('portal.conta.atrib_abrir', ['pt' => 'Abrir meu perfil de atribuições', 'en' => 'Open my responsibilities profile', 'es' => 'Abrir mi perfil de atribuciones'])) ?></button>
            <?php if ($paEquipe): ?><button type="button" class="btn btn-sm btn-outline-success ms-1" data-pa-abrir="perfil_atribuicoes_gestao.php" data-pa-titulo="<?= $e(IdiomaService::t('portal.conta.atrib_equipe', ['pt' => 'Perfis da minha equipe', 'en' => 'My team profiles', 'es' => 'Perfiles de mi equipo'])) ?>"><i class="bi bi-people"></i> <?= $e(IdiomaService::t('portal.conta.atrib_equipe', ['pt' => 'Perfis da minha equipe', 'en' => 'My team profiles', 'es' => 'Perfiles de mi equipo'])) ?></button><?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
    <div class="account-message mt-3" id="accountMessage" role="status"></div>
</main>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>window.greenPortalDefaultTransaction = <?= json_encode($defaultTransaction) ?>;</script>
<?= IdiomaService::script([], [
    'portal.conta.js.concluida' => ['pt' => 'Operação concluída.', 'en' => 'Done.', 'es' => 'Operación concluida.'],
    'portal.conta.js.erro' => ['pt' => 'Não foi possível concluir a operação.', 'en' => 'The operation could not be completed.', 'es' => 'No fue posible concluir la operación.'],
    'portal.conta.js.senhas_diferentes' => ['pt' => 'As senhas novas não conferem.', 'en' => 'The new passwords do not match.', 'es' => 'Las nuevas contraseñas no coinciden.'],
]) ?>
<script src="../assets/js/minha-conta-iframe.js?v=2"></script>
<?php if ($abaUnica !== ''): ?><script>(function(){var b=document.querySelector('[data-bs-target="#<?= $abaUnica ?>"]');if(b)bootstrap.Tab.getOrCreateInstance(b).show();}());</script><?php endif; ?>
<script>/* Perfil de atribuições: abre em popup largo na página principal */ $(document).on('click', '[data-pa-abrir]', function () { var host = window.top || window, url = new URL(String($(this).attr('data-pa-abrir')), window.location.href).toString(), titulo = String($(this).attr('data-pa-titulo') || ''); if (!host.GreenPortalPopups || !host.jQuery) { window.open(url, '_blank'); return; } var frame = host.jQuery('<iframe>', { title: titulo, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url); host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: frame, amplo: true, redimensionavel: true }); });</script>
</body>
</html>

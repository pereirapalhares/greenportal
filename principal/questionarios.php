<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
if (!PermissaoTransacao::obter('QUESTIONARIOS')['pode_visualizar']) { http_response_code(403); exit(adm_t('Sem permissão para administrar questionários.')); }
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$tabs = [
    ['id' => 'legal', 'module' => 'LEGAL', 'label' => adm_t('Legal'), 'url' => 'legal_questionario.php'],
    ['id' => 'sga', 'module' => 'SGA', 'label' => 'SGA', 'url' => 'sga_questionario.php'],
    ['id' => 'quality', 'module' => 'QUALITY', 'label' => adm_t('Qualidade'), 'url' => 'quality_questionario.php'],
    ['id' => 'workflow', 'module' => 'WORKFLOW', 'label' => adm_t('Workflow'), 'url' => 'workflow_questionario.php'],
    ['id' => 'supplier', 'module' => 'SUPPLIER', 'label' => adm_t('Fornecedores'), 'url' => 'supplier_questionario.php'],
    ['id' => 'esg', 'module' => 'ESG', 'label' => 'ESG', 'url' => 'esg_questionario.php'],
    ['id' => 'projects', 'module' => 'PROJECTS', 'label' => adm_t('Projetos'), 'url' => 'projects_questionario.php'],
    ['id' => 'employee', 'module' => 'EMPLOYEE_INTEGRATION', 'label' => adm_t('Integração'), 'url' => 'employee_integration_questionario.php'],
    ['id' => 'perfil', 'module' => 'USER_PROFILE', 'label' => adm_t('Perfil de atribuições'), 'url' => 'perfil_atribuicoes_questionario.php'],
];
$tabs = array_values(array_filter($tabs, static fn(array $tab): bool => in_array($tab['module'], ['LEGAL', 'SUPPLIER', 'USER_PROFILE'], true)));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= adm_h('Questionários') ?></title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
    <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
    <link rel="stylesheet" href="../assets/css/app.css?v=4">
    <link rel="stylesheet" href="../assets/css/main.css?v=18">
    <style>
    /* A aba interna ocupa só o espaço visível (sem altura mínima fixa): os popups abertos nela (90% da janela que chamou)
       não passam da borda da tela */
    html,body.questionnaire-tabs-page{height:100%;overflow:hidden}
    body.questionnaire-tabs-page{box-sizing:border-box}
    body.questionnaire-tabs-page>main{height:100%;display:flex;flex-direction:column;min-height:0}
    body.questionnaire-tabs-page .questionnaire-iframe-tabs{flex:1 1 auto;min-height:0}
    body.questionnaire-tabs-page .questionnaire-iframe-tabs .tab-pane{height:100%;min-height:0}
    body.questionnaire-tabs-page .questionnaire-iframe-tabs iframe{height:100%;min-height:0}
    </style>
</head>
<body class="p-2 bg-light questionnaire-tabs-page">
<main class="container-fluid" data-questionarios-tabs>
    <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0"><i class="bi bi-ui-checks-grid me-2"></i><?= adm_h('Questionários') ?></h5><span class="d-flex align-items-center gap-2"><span class="small text-muted"><?= adm_h('Catálogo universal e versões por empresa') ?></span><button type="button" class="btn btn-sm btn-outline-info" data-questionarios-ajuda title="<?= adm_a('Como funcionam as versões dos questionários') ?>"><i class="bi bi-question-circle me-1"></i><?= adm_h('Ajuda') ?></button></span></div>
    <ul class="nav nav-tabs questionnaire-tabs mb-2" role="tablist" aria-label="<?= adm_a('Tipos de questionário') ?>">
        <?php foreach ($tabs as $index => $tab): ?>
            <li class="nav-item" role="presentation"><button class="nav-link <?= $index === 0 ? 'active' : '' ?>" type="button" data-bs-toggle="tab" data-bs-target="#questionario-pane-<?= $e($tab['id']) ?>" aria-controls="questionario-pane-<?= $e($tab['id']) ?>" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"><?= $e($tab['label']) ?></button></li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content questionnaire-iframe-tabs">
        <?php foreach ($tabs as $index => $tab): ?>
            <div class="tab-pane fade <?= $index === 0 ? 'show active' : '' ?>" id="questionario-pane-<?= $e($tab['id']) ?>" role="tabpanel"><iframe title="<?= $e($tab['label']) ?>" src="<?= $e($tab['url']) ?>" <?= $index === 0 ? '' : 'loading="lazy"' ?>></iframe></div>
        <?php endforeach; ?>
    </div>
</main>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>/* Ajuda: popup do portal (90% da janela, sempre na frente) */
document.querySelector('[data-questionarios-ajuda]').addEventListener('click', function () {
    var url = new URL('questionarios_ajuda.php', window.location.href).toString(), host = null;
    try { host = window.top && window.top.GreenPortalPopups ? window.top : null; } catch (e) {}
    if (host && typeof host.GreenPortalPopups.transacao90 === 'function') host.GreenPortalPopups.transacao90({ titulo: <?= adm_js('Questionários — como funcionam as versões') ?>, url: url });
    else window.open(url, '_blank');
});</script>
</body>
</html>

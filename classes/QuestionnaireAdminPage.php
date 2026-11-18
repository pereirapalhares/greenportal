<?php
declare(strict_types=1);
require_once __DIR__ . '/AdministracaoTextos.php';

require_once __DIR__ . '/PermissaoTransacao.php';

final class QuestionnaireAdminPage
{
    public static function render(string $module, array $title): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
        if (!PermissaoTransacao::obter('QUESTIONARIOS')['pode_visualizar']) { http_response_code(403); exit(adm_t('Sem permissão para administrar questionários.')); }
        $module = strtoupper(trim($module));
        if (!preg_match('/^[A-Z_]+$/', $module)) { http_response_code(400); exit(adm_t('Tipo de questionário inválido.')); }
        if (empty($_SESSION['questionarios_csrf'])) $_SESSION['questionarios_csrf'] = bin2hex(random_bytes(32));
        $csrf = (string)$_SESSION['questionarios_csrf'];
        $label = (string)($title['pt'] ?? $module);
        $isLegal = $module === 'LEGAL';
        $e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        ?>
        <!doctype html>
        <html lang="pt-BR">
        <head>
            <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?= $e($label) ?></title>
            <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
            <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
            <link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=18">
        </head>
        <body class="p-2 bg-light questionnaire-type-page">
        <main class="container-fluid questionnaire-type" data-questionario-tipo data-modulo="<?= $e($module) ?>" data-csrf="<?= $e($csrf) ?>">
            <section class="menu-grid-fragment card shadow-sm border-0 grid-ready" data-questionnaire-grid>
                <div class="card-body d-flex flex-column">
                    <div class="crud-toolbar menu-grid-toolbar mb-2"><div class="crud-actions crud-actions-standard"><button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-new-question title="<?= adm_a('Incluir pergunta') ?>" aria-label="<?= adm_a('Incluir pergunta') ?>"><i class="bi bi-plus-lg"></i></button><button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-edit-question disabled title="<?= adm_a('Editar pergunta') ?>" aria-label="<?= adm_a('Editar pergunta') ?>"><i class="bi bi-pencil-square"></i></button><button type="button" class="btn btn-sm btn-outline-danger grid-crud-button" data-delete-question disabled title="<?= adm_a('Excluir pergunta') ?>" aria-label="<?= adm_a('Excluir pergunta') ?>"><i class="bi bi-trash"></i></button><button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-publish-version disabled title="<?= adm_a('Publicar versão') ?>" aria-label="<?= adm_a('Publicar versão') ?>"><i class="bi bi-cloud-arrow-up"></i></button><button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-reload-grid title="<?= adm_a('Recarregar') ?>" aria-label="<?= adm_a('Recarregar') ?>"><i class="bi bi-arrow-clockwise"></i></button><button type="button" class="btn btn-sm btn-outline-info grid-crud-button" data-open-transacao-ajuda data-help-transaction="questionario_<?= strtolower($e($module)) ?>" title="<?= adm_a('Ajuda') ?>" aria-label="<?= adm_a('Ajuda') ?>"><i class="bi bi-question-lg"></i></button><span class="crud-pagination-separator" aria-hidden="true">|</span><select class="form-select form-select-sm questionnaire-version-select" id="questionnaireVersion<?= $e($module) ?>" data-questionnaire-select aria-label="<?= adm_a('Selecionar questionário') ?>"><option>Carregando...</option></select><nav class="grid-pagination" aria-label="<?= adm_a('Paginação do grid') ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" data-page-prev disabled title="<?= adm_a('Anterior') ?>" aria-label="<?= adm_a('Anterior') ?>"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status" data-page-status>1 / 1</span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" data-page-next disabled title="<?= adm_a('Próxima') ?>" aria-label="<?= adm_a('Próxima') ?>"><i class="bi bi-chevron-right"></i></button></nav></div><div class="crud-search"><i class="bi bi-search"></i><input type="search" data-grid-search placeholder="<?= adm_a('Buscar pergunta') ?>" aria-label="<?= adm_a('Buscar pergunta') ?>"></div></div>
                    <div class="questionnaire-version-bar" data-version-bar></div>
                    <div class="questionnaire-conflicts d-none" data-conflicts></div>
                    <div class="small text-muted mb-2" data-grid-message role="status"></div>
                    <div class="menu-grid-scroll flex-grow-1"><table class="table table-sm table-hover menu-batch-grid" data-questionnaire-table><thead><tr><?php if ($isLegal): ?><th data-column="codigo_hierarquico"><?= adm_h('Classificação') ?></th><?php endif; ?><th data-column="texto_pt"><?= adm_h('Pergunta') ?></th><th data-column="bloco" data-block-column><?= adm_h('Bloco') ?></th><th data-column="tipo"><?= adm_h('Tipo') ?></th><th data-column="opcoes"><?= adm_h('Opções') ?></th><th data-column="obrigatoria"><?= adm_h('Obrigatória') ?></th><th data-column="ordem"><?= adm_h('Ordem') ?></th></tr></thead><tbody><tr><td colspan="8"><?= adm_h('Carregando...') ?></td></tr></tbody></table></div>
                </div>
            </section>
        </main>
        <div class="modal fade questionnaire-form-modal" id="questionModal<?= $e($module) ?>" tabindex="-1" data-popup-90 aria-labelledby="questionModalTitle<?= $e($module) ?>"><div class="modal-dialog"><div class="modal-content"><form data-question-form novalidate>
            <div class="modal-header"><h5 class="modal-title" id="questionModalTitle<?= $e($module) ?>" data-question-modal-title><?= adm_h('Nova pergunta') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= adm_a('Fechar') ?>"></button></div>
            <div class="questionnaire-form-toolbar"><div class="crud-actions crud-actions-standard"><button type="submit" class="btn btn-sm btn-success grid-crud-button" data-save-question title="<?= adm_a('Salvar') ?>" aria-label="<?= adm_a('Salvar') ?>"><i class="bi bi-check2"></i></button><button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-form-new title="<?= adm_a('Incluir nova pergunta') ?>" aria-label="<?= adm_a('Incluir nova pergunta') ?>"><i class="bi bi-plus-lg"></i></button><button type="button" class="btn btn-sm btn-outline-danger grid-crud-button" data-form-delete title="<?= adm_a('Excluir pergunta') ?>" aria-label="<?= adm_a('Excluir pergunta') ?>"><i class="bi bi-trash"></i></button><button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-bs-dismiss="modal" title="<?= adm_a('Fechar') ?>" aria-label="<?= adm_a('Fechar') ?>"><i class="bi bi-x-lg"></i></button></div><span class="small" data-form-message role="status"></span></div>
            <div class="modal-body"><input type="hidden" name="codpergunta"><input type="hidden" name="codversao"><input type="hidden" name="codigo">
                <div class="row g-3">
                    <?php if ($isLegal): ?><div class="col-sm-3 col-lg-2"><label class="form-label" for="qClass<?= $e($module) ?>"><?= adm_h('Classificação') ?></label><input class="form-control" id="qClass<?= $e($module) ?>" name="codigo_hierarquico" placeholder="1. ou 1.1." pattern="[0-9]+([.][0-9]+)*[.]?"></div><?php endif; ?>
                    <div class="<?= $isLegal ? 'col-sm-9 col-lg-10' : 'col-12' ?>"><label class="form-label" for="qText<?= $e($module) ?>"><?= adm_h('Pergunta *') ?></label><textarea class="form-control" id="qText<?= $e($module) ?>" name="texto_pt" rows="2" required></textarea></div>
                    <div class="col-sm-6 col-lg-5" data-block-field><label class="form-label" for="qBlock<?= $e($module) ?>"><?= adm_h('Bloco') ?></label><select class="form-select" id="qBlock<?= $e($module) ?>" name="codbloco"></select></div>
                    <div class="col-sm-6 col-lg-3"><label class="form-label" for="qType<?= $e($module) ?>"><?= adm_h('Tipo de resposta') ?></label><select class="form-select" id="qType<?= $e($module) ?>" name="tipo"><option value="TEXTO"><?= adm_h('Texto') ?></option><option value="NUMERO"><?= adm_h('Número') ?></option><option value="DATA"><?= adm_h('Data') ?></option><option value="SIM_NAO"><?= adm_h('Sim / Não') ?></option><option value="UNICA_ESCOLHA"><?= adm_h('Escolha única') ?></option><option value="MULTIPLA_ESCOLHA"><?= adm_h('Múltipla escolha') ?></option><option value="ANEXO"><?= adm_h('Anexo') ?></option></select></div>
                    <div class="col-6 col-sm-3 col-lg-2"><label class="form-label" for="qOrder<?= $e($module) ?>"><?= adm_h('Ordem') ?></label><input class="form-control" type="number" id="qOrder<?= $e($module) ?>" name="ordem" value="0"></div>
                    <div class="col-6 col-sm-3 col-lg-2"><label class="form-label" for="qWeight<?= $e($module) ?>"><?= adm_h('Peso') ?></label><input class="form-control" type="number" step="0.001" id="qWeight<?= $e($module) ?>" name="peso" value="1"></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="obrigatoria" value="1" id="questionRequired<?= $e($module) ?>"><label class="form-check-label" for="questionRequired<?= $e($module) ?>"><?= adm_h('Resposta obrigatória') ?></label></div></div>
                    <div class="col-12 d-none" data-options-field><div class="questionnaire-options-head"><span class="form-label mb-0"><?= adm_h('Opções de resposta') ?></span><button type="button" class="btn btn-sm btn-outline-success" data-add-option><i class="bi bi-plus-lg me-1"></i><?= adm_h('Incluir opção') ?></button></div>
                        <table class="table table-sm questionnaire-options"><thead><tr><th><?= adm_h('Rótulo') ?></th><th style="width:140px"><?= adm_h('Pontuação') ?></th><th style="width:44px"></th></tr></thead><tbody data-options-body></tbody></table>
                        <small class="text-muted"><?= adm_h('Os rótulos em inglês e espanhol das opções que já existiam são mantidos.') ?></small></div>
                </div>
            </div>
        </form></div></div></div>
        <div class="modal fade" id="versionsModal<?= $e($module) ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><?= adm_h('Histórico de versões') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= adm_a('Fechar') ?>"></button></div><div class="modal-body"><table class="table table-sm"><thead><tr><th><?= adm_h('Versão') ?></th><th><?= adm_h('Escopo') ?></th><th><?= adm_h('Situação') ?></th><th><?= adm_h('Origem') ?></th><th><?= adm_h('Publicada em') ?></th><th><?= adm_h('Observação') ?></th></tr></thead><tbody data-versions-body></tbody></table></div></div></div></div>
        <style>
        .questionnaire-version-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 8px;padding:8px 10px;border:1px solid var(--gp-border,#dfe7e3);border-radius:8px;background:#fff;font-size:12px}
        .questionnaire-version-bar .badge{font-weight:600}
        .questionnaire-version-bar .qv-acoes{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap}
        .questionnaire-conflicts{margin:0 0 8px;padding:8px 10px;border:1px solid #f0c36d;border-radius:8px;background:#fff8e6;font-size:12px}
        .questionnaire-conflicts table{margin:6px 0 0;font-size:12px;background:transparent}
        /* Tamanho: regra global dos popups (assets/js/popups.js): 90% de largura da janela que chamou e altura do conteúdo,
           no máximo 90%, com rolagem vertical no corpo. Abaixo, só o mesmo comportamento quando a tela abre fora do portal. */
        .questionnaire-form-modal:not(.popup-90)>.modal-dialog{width:90vw;max-width:90vw;margin:5vh auto}
        .questionnaire-form-modal:not(.popup-90) .modal-content{max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
        .questionnaire-form-modal form{display:flex;flex-direction:column;flex:1 1 auto;min-height:0}
        .questionnaire-form-modal .modal-header{padding:10px 16px}
        .questionnaire-form-modal .modal-title{font-size:15px;font-weight:700}
        .questionnaire-form-toolbar{display:flex;align-items:center;gap:12px;padding:8px 16px;border-bottom:1px solid var(--gp-border,#dfe7e3);background:var(--gp-surface,#f7faf8)}
        .questionnaire-form-modal .modal-body{flex:1 1 auto;min-height:0;overflow-y:auto;padding:14px 16px}
        .questionnaire-form-modal .form-label{font-size:12px;font-weight:600;margin-bottom:3px;color:#49665b}
        .questionnaire-form-modal .form-control,.questionnaire-form-modal .form-select{font-size:13px;padding:6px 10px;min-height:34px;background-position:right 8px center}
        .questionnaire-form-modal textarea.form-control{min-height:56px;resize:vertical}
        .questionnaire-form-modal .form-check-label{font-size:13px}
        .questionnaire-options-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
        .questionnaire-options{font-size:13px}
        .questionnaire-options .form-control{font-size:13px;padding:4px 8px;min-height:30px;height:30px}
        </style>
        <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/ajuda-transacao.js?v=3"></script><script src="../assets/js/questionario-tipo.js?v=15"></script>
        <?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?></body></html>
        <?php
    }
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';
require_once dirname(__DIR__) . '/components/botoes_crud.php';

if (empty($_SESSION['autenticado'])) { http_response_code(401); exit(adm_t('Sessao expirada.')); }
if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR') { http_response_code(403); exit(adm_t('Acesso restrito ao perfil ADMINISTRADOR.')); }

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$user = (int)($_SESSION['usuario']['codusuario'] ?? 0);
if (empty($_SESSION['empresa_migracao_csrf'])) $_SESSION['empresa_migracao_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['empresa_migracao_csrf'];
$message = ''; $error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException(adm_t('Token de seguranca invalido.'));
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'criar_solicitacao' || $action === 'atualizar_solicitacao') {
            $origins = [];
            foreach (preg_split('/[\s,;]+/', (string)($_POST['bancos_origem'] ?? '')) ?: [] as $source) {
                $source = trim($source); if ($source !== '') $origins[] = $source;
            }
            $originJson = json_encode(array_values(array_unique($origins)));
            if ($action === 'atualizar_solicitacao') {
                AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_solicitacao_atualizar', [
                    (int)$_POST['codsolicitacao'], $user, $originJson, trim((string)$_POST['banco_destino']),
                    trim((string)$_POST['path_ged']), trim((string)($_POST['observacoes'] ?? '')),
                ]);
                $message = adm_t('Parametros da solicitacao atualizados.');
            } else {
                $result = AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_solicitacao_criar', [
                    (int)$_POST['codempresa'], $user, $originJson, trim((string)$_POST['banco_destino']),
                    trim((string)$_POST['path_ged']), trim((string)($_POST['observacoes'] ?? '')),
                ]);
                $message = 'Solicitacao #' . (int)($result[0]['codsolicitacao'] ?? 0) . adm_t(' criada em construcao.');
            }
        } elseif ($action === 'replicar_prompts') {
            $targetDb = trim((string)($_POST['banco_destino'] ?? ''));
            $requestId = (int)($_POST['codsolicitacao'] ?? 0);
            if (!preg_match('/^g[a-z0-9_]+_dbp$/i', $targetDb)) throw new RuntimeException(adm_t('Banco de destino invalido.'));
            AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_prompts_solicitar', [$requestId, $user, $targetDb]);
            if ($requestId > 0) AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_log_registrar', [$requestId, 'INFO', 'REPLICAR_PROMPTS_IA', 'Replicacao manual de prompts colocada na fila do worker.', json_encode(['banco_destino'=>$targetDb], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $message = adm_t('Replicacao de prompts solicitada para ').$targetDb.adm_t('. O worker executara a operacao automaticamente.');
        } elseif ($action === 'excluir_solicitacao') {
            AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_solicitacao_excluir', [(int)$_POST['codsolicitacao'], $user]);
            $message = adm_t('Solicitacao excluida.');
        } elseif ($action === 'iniciar_solicitacao') {
            AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_solicitacao_iniciar', [(int)$_POST['codsolicitacao'], $user]);
            $message = adm_t('Migracao enviada para execucao pelo Task Scheduler.');
        }
    }
} catch (Throwable $exception) { $error = $exception->getMessage(); }

$queryText = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
$page = 1; $limit = 500; // todas as solicitações: busca, filtros por coluna e ordenação no próprio grid, como nos demais
$total = (int)(AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_solicitacoes_count', [$statusFilter, $queryText])[0]['total'] ?? 0);
$pages = max(1, (int)ceil($total / $limit)); $page = min($page, $pages);
$requests = AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_solicitacoes_listar', [$statusFilter, $queryText, $limit, ($page - 1) * $limit]);
$companies = $pdo->query("SELECT codempresa,codigo,nome,banco_legado,banco_novo,ged,status_migracao FROM vw_empresa_migracao_grid WHERE banco_novo IS NOT NULL ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= adm_h('Migração geral') ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet">
<?php greenPortalEstiloPagina(); ?>
<style>
.migration-grid-fragment{display:flex;flex-direction:column;height:100vh;padding:10px 12px;gap:8px}
.migration-grid-fragment .menu-grid-scroll{flex:1 1 auto}
.migration-grid td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.migration-grid .grid-secondary{color:#6b8279;font-size:10px}
.migration-grid tbody tr{cursor:pointer}
.migration-step{border:1px solid #dfe9e4;border-radius:8px;padding:10px 12px;margin-bottom:10px;background:#fbfdfc}
.migration-step-title{display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;color:#24493c;margin-bottom:8px}
.migration-step-title small{font-weight:400;color:#6b8279}
.migration-step-num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#2f7d5b;color:#fff;font-size:11px}
.migration-extra-list .input-group{margin-top:6px}
.migration-db{font-family:Consolas,monospace}
.migration-form .form-control{height:auto;min-height:calc(1.5em + .5rem + 2px);padding:.25rem .5rem;font-size:.8125rem;border-radius:var(--bs-border-radius-sm)}
.migration-form textarea.form-control{min-height:56px}
.migration-origins .badge{font-weight:500;margin:1px 2px 1px 0}
.migration-erro{color:#b42318}
</style>
</head>
<body>
<section class="setup-fragment menu-grid-fragment migration-grid-fragment">
    <div class="crud-toolbar menu-grid-toolbar">
        <?php greenPortalCrudButtons([
            'save' => false, 'duplicate' => false, 'export' => false, 'columns' => false,
            'new_attribute' => 'data-new-migration', 'delete_attribute' => 'data-delete-migration',
            'extras' => ('<button class="btn btn-sm btn-outline-primary grid-crud-button" type="button" data-edit-migration disabled title="' . adm_a('Editar parâmetros da solicitação selecionada') . '" aria-label="' . adm_a('Editar parâmetros') . '"><i class="bi bi-pencil-square"></i></button>')
                . ('<button class="btn btn-sm btn-outline-success grid-crud-button" type="button" data-start-migration disabled title="' . adm_a('Iniciar migração (somente em construção)') . '" aria-label="' . adm_a('Iniciar migração') . '"><i class="bi bi-play-fill"></i></button>')
                . ('<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-migration-log disabled title="' . adm_a('Ver log da migração') . '" aria-label="' . adm_a('Ver log da migração') . '"><i class="bi bi-journal-text"></i></button>')
                . ('<button class="btn btn-sm btn-outline-info grid-crud-button" type="button" data-replicar-prompts disabled title="' . adm_a('Replicar prompts de IA') . '" aria-label="' . adm_a('Replicar prompts de IA') . '"><i class="bi bi-stars"></i></button>')
                . ('<a class="btn btn-sm btn-outline-warning grid-crud-button" href="administracao_migracao_menus.php" title="' . adm_a('Validar menus não mapeados') . '" aria-label="' . adm_a('Validar menus não mapeados') . '"><i class="bi bi-diagram-3"></i></a>')
                . ('<a class="btn btn-sm btn-outline-danger grid-crud-button" href="administracao_migracao_orfaos.php" title="' . adm_a('Integridade dos vínculos migrados') . '" aria-label="' . adm_a('Integridade dos vínculos migrados') . '"><i class="bi bi-shield-check"></i></a>'),
        ]); ?>
        <div class="crud-search"><i class="bi bi-search"></i><input type="search" id="migrationSearch" placeholder="<?= adm_a('Buscar no grid') ?>" aria-label="<?= adm_a('Buscar no grid') ?>"></div>
    </div>
    <?php if ($message): ?><div class="alert alert-success py-2 mb-0" role="status"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2 mb-0" role="alert"><?= $escape($error) ?></div><?php endif; ?>
    <form class="setup-form menu-grid-form" onsubmit="return false">
    <div class="menu-grid-scroll">
        <table class="table table-sm menu-batch-grid migration-grid" id="migrationBatchGrid">
            <thead><tr>
                <?php foreach (['codsolicitacao' => [adm_t('Solicitação'), 90], 'empresa' => ['Empresa', 200], 'origens' => ['Origem(ns)', 260], 'destino' => ['Destino', 150], 'ged' => [adm_t('Caminho do GED'), 260], 'prompts' => [adm_t('Prompts IA'), 100], 'status' => ['Status', 170], 'etapas' => ['Etapas', 70], 'mensagem' => ['Mensagem', 260]] as $key => [$label, $width]): ?>
                <th data-column="<?= $key ?>" style="width:<?= $width ?>px"><div class="column-heading"><span><?= $label ?></span>
                    <?php if ($key === 'status'): ?><select class="column-filter form-select form-select-sm" data-column-filter="status" aria-label="<?= adm_a('Filtrar Status') ?>"><option value=""><?= adm_h('Todos') ?></option><?php foreach (['EM_CONSTRUCAO' => adm_t('Em construção'), 'EM_EXECUCAO' => adm_t('Em execução'), 'ERRO' => adm_t('Erro'), 'FINALIZADO_COM_SUCESSO' => adm_t('Finalizado com sucesso')] as $v => $l): ?><option value="<?= strtolower($v) ?>"><?= $l ?></option><?php endforeach; ?></select>
                    <?php else: ?><input class="column-filter" type="search" data-column-filter="<?= $key ?>" placeholder="<?= adm_a('Buscar') ?>" aria-label="Buscar <?= $label ?>"><?php endif; ?>
                </div></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php $rotuloStatus = ['EM_CONSTRUCAO' => [adm_t('Em construção'), 'secondary'], 'EM_EXECUCAO' => [adm_t('Em execução'), 'primary'], 'ERRO' => ['Erro', 'danger'], 'FINALIZADO_COM_SUCESSO' => [adm_t('Finalizado com sucesso'), 'success']];
            foreach ($requests as $request): $requestSources = (array)json_decode((string)$request['bancos_origem'], true); [$statusTexto, $statusCor] = $rotuloStatus[$request['status']] ?? [$request['status'], 'secondary']; ?>
                <tr data-row data-migration-row data-key="<?= $escape($request['codsolicitacao']) ?>" data-id="<?= $escape($request['codsolicitacao']) ?>" data-status="<?= $escape($request['status']) ?>" data-empresa="<?= $escape($request['codempresa']) ?>" data-origens="<?= $escape(json_encode($requestSources, JSON_UNESCAPED_UNICODE)) ?>" data-destino="<?= $escape($request['banco_destino']) ?>" data-ged="<?= $escape($request['path_ged']) ?>" data-observacoes="<?= $escape($request['observacoes'] ?? '') ?>">
                    <td data-column="codsolicitacao">#<?= $escape($request['codsolicitacao']) ?></td>
                    <td data-column="empresa" title="<?= $escape($request['empresa_nome']) ?>"><?= $escape($request['empresa_nome']) ?> <span class="grid-secondary"><?= $escape($request['codigo']) ?></span></td>
                    <td data-column="origens" class="migration-origins" title="<?= $escape(implode(', ', $requestSources)) ?>"><?php foreach ($requestSources as $source): ?><span class="badge text-bg-light"><?= $escape($source) ?></span><?php endforeach; ?></td>
                    <td data-column="destino"><?= $escape($request['banco_destino']) ?></td>
                    <td data-column="ged" title="<?= $escape($request['path_ged']) ?>"><?= $escape($request['path_ged']) ?></td>
                    <td data-column="prompts" class="text-end"><?= $escape($request['prompts_replicados'] ?? '-') ?></td>
                    <td data-column="status"><input type="hidden" value="<?= $escape(strtolower((string)$request['status'])) ?>"><span class="badge text-bg-<?= $statusCor ?>"><?= $escape($statusTexto) ?></span></td>
                    <td data-column="etapas" class="text-end"><?= $escape($request['etapas_concluidas']) ?>/<?= $escape($request['etapas_total']) ?></td>
                    <td data-column="mensagem" class="migration-erro" title="<?= $escape($request['erro'] ?? '') ?>"><?= $escape($request['erro'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </form>
    <nav class="grid-pagination" aria-label="<?= adm_a('Paginação do grid') ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" disabled><i class="bi bi-chevron-left"></i><span><?= adm_h('Anterior') ?></span></button><span class="grid-page-status"><?= $total ?> registro(s)</span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" disabled><span><?= adm_h('Próxima') ?></span><i class="bi bi-chevron-right"></i></button></nav>
</section>

<form method="post" id="deleteMigrationForm" hidden><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="excluir_solicitacao"><input type="hidden" name="codsolicitacao" id="deleteMigrationId"></form>
<form method="post" id="startMigrationForm" hidden><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="iniciar_solicitacao"><input type="hidden" name="codsolicitacao" id="startMigrationId"></form>
<form method="post" id="replicatePromptsForm" hidden><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="replicar_prompts"><input type="hidden" name="banco_destino" id="replicatePromptsTarget"><input type="hidden" name="codsolicitacao" id="replicatePromptsRequest"></form>

<div class="modal fade" id="migrationParametersModal" tabindex="-1" aria-labelledby="migrationModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<form method="post" class="migration-form">
    <div class="modal-header"><h5 class="modal-title" id="migrationModalTitle"><?= adm_h('Parâmetros da migração') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= adm_a('Fechar') ?>"></button></div>
    <div class="modal-body"><div class="alert alert-warning py-2 small d-none" id="migrationReadOnlyNote" role="note"><i class="bi bi-lock me-1"></i><?= adm_h('Esta solicitação não está em construção: os parâmetros só podem ser consultados.') ?></div>
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" id="migrationAction" value="criar_solicitacao"><input type="hidden" name="codsolicitacao" id="migrationId">
        <div class="migration-step">
            <div class="migration-step-title"><span class="migration-step-num">1</span><?= adm_h('Empresa') ?></div>
            <select class="form-select form-select-sm" id="migrationCompany" name="codempresa" required><option value=""><?= adm_h('Selecione a empresa') ?></option><?php foreach ($companies as $company): ?><option value="<?= $escape($company['codempresa']) ?>" data-destino="<?= $escape($company['banco_novo']) ?>" data-ged="<?= $escape($company['ged']) ?>" data-legado="<?= $escape($company['banco_legado'] ?? '') ?>"><?= $escape($company['nome']) ?> (<?= $escape($company['codigo']) ?>)</option><?php endforeach; ?></select>
            <div class="form-text"><?= adm_h('Ao escolher a empresa, os bancos e o caminho do GED são sugeridos pelo cadastro. Confira antes de salvar.') ?></div>
        </div>
        <div class="migration-step">
            <div class="migration-step-title"><span class="migration-step-num">2</span><?= adm_h('De onde vêm os dados') ?> <small>sistema antigo</small></div>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small" for="migrationSourceMain"><?= adm_h('Banco principal') ?> <span class="text-danger">*</span></label>
                    <input class="form-control form-control-sm migration-db" id="migrationSourceMain" list="migrationKnownDbs" required placeholder="green_empresa" autocomplete="off" spellcheck="false">
                    <div class="form-text"><?= adm_h('Dados da empresa (cadastros, documentos, licenças).') ?></div></div>
                <div class="col-md-6"><label class="form-label small" for="migrationSourceSecurity"><?= adm_h('Banco de segurança') ?> <span class="text-muted"><?= adm_h('(opcional)') ?></span></label>
                    <input class="form-control form-control-sm migration-db" id="migrationSourceSecurity" list="migrationKnownDbs" placeholder="green_empresa_seguranca" autocomplete="off" spellcheck="false">
                    <div class="form-text"><?= adm_h('Usuários e perfis do sistema antigo, quando ficam em banco separado.') ?></div></div>
            </div>
            <div id="migrationExtraSources" class="migration-extra-list"></div>
            <button type="button" class="btn btn-link btn-sm px-0" id="migrationAddSource"><i class="bi bi-plus-circle me-1"></i><?= adm_h('Adicionar outro banco de origem') ?></button>
            <textarea name="bancos_origem" id="migrationSources" hidden></textarea>
        </div>
        <div class="migration-step">
            <div class="migration-step-title"><span class="migration-step-num">3</span><?= adm_h('Para onde vão') ?> <small>novo sistema</small></div>
            <div class="row g-2">
                <div class="col-md-5"><label class="form-label small" for="migrationTarget"><?= adm_h('Banco de destino') ?> <span class="text-danger">*</span></label><input class="form-control form-control-sm migration-db" id="migrationTarget" name="banco_destino" required placeholder="gempresa_dbp" autocomplete="off" spellcheck="false"></div>
                <div class="col-md-7"><label class="form-label small" for="migrationGed"><?= adm_h('Pasta dos arquivos (GED)') ?> <span class="text-danger">*</span></label><input class="form-control form-control-sm" id="migrationGed" name="path_ged" required placeholder="E:\projetoGreen\GED\green_empresa" spellcheck="false"></div>
            </div>
        </div>
        <div class="migration-step">
            <label class="migration-step-title" for="migrationNotes"><span class="migration-step-num">4</span><?= adm_h('Observações') ?> <small>opcional</small></label>
            <textarea class="form-control form-control-sm" id="migrationNotes" name="observacoes" rows="2"></textarea>
        </div>
        <div class="alert alert-danger py-2 small d-none mt-2 mb-0" id="migrationFormError" role="alert"></div>
        <datalist id="migrationKnownDbs"><?php $conhecidos = []; foreach ($companies as $company) { $l = trim((string)($company['banco_legado'] ?? '')); if ($l !== '') { $conhecidos[$l] = 1; $conhecidos[$l . '_seguranca'] = 1; } } foreach (array_keys($conhecidos) as $db): ?><option value="<?= $escape($db) ?>"><?php endforeach; ?></datalist>
    </div>
    <div class="modal-footer"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-dismiss="modal"><?= adm_h('Cancelar') ?></button><button class="btn btn-sm btn-success" type="submit" id="migrationSaveButton"><i class="bi bi-check2 me-1"></i><?= adm_h('Salvar parâmetros') ?></button></div>
</form>
</div></div></div>

<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script src="../assets/js/grid-sort.js?v=1"></script><script src="../assets/js/grid-loading.js?v=3"></script><script src="../assets/js/grid-column-filters.js?v=5"></script>
<script>
(function () {
    'use strict';
    var $ = function (s) { return document.querySelector(s); };
    var modalEl = $('#migrationParametersModal'), modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var editBtn = document.querySelector('[data-edit-migration]'), deleteBtn = document.querySelector('[data-delete-migration]'),
        startBtn = document.querySelector('[data-start-migration]'), logBtn = document.querySelector('[data-migration-log]'), promptsBtn = document.querySelector('[data-replicar-prompts]'),
        newBtn = document.querySelector('[data-new-migration]'), reloadBtn = document.querySelector('[data-reload-grid]');
    var f = { action: $('#migrationAction'), id: $('#migrationId'), company: $('#migrationCompany'), sources: $('#migrationSources'),
        target: $('#migrationTarget'), ged: $('#migrationGed'), notes: $('#migrationNotes'), title: $('#migrationModalTitle'), save: $('#migrationSaveButton'), note: $('#migrationReadOnlyNote'),
        main: $('#migrationSourceMain'), security: $('#migrationSourceSecurity'), extras: $('#migrationExtraSources'), addSource: $('#migrationAddSource'), error: $('#migrationFormError') };
    var somenteLeitura = false;
    function addExtra(valor) {
        var g = document.createElement('div'); g.className = 'input-group input-group-sm';
        g.innerHTML = <?= adm_js('<span class="input-group-text">Outro banco</span><input class="form-control migration-db" list="migrationKnownDbs" autocomplete="off" spellcheck="false" placeholder="nome_do_banco"><button type="button" class="btn btn-outline-danger" title="Remover este banco" aria-label="Remover"><i class="bi bi-x-lg"></i></button>') ?>;
        var input = g.querySelector('input'); input.value = valor || ''; input.disabled = somenteLeitura;
        g.querySelector('button').disabled = somenteLeitura;
        g.querySelector('button').addEventListener('click', function () { g.remove(); });
        f.extras.appendChild(g); return input;
    }
    function preencherOrigens(lista) {
        f.extras.innerHTML = ''; f.main.value = ''; f.security.value = '';
        (lista || []).forEach(function (db) {
            if (!f.security.value && /_seguranca$/i.test(db)) f.security.value = db;
            else if (!f.main.value) f.main.value = db;
            else addExtra(db);
        });
    }
    function origens() {
        var l = [f.main.value, f.security.value].concat([].map.call(f.extras.querySelectorAll('input'), function (i) { return i.value; }));
        return l.map(function (v) { return v.trim(); }).filter(Boolean);
    }
    function erro(msg, campo) { f.error.textContent = msg; f.error.classList.remove('d-none'); if (campo) campo.focus(); return false; }
    f.addSource.addEventListener('click', function () { addExtra('').focus(); });
    var selected = null;
    function atualizarBotoes() {
        var emConstrucao = !!selected && selected.dataset.status === 'EM_CONSTRUCAO';
        if (editBtn) { editBtn.disabled = !selected; editBtn.title = !selected ? 'Selecione uma solicitação para editar os parâmetros' : (emConstrucao ? <?= adm_js('Editar parâmetros da solicitação #') ?> + selected.dataset.id : <?= adm_js('Consultar parâmetros da solicitação #') ?> + selected.dataset.id + <?= adm_js(' (só é possível alterar em construção)') ?>); }
        if (deleteBtn) deleteBtn.disabled = !emConstrucao;
        if (startBtn) startBtn.disabled = !emConstrucao;
        if (logBtn) logBtn.disabled = !selected;
        if (promptsBtn) promptsBtn.disabled = !selected;
    }
    function abrir(row) {
        var novo = !row; somenteLeitura = !novo && row.dataset.status !== 'EM_CONSTRUCAO';
        f.title.textContent = novo ? 'Nova solicitação de migração' : <?= adm_js('Parâmetros da solicitação #') ?> + row.dataset.id;
        [f.company, f.main, f.security, f.target, f.ged, f.notes].forEach(function (el) { el.disabled = somenteLeitura; });
        f.addSource.classList.toggle('d-none', somenteLeitura); f.error.classList.add('d-none');
        f.note.classList.toggle('d-none', !somenteLeitura);
        f.save.classList.toggle('d-none', somenteLeitura);
        f.action.value = novo ? 'criar_solicitacao' : 'atualizar_solicitacao';
        f.id.value = novo ? '' : row.dataset.id;
        f.company.value = novo ? '' : row.dataset.empresa;
        preencherOrigens(novo ? [] : JSON.parse(row.dataset.origens || '[]'));
        f.target.value = novo ? '' : row.dataset.destino;
        f.ged.value = novo ? '' : row.dataset.ged;
        f.notes.value = novo ? '' : (row.dataset.observacoes || '');
        f.save.innerHTML = novo ? '<i class="bi bi-plus-lg me-1"></i>Criar em construção' : <?= adm_js('<i class="bi bi-check2 me-1"></i>Salvar parâmetros') ?>;
        modal.show();
    }
    modalEl.addEventListener('shown.bs.modal', function () { if (!f.company.disabled) f.company.focus(); });
    document.querySelectorAll('[data-migration-row]').forEach(function (row) {
        row.addEventListener('click', function () {
            document.querySelectorAll('[data-migration-row].selected').forEach(function (r) { r.classList.remove('selected'); });
            row.classList.add('selected'); selected = row; atualizarBotoes();
        });
        row.addEventListener('dblclick', function () { abrir(row); });
    });
    if (newBtn) newBtn.addEventListener('click', function () { abrir(null); });
    if (editBtn) editBtn.addEventListener('click', function () { if (selected) abrir(selected); });
    if (deleteBtn) deleteBtn.addEventListener('click', function () {
        if (!selected || selected.dataset.status !== 'EM_CONSTRUCAO') return;
        if (window.confirm(<?= adm_js('Excluir a solicitação #') ?> + selected.dataset.id + <?= adm_js(' (em construção)?') ?>)) { $('#deleteMigrationId').value = selected.dataset.id; $('#deleteMigrationForm').submit(); }
    });
    if (startBtn) startBtn.addEventListener('click', function () {
        if (!selected || selected.dataset.status !== 'EM_CONSTRUCAO') return;
        if (window.confirm(<?= adm_js('Iniciar a migração da solicitação #') ?> + selected.dataset.id + <?= adm_js('? Ela será executada em etapas pelo Task Scheduler.') ?>)) { $('#startMigrationId').value = selected.dataset.id; $('#startMigrationForm').submit(); }
    });
    if (logBtn) logBtn.addEventListener('click', function () {
        if (!selected) return;
        var url = new URL('administracao_migracao_log.php?id=' + encodeURIComponent(selected.dataset.id), window.location.href).toString(), h = null;
        try { h = window.top && window.top.GreenPortalPopups && window.top.jQuery ? window.top : null; } catch (e) { h = null; }
        // Log em popup do portal (90% da tela que chamou); fora do portal, abre na própria janela
        if (h) h.GreenPortalPopups.transacao90({ titulo: <?= adm_js('Log da migração #') ?> + selected.dataset.id, url: url }); else window.location.href = url;
    });
    if (promptsBtn) promptsBtn.addEventListener('click', function () {
        if (!selected) return;
        if (window.confirm(<?= adm_js('Replicar os prompts de IA para o banco ') ?> + selected.dataset.destino + '?')) {
            $('#replicatePromptsTarget').value = selected.dataset.destino;
            $('#replicatePromptsRequest').value = selected.dataset.id;
            $('#replicatePromptsForm').submit();
        }
    });
    if (reloadBtn) reloadBtn.addEventListener('click', function () { window.location.href = window.location.pathname; });
    f.company.addEventListener('change', function () {
        var o = this.selectedOptions[0];
        if (!f.target.value || f.action.value === 'criar_solicitacao') f.target.value = (o && o.dataset.destino) || '';
        if (!f.ged.value || f.action.value === 'criar_solicitacao') f.ged.value = (o && o.dataset.ged) || '';
        var legado = (o && o.dataset.legado) || '';
        if (legado && (f.action.value === 'criar_solicitacao' || !f.main.value)) { f.main.value = legado; if (!f.security.value || f.action.value === 'criar_solicitacao') f.security.value = legado + '_seguranca'; }
    });
    document.querySelector('.migration-form').addEventListener('submit', function (ev) {
        f.error.classList.add('d-none');
        var lista = origens(), nome = /^[A-Za-z0-9_]+$/, vistos = {};
        for (var k = 0; k < lista.length; k++) {
            if (!nome.test(lista[k])) { ev.preventDefault(); return erro(<?= adm_js('Nome de banco inválido: "') ?> + lista[k] + <?= adm_js('". Use só letras, números e _ (sem espaços).') ?>); }
            if (vistos[lista[k].toLowerCase()]) { ev.preventDefault(); return erro(<?= adm_js('O banco "') ?> + lista[k] + <?= adm_js('" foi informado mais de uma vez.') ?>); }
            vistos[lista[k].toLowerCase()] = 1;
        }
        if (!lista.length) { ev.preventDefault(); return erro(<?= adm_js('Informe o banco principal de origem.') ?>, f.main); }
        if (!nome.test(f.target.value.trim())) { ev.preventDefault(); return erro(<?= adm_js('Nome do banco de destino inválido.') ?>, f.target); }
        if (vistos[f.target.value.trim().toLowerCase()]) { ev.preventDefault(); return erro(<?= adm_js('O banco de destino não pode ser também um banco de origem.') ?>, f.target); }
        f.sources.value = lista.join('\n');
    });
    atualizarBotoes();
}());
</script>
</body></html>

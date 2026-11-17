<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }

$env = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$dsn = static fn(string $database): string => 'mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $database . ';charset=utf8mb4';
$database = (string)($_SESSION['tenant']['banco_dados'] ?? ($env['GPT_DB_DATABASE'] ?? 'gmodelo_dbp'));
$pdo = new PDO($dsn($database), $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }

$permission = PermissaoTransacao::obter('MODULOLEGAL_POTENCIAIS');
if (!$permission['pode_visualizar']) { http_response_code(403); exit('Acesso nao autorizado.'); }
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$id = (int)($_GET['id'] ?? $_GET['codsolicitacao'] ?? $_POST['id'] ?? $_POST['codsolicitacao'] ?? 0);
$json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$call = static function (PDO $connection, string $sql, array $params = []): array {
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    return $rows;
};
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$date = static function (mixed $value) use ($idioma): string {
    if (!$value) return '—';
    try { return (new DateTime((string)$value))->format($idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i'); }
    catch (Throwable) { return (string)$value; }
};
$statusLabel = static function (mixed $value) use ($idioma): string {
    $map = [
        'PENDENTE' => ['pt' => 'Pendente', 'en' => 'Pending', 'es' => 'Pendiente'],
        'PROCESSANDO' => ['pt' => 'Processando', 'en' => 'Processing', 'es' => 'Procesando'],
        'CONCLUIDA' => ['pt' => 'Concluída', 'en' => 'Completed', 'es' => 'Concluida'],
        'ERRO' => ['pt' => 'Erro', 'en' => 'Error', 'es' => 'Error'],
        'CANCELADA' => ['pt' => 'Cancelada', 'en' => 'Cancelled', 'es' => 'Cancelada'],
        'SUGERIDO' => ['pt' => 'Sugerido', 'en' => 'Suggested', 'es' => 'Sugerido'],
        'APROVADO' => ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado'],
        'REJEITADO' => ['pt' => 'Rejeitado', 'en' => 'Rejected', 'es' => 'Rechazado'],
    ];
    $key = strtoupper((string)$value);
    return $map[$key][$idioma] ?? ($key ?: '—');
};
$statusClass = static function (mixed $value): string {
    return match (strtoupper((string)$value)) {
        'CONCLUIDA', 'APROVADO' => 'text-bg-success',
        'ERRO', 'REJEITADO' => 'text-bg-danger',
        'PROCESSANDO' => 'text-bg-primary',
        'PENDENTE' => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
};

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validar') {
    try {
        if (!$permission['pode_editar']) throw new RuntimeException('Seu perfil não possui permissão para validar potenciais.');
        $potentialId = (int)($_POST['codpotencial'] ?? 0);
        $newStatus = strtoupper((string)($_POST['status'] ?? ''));
        if (!$potentialId || !in_array($newStatus, ['APROVADO', 'REJEITADO'], true)) throw new RuntimeException('Dados de validação inválidos.');
        $call($pdo, 'CALL sp_ia_potencial_validar(:potencial,:usuario,:status)', ['potencial' => $potentialId, 'usuario' => $uid, 'status' => $newStatus]);
        $message = 'Potencial atualizado com sucesso.';
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$requestRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
    'tabela' => 'vw_ml_potenciais_solicitacoes_grid',
    'filtros' => $json(['codsolicitacao' => ['value' => (string)$id, 'exact' => true]]),
]);
$req = $requestRows[0] ?? null;
// Quem pode abrir: quem pediu a análise e, como no calendário de pendências, quem tem o solicitante na equipe
// (ADM DA EMPRESA = todos os usuários; os demais = subordinados em todos os níveis) — PainelLateralService::equipe()
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';
$requestOwner = (int)($req['codusuario'] ?? 0);
if ($req && !PainelLateralService::podeVer($pdo, $uid, $requestOwner)) $req = null;
if (!$req) { http_response_code(404); exit('Solicitação não encontrada.'); }
$requestDetailRows = $call($pdo, 'SELECT codsolicitacao,codusuario,contexto_json,status,solicitada_em,iniciada_em,concluida_em,processador,erro FROM vw_ml_potenciais_solicitacoes WHERE codsolicitacao = :id AND codusuario = :usuario LIMIT 1', ['id' => $id, 'usuario' => $requestOwner]);
$requestDetail = $requestDetailRows[0] ?? $req;
$context = json_decode((string)($requestDetail['contexto_json'] ?? ''), true);
$context = is_array($context) ? $context : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_new_analysis') {
    try {
        $rowsRequest = $call($pdo, 'CALL sp_ia_potencial_solicitar_com_alerta(:usuario,:idioma,:contexto,:linkstatus)', ['usuario' => $uid, 'idioma' => $idioma, 'contexto' => $json($context), 'linkstatus' => '/principal/ia_potenciais_resultado.php?codsolicitacao={CODSOLICITACAO}']);
        $requestId = (int)($rowsRequest[0]['codsolicitacao'] ?? 0);
        if ($requestId <= 0) throw new RuntimeException('Não foi possível registrar a nova solicitação.');
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'codsolicitacao' => $requestId, 'message' => 'Nova análise solicitada.'], JSON_UNESCAPED_UNICODE); exit;
    } catch (Throwable $exception) { http_response_code(409); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
}
$companyRows = $call($pdo, 'SELECT dscempresa FROM vw_mm_empresa_nome WHERE codempresa = :id LIMIT 1', ['id' => (int)($context['CODEMPRESA'] ?? 0)]);
$companyName = (string)($companyRows[0]['dscempresa'] ?? '');
$rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', [
    'tabela' => 'vw_ml_potenciais_grid',
    'filtros' => $json(['codsolicitacao' => $id]),
]);
$contextValue = static function (array $source, array $keys, string $fallback = '—'): string {
    foreach ($keys as $key) if (array_key_exists($key, $source) && trim((string)$source[$key]) !== '') return (string)$source[$key];
    return $fallback;
};
$requestUser = $contextValue($requestDetail, ['codusuario'], (string)$uid);
if ((int)$requestUser === $uid) $requestUser = (string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['login'] ?? $requestUser);
$legalCode = trim((string)($context['CODIGO'] ?? ''));
$analysisSubject = $legalCode !== ''
    ? ($idioma === 'en' ? 'Legal requirement - ' . $legalCode : ($idioma === 'es' ? 'Requisito legal - ' . $legalCode : 'Requisito legal - ' . $legalCode))
    : ($idioma === 'en' ? 'AI potential analysis result' : ($idioma === 'es' ? 'Resultado del análisis de potenciales por IA' : 'Resultado da análise de potenciais por IA'));
$title = $analysisSubject;
$title = $idioma === 'en' ? 'AI potential analysis result' : ($idioma === 'es' ? 'Resultado del análisis de potenciales por IA' : 'Resultado da análise de potenciais por IA');
$title = $analysisSubject;
$labels = [
    'origin' => $idioma === 'en' ? 'Analysis origin' : ($idioma === 'es' ? 'Origen del análisis' : 'Origem da análise'),
    'legal' => $idioma === 'en' ? 'Legal requirement' : ($idioma === 'es' ? 'Requisito legal' : 'Requisito legal'),
    'code' => $idioma === 'en' ? 'Legislation code' : ($idioma === 'es' ? 'Código de legislación' : 'Código da legislação'),
    'unit' => $idioma === 'en' ? 'Unit / company' : ($idioma === 'es' ? 'Unidad / empresa' : 'Unidade / empresa'),
    'context' => $idioma === 'en' ? 'Context sent to AI' : ($idioma === 'es' ? 'Contexto enviado a la IA' : 'Contexto enviado à IA'),
    'request' => $idioma === 'en' ? 'Request tracking' : ($idioma === 'es' ? 'Seguimiento de la solicitud' : 'Acompanhamento da solicitação'),
    'requestedBy' => $idioma === 'en' ? 'Requested by' : ($idioma === 'es' ? 'Solicitada por' : 'Solicitada por'),
    'requestedAt' => $idioma === 'en' ? 'Requested at' : ($idioma === 'es' ? 'Solicitada en' : 'Solicitada em'),
    'status' => $idioma === 'en' ? 'Status' : ($idioma === 'es' ? 'Estado' : 'Status'),
    'processor' => $idioma === 'en' ? 'Processor' : ($idioma === 'es' ? 'Procesador' : 'Processador'),
    'potentials' => $idioma === 'en' ? 'Generated potentials' : ($idioma === 'es' ? 'Potenciales generados' : 'Potenciais gerados'),
    'search' => $idioma === 'en' ? 'Search in potentials' : ($idioma === 'es' ? 'Buscar en potenciales' : 'Buscar nos potenciais'),
    'allStatuses' => $idioma === 'en' ? 'All statuses' : ($idioma === 'es' ? 'Todos los estados' : 'Todos os status'),
    'type' => $idioma === 'en' ? 'Type' : ($idioma === 'es' ? 'Tipo' : 'Tipo'),
    'potentialTitle' => $idioma === 'en' ? 'Title' : ($idioma === 'es' ? 'Título' : 'Título'),
    'description' => $idioma === 'en' ? 'Description' : ($idioma === 'es' ? 'Descripción' : 'Descrição'),
    'justification' => $idioma === 'en' ? 'Justification' : ($idioma === 'es' ? 'Justificación' : 'Justificativa'),
    'confidence' => $idioma === 'en' ? 'Confidence' : ($idioma === 'es' ? 'Confianza' : 'Confiança'),
    'actions' => $idioma === 'en' ? 'Actions' : ($idioma === 'es' ? 'Acciones' : 'Ações'),
    'approve' => $idioma === 'en' ? 'Approve' : ($idioma === 'es' ? 'Aprobar' : 'Aprovar'),
    'reject' => $idioma === 'en' ? 'Reject' : ($idioma === 'es' ? 'Rechazar' : 'Rejeitar'),
    'empty' => $idioma === 'en' ? 'No potentials found for this request.' : ($idioma === 'es' ? 'No se encontraron potenciales para esta solicitud.' : 'Nenhum potencial encontrado para esta solicitação.'),
    'filteredEmpty' => $idioma === 'en' ? 'No potential matches the current filters.' : ($idioma === 'es' ? 'Ningún potencial coincide con los filtros actuales.' : 'Nenhum potencial corresponde aos filtros atuais.'),
    'reload' => $idioma === 'en' ? 'Refresh' : ($idioma === 'es' ? 'Actualizar' : 'Recarregar'),
    'help' => $idioma === 'en' ? 'Help' : ($idioma === 'es' ? 'Ayuda' : 'Ajuda'),
    'contextUnavailable' => $idioma === 'en' ? 'Context unavailable.' : ($idioma === 'es' ? 'Contexto no disponible.' : 'Contexto indisponível.'),
    'confirmApprove' => $idioma === 'en' ? 'Approve this potential?' : ($idioma === 'es' ? '¿Aprobar este potencial?' : 'Aprovar este potencial?'),
    'requestNo' => $idioma === 'en' ? 'Request' : ($idioma === 'es' ? 'Solicitud' : 'Solicitação'),
    'newAnalysis' => $idioma === 'en' ? 'Request new analysis' : ($idioma === 'es' ? 'Solicitar nuevo análisis' : 'Solicitar nova análise'),
    'actionsAria' => $idioma === 'en' ? 'Potential actions' : ($idioma === 'es' ? 'Acciones de los potenciales' : 'Ações dos potenciais'),
    'selection' => $idioma === 'en' ? 'Selection' : ($idioma === 'es' ? 'Selección' : 'Seleção'),
    'selectPotential' => $idioma === 'en' ? 'Select potential' : ($idioma === 'es' ? 'Seleccionar potencial' : 'Selecionar potencial'),
    'loading' => $idioma === 'en' ? 'Loading...' : ($idioma === 'es' ? 'Cargando...' : 'Carregando...'),
    'selectRow' => $idioma === 'en' ? 'Select the row' : ($idioma === 'es' ? 'Seleccione la línea' : 'Selecione a linha'),
    'pageOf' => $idioma === 'en' ? 'Page {page} of {pages}' : ($idioma === 'es' ? 'Página {page} de {pages}' : 'Página {page} de {pages}'),
    'records' => $idioma === 'en' ? '{count} record(s)' : ($idioma === 'es' ? '{count} registro(s)' : '{count} registro(s)'),
    'confirmReject' => $idioma === 'en' ? 'Reject this potential?' : ($idioma === 'es' ? '¿Rechazar este potencial?' : 'Rejeitar este potencial?'),
];
$categoryLabels = $idioma === 'en' ? ['license' => 'Licenses', 'monitoring' => 'Measurement and Monitoring', 'training' => 'Training', 'infrastructure' => 'Infrastructure', 'documents' => 'Documents'] : ($idioma === 'es' ? ['license' => 'Licencias', 'monitoring' => 'Medición y Monitoreo', 'training' => 'Capacitaciones', 'infrastructure' => 'Infraestructura', 'documents' => 'Documentos'] : ['license' => 'Licenças', 'monitoring' => 'Medição e Monitoramento', 'training' => 'Treinamentos', 'infrastructure' => 'Infraestrutura', 'documents' => 'Documentos']);
?><!doctype html>
<html lang="<?= $e($idioma) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $e($title) ?></title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
    <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2">
    <link rel="stylesheet" href="../assets/css/app.css?v=1">
    <style>
        html{overflow:auto}body{min-width:320px;overflow:auto;overflow-x:auto;overflow-y:auto}.page-toolbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap}.toolbar-actions,.grid-filters{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}.grid-filters{justify-content:space-between}.origin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.75rem}.origin-item{border:1px solid var(--gp-border);border-radius:.5rem;padding:.65rem;background:var(--gp-surface);min-width:0}.origin-item.legal-item{grid-column:1/-1}.origin-item .label{display:block;color:var(--gp-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.03em}.origin-item .value{display:block;margin-top:.2rem;overflow-wrap:anywhere}.legal-value{max-height:120px;overflow:auto;white-space:pre-wrap}.context-box{max-height:180px;overflow:auto;white-space:pre-wrap}.grid-scroll{max-height:55vh;overflow:auto}.grid-scroll table{min-width:1040px}.grid-scroll th{position:sticky;top:0;z-index:2;background:var(--gp-primary-soft)}.grid-scroll tbody tr[data-potential-id]{cursor:pointer}.grid-scroll tbody tr.selected{background:var(--gp-primary-soft);box-shadow:inset 3px 0 var(--gp-primary)}.cell-long{min-width:220px;max-width:360px;white-space:normal;vertical-align:top}.cell-scroll{max-height:118px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}.cell-title{min-width:190px;max-width:280px;white-space:normal}.confidence{white-space:nowrap}.pagination-bar{display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap}.loading-state{display:none}.is-hidden{display:none!important}.category-iframe-tabs .tab-pane{height:calc(100vh - 160px);min-height:440px}.category-iframe-tabs iframe{width:100%;height:100%;min-height:440px;border:0;border-radius:.35rem;background:var(--gp-surface)}@media(max-width:576px){body{padding:.75rem!important}.card-body{padding:.75rem}.grid-scroll{max-height:60vh}.page-toolbar h4{font-size:1.1rem}}
    </style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="p-3 bg-light">
<main class="container-fluid">
    <div class="page-toolbar mb-3">
        <div><h4 class="mb-1"><i class="bi bi-stars me-2"></i><?= $e($title) ?></h4><div class="text-muted small"><?= $e($labels['requestNo']) ?> #<?= $e($id) ?></div></div>
        <div class="toolbar-actions">
            <button type="button" class="btn btn-sm btn-outline-warning" data-request-new-analysis title="<?= $e($labels['newAnalysis']) ?>" aria-label="<?= $e($labels['newAnalysis']) ?>"><i class="bi bi-stars"></i></button>
            <button type="button" class="btn btn-sm btn-outline-info" data-open-transacao-ajuda data-help-transaction="ia_potenciais_resultado" title="<?= $e($labels['help']) ?>" aria-label="<?= $e($labels['help']) ?>"><i class="bi bi-question-circle"></i></button>
        </div>
    </div>
    <?php if ($message): ?><div class="alert alert-success py-2" role="status"><?= $e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= $e($error) ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-3" role="tablist"><li class="nav-item" role="presentation"><button class="nav-link active" id="licenseTabButton" data-bs-toggle="tab" data-bs-target="#licenseTab" type="button" role="tab" aria-controls="licenseTab" aria-selected="true"><?= $e($categoryLabels['license']) ?></button></li><li class="nav-item" role="presentation"><button class="nav-link" id="monitoringTabButton" data-bs-toggle="tab" data-bs-target="#monitoringTab" type="button" role="tab" aria-controls="monitoringTab" aria-selected="false"><?= $e($categoryLabels['monitoring']) ?></button></li><li class="nav-item" role="presentation"><button class="nav-link" id="trainingTabButton" data-bs-toggle="tab" data-bs-target="#trainingTab" type="button" role="tab" aria-controls="trainingTab" aria-selected="false"><?= $e($categoryLabels['training']) ?></button></li><li class="nav-item" role="presentation"><button class="nav-link" id="infrastructureTabButton" data-bs-toggle="tab" data-bs-target="#infrastructureTab" type="button" role="tab" aria-controls="infrastructureTab" aria-selected="false"><?= $e($categoryLabels['infrastructure']) ?></button></li><li class="nav-item" role="presentation"><button class="nav-link" id="documentsTabButton" data-bs-toggle="tab" data-bs-target="#documentsTab" type="button" role="tab" aria-controls="documentsTab" aria-selected="false"><?= $e($categoryLabels['documents']) ?></button></li></ul>
    <div class="tab-content category-iframe-tabs"><div class="tab-pane fade show active" id="licenseTab" role="tabpanel" aria-labelledby="licenseTabButton"><iframe title="<?= $e($categoryLabels['license']) ?>" src="ia_potenciais_licencas.php?codsolicitacao=<?= $e($id) ?>"></iframe></div><div class="tab-pane fade" id="monitoringTab" role="tabpanel" aria-labelledby="monitoringTabButton"><iframe title="<?= $e($categoryLabels['monitoring']) ?>" loading="lazy" src="ia_potenciais_medicao.php?codsolicitacao=<?= $e($id) ?>"></iframe></div><div class="tab-pane fade" id="trainingTab" role="tabpanel" aria-labelledby="trainingTabButton"><iframe title="<?= $e($categoryLabels['training']) ?>" loading="lazy" src="ia_potenciais_treinamentos.php?codsolicitacao=<?= $e($id) ?>"></iframe></div><div class="tab-pane fade" id="infrastructureTab" role="tabpanel" aria-labelledby="infrastructureTabButton"><iframe title="<?= $e($categoryLabels['infrastructure']) ?>" loading="lazy" src="ia_potenciais_infraestrutura.php?codsolicitacao=<?= $e($id) ?>"></iframe></div><div class="tab-pane fade" id="documentsTab" role="tabpanel" aria-labelledby="documentsTabButton"><iframe title="<?= $e($categoryLabels['documents']) ?>" loading="lazy" src="ia_potenciais_documentos.php?codsolicitacao=<?= $e($id) ?>"></iframe></div></div>
    <div class="legacy-result-content d-none">
    <div class="tab-content">
    <section class="tab-pane fade" id="originTab" role="tabpanel" aria-labelledby="originTabButton">
        <div class="card shadow-sm mb-3">
        <div class="card-header bg-white"><strong id="originHeading"><i class="bi bi-diagram-3 me-1"></i><?= $e($labels['origin']) ?></strong></div>
        <div class="card-body">
            <div class="origin-grid">
                <div class="origin-item legal-item"><span class="label"><?= $e($labels['legal']) ?></span><div class="value legal-value"><?= nl2br($e($contextValue($context, ['PADRAO']))) ?></div></div>
                <div class="origin-item"><span class="label"><?= $e($labels['code']) ?></span><span class="value"><?= $e($contextValue($context, ['CODIGO'])) ?></span></div>
                <div class="origin-item"><span class="label"><?= $e($labels['unit']) ?></span><span class="value"><?= $e($contextValue($context, ['UNIDADE'])) ?> / <?= $e($companyName ?: 'Empresa ' . $contextValue($context, ['CODEMPRESA'])) ?></span></div>
                <div class="origin-item"><span class="label"><?= $e($labels['requestedBy']) ?></span><span class="value"><?= $e($requestUser) ?></span></div>
                <div class="origin-item"><span class="label"><?= $e($labels['requestedAt']) ?></span><span class="value"><?= $e($date($requestDetail['solicitada_em'] ?? '')) ?></span></div>
                <div class="origin-item"><span class="label"><?= $e($labels['status']) ?></span><span class="value"><span class="badge <?= $e($statusClass($requestDetail['status'] ?? '')) ?>"><?= $e($statusLabel($requestDetail['status'] ?? '')) ?></span></span></div>
            </div>
            <div class="small text-muted mt-2"><?= $e($labels['request']) ?>: #<?= $e($id) ?> · <?= $e($labels['processor']) ?>: <?= $e($requestDetail['processador'] ?? '—') ?></div>
        </div>
        </div>
    </section>

    <section class="tab-pane fade show active" id="potentialsTab" role="tabpanel" aria-labelledby="potentialsTabButton">
    <div class="card shadow-sm" aria-labelledby="potentialsHeading">
        <div class="card-header bg-white page-toolbar"><strong id="potentialsHeading"><i class="bi bi-list-check me-1"></i><?= $e($labels['potentials']) ?></strong><div class="small text-muted" id="gridCount"></div></div>
        <div class="card-body">
            <div class="grid-filters mb-3 border rounded bg-light p-2" role="toolbar" aria-label="<?= $e($labels['actionsAria']) ?>">
                <div class="toolbar-actions"><button type="button" class="btn btn-sm btn-success" data-grid-action="APROVADO"<?= $permission['pode_editar'] ? '' : ' disabled' ?>><i class="bi bi-check2 me-1"></i><?= $e($labels['approve']) ?></button><button type="button" class="btn btn-sm btn-outline-danger" data-grid-action="REJEITADO"<?= $permission['pode_editar'] ? '' : ' disabled' ?>><i class="bi bi-x-lg me-1"></i><?= $e($labels['reject']) ?></button></div>
                <div class="toolbar-actions" role="search">
                <label class="visually-hidden" for="potentialSearch"><?= $e($labels['search']) ?></label><input id="potentialSearch" class="form-control form-control-sm" type="search" placeholder="<?= $e($labels['search']) ?>" style="min-width:240px">
                <label class="visually-hidden" for="potentialStatus"><?= $e($labels['status']) ?></label><select id="potentialStatus" class="form-select form-select-sm" style="width:auto"><option value=""><?= $e($labels['allStatuses']) ?></option><option value="SUGERIDO"><?= $e($statusLabel('SUGERIDO')) ?></option><option value="APROVADO"><?= $e($statusLabel('APROVADO')) ?></option><option value="REJEITADO"><?= $e($statusLabel('REJEITADO')) ?></option></select></div>
            </div>
            <div class="loading-state alert alert-info py-2" id="potentialLoading"><span class="spinner-border spinner-border-sm me-2"></span><?= $e($labels['loading']) ?></div>
            <?php if (!$rows): ?><div class="alert alert-info py-2" id="potentialEmpty"><i class="bi bi-info-circle me-1"></i><?= $e($labels['empty']) ?></div><?php else: ?>
            <div class="table-responsive grid-scroll" id="potentialGridWrap"><table class="table table-sm table-hover align-middle mb-0" data-testid="potentials-grid"><thead><tr><th aria-label="<?= $e($labels['selection']) ?>"></th><th><?= $e($labels['type']) ?></th><th><?= $e($labels['potentialTitle']) ?></th><th><?= $e($labels['description']) ?></th><th><?= $e($labels['justification']) ?></th><th><?= $e($labels['confidence']) ?></th><th><?= $e($labels['status']) ?></th><th><?= $e($labels['actions']) ?></th></tr></thead><tbody>
                <?php foreach ($rows as $row): $rowStatus = strtoupper((string)($row['status'] ?? '')); ?>
                <tr data-grid-row data-potential-id="<?= $e($row['codpotencial'] ?? '') ?>" data-status="<?= $e($rowStatus) ?>" data-search="<?= $e(implode(' ', [(string)($row['tipo_potencial'] ?? ''), (string)($row['titulo'] ?? ''), (string)($row['descricao'] ?? ''), (string)($row['justificativa_ia'] ?? ''), (string)($rowStatus)])) ?>">
                    <td><?php if ($rowStatus === 'SUGERIDO' && $permission['pode_editar']): ?><input type="radio" name="selected_potential" value="<?= $e($row['codpotencial'] ?? '') ?>" aria-label="<?= $e($labels['selectPotential']) ?> <?= $e($row['codpotencial'] ?? '') ?>"><?php endif; ?></td>
                    <td><span class="badge text-bg-light border"><?= $e($row['tipo_potencial'] ?? '—') ?></span></td>
                    <td class="cell-title"><strong><?= $e($row['titulo'] ?? '—') ?></strong></td>
                    <td class="cell-long"><div class="cell-scroll"><?= nl2br($e($row['descricao'] ?? '—')) ?></div></td>
                    <td class="cell-long"><div class="cell-scroll"><?= nl2br($e($row['justificativa_ia'] ?? '—')) ?></div></td>
                    <td class="confidence"><?= $e($row['confianca_ia'] !== null && $row['confianca_ia'] !== '' ? number_format((float)$row['confianca_ia'] * 100, 0, ',', '.') . '%' : '—') ?></td>
                    <td><span class="badge <?= $e($statusClass($rowStatus)) ?>"><?= $e($statusLabel($rowStatus)) ?></span></td>
                    <td><?= $rowStatus === 'SUGERIDO' && $permission['pode_editar'] ? '<span class="text-muted small">' . $e($labels['selectRow']) . '</span>' : '—' ?></td>
                </tr>
                <?php endforeach; ?></tbody></table></div><div class="alert alert-info py-2 mt-3 is-hidden" id="filteredEmpty"><?= $e($labels['filteredEmpty']) ?></div><div class="pagination-bar mt-3"><span class="small text-muted" id="pageInfo"></span><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" id="previousPage">‹</button><button type="button" class="btn btn-outline-secondary" id="nextPage">›</button></div></div>
            <?php endif; ?>
        </div>
    </div>
    </section>
    </div>
</div>
</main>
<form method="post" id="validationForm" class="d-none"><input type="hidden" name="action" value="validar"><input type="hidden" name="id" value="<?= $e($id) ?>"><input type="hidden" name="codpotencial" id="validationPotential"><input type="hidden" name="status" id="validationStatus"></form>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>var IA_TEXTOS = <?= json_encode(['newAnalysis' => $labels['newAnalysis'], 'pageOf' => $labels['pageOf'], 'records' => $labels['records']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toolbar = document.querySelector('.page-toolbar .toolbar-actions');
    if (toolbar) {
        toolbar.querySelectorAll('a[href*="ia_potenciais_resultado_pdf"], button[onclick="location.reload()"], button[title*="PDF"], button[title*="recarregar"], button[title*="Recarregar"]').forEach(function (button) { button.remove(); });
        var newAnalysis = toolbar.querySelector('[data-request-new-analysis]') || document.createElement('button');
        newAnalysis.type = 'button'; newAnalysis.className = 'btn btn-sm btn-outline-warning'; newAnalysis.setAttribute('data-request-new-analysis', ''); newAnalysis.title = IA_TEXTOS.newAnalysis; newAnalysis.setAttribute('aria-label', IA_TEXTOS.newAnalysis); newAnalysis.innerHTML = '<i class="bi bi-stars"></i>';
        var help = toolbar.querySelector('[data-open-transacao-ajuda]'); toolbar.insertBefore(newAnalysis, help || toolbar.firstChild);
        newAnalysis.addEventListener('click', function () {
            var button = this, data = new FormData(); data.append('action', 'request_new_analysis'); button.disabled = true;
            fetch(window.location.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); }).then(function (result) { if (!result.body.ok) throw new Error(result.body.message || 'Não foi possível solicitar a nova análise.'); window.location.href = 'ia_potenciais_resultado.php?codsolicitacao=' + encodeURIComponent(result.body.codsolicitacao); }).catch(function (error) { window.alert(error.message || 'Não foi possível solicitar a nova análise.'); button.disabled = false; });
        });
    }
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-grid-row]')), page = 1, pageSize = 10;
    var search = document.getElementById('potentialSearch'), status = document.getElementById('potentialStatus'), pageInfo = document.getElementById('pageInfo'), count = document.getElementById('gridCount'), empty = document.getElementById('filteredEmpty');
    function render() { if (!rows.length) return; var term = (search.value || '').toLowerCase().trim(), selected = status.value, filtered = rows.filter(function (row) { return (!selected || row.dataset.status === selected) && (!term || (row.dataset.search || '').toLowerCase().indexOf(term) >= 0); }); var pages = Math.max(1, Math.ceil(filtered.length / pageSize)); page = Math.min(page, pages); rows.forEach(function (row) { row.classList.add('is-hidden'); }); filtered.slice((page - 1) * pageSize, page * pageSize).forEach(function (row) { row.classList.remove('is-hidden'); }); empty.classList.toggle('is-hidden', filtered.length > 0); pageInfo.textContent = filtered.length ? IA_TEXTOS.pageOf.replace('{page}', page).replace('{pages}', pages) : ''; count.textContent = IA_TEXTOS.records.replace('{count}', filtered.length); document.getElementById('previousPage').disabled = page <= 1; document.getElementById('nextPage').disabled = page >= pages; }
    search?.addEventListener('input', function () { page = 1; render(); }); status?.addEventListener('change', function () { page = 1; render(); }); document.getElementById('previousPage')?.addEventListener('click', function () { page--; render(); }); document.getElementById('nextPage')?.addEventListener('click', function () { page++; render(); }); render();
    document.querySelectorAll('[data-grid-row]').forEach(function (row) { row.addEventListener('click', function (event) { if (event.target.closest('button,a,input,select,textarea')) { if (!event.target.closest('input[type="radio"]')) return; } var radio = row.querySelector('input[name="selected_potential"]'); if (!radio) return; radio.checked = true; rows.forEach(function (item) { item.classList.remove('selected'); }); row.classList.add('selected'); }); });
    document.querySelectorAll('[data-grid-action]').forEach(function (button) { button.addEventListener('click', function () { var selected = document.querySelector('input[name="selected_potential"]:checked'); if (!selected) { window.alert('Selecione um potencial clicando na linha antes de decidir.'); return; } var action = button.dataset.gridAction, approve = action === 'APROVADO'; if (!window.confirm(approve ? <?= json_encode($labels['confirmApprove'], JSON_UNESCAPED_UNICODE) ?> : <?= json_encode($labels['confirmReject'], JSON_UNESCAPED_UNICODE) ?>)) return; document.getElementById('validationPotential').value = selected.value; document.getElementById('validationStatus').value = action; document.getElementById('validationForm').submit(); }); });
});
</script>
<script src="../assets/js/ajuda-transacao.js?v=2"></script><script src="../assets/js/ia-potenciais-resultado-ajuda.js?v=2"></script>
</body></html>

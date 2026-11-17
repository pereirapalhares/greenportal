<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Textos em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');
$context = strtoupper(trim((string)($_GET['contexto'] ?? '')));
$permissions = [
    'LEGAL' => ['REQUISITOS_LEGAIS', 'LEGISLACAO', 'SITUACAO_CUMPRIMENTO', 'LAIA', 'LPRO', 'LAIS'],
    'SGA' => ['LICENCAS', 'MM_PLANO_MEDICAO_MONITORAMENTO', 'MODULOLEGAL_TREINAMENTOS', 'MODULOLEGAL_INFRAESTRUTURA', 'CONTROLE_DOCUMENTOS'],
    'QUALITY' => ['NAO_CONFORMIDADES', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS', 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS'],
    'WORKFLOW' => ['QUESTIONARIOS'],
    'SUPPLIER' => ['HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'HOMOLOGACAO_FORNECEDORES'],
    'ESG' => ['ESG'],
    'PROJECTS' => ['PROJETOS'],
    'EMPLOYEE_INTEGRATION' => ['INTEGRACAO_FUNCIONARIOS'],
];
$admin = !empty(PermissaoTransacao::obter('QUESTIONARIOS')['pode_visualizar']);
$supplier = !empty(PermissaoTransacao::obter('HOMOLOGACAO_FORNECEDOR_FORNECEDOR')['pode_visualizar']) || !empty(PermissaoTransacao::obter('HOMOLOGACAO_FORNECEDORES')['pode_visualizar']);
$allowed = $context === '' ? ($admin || $supplier) : ($admin || (bool)array_filter($permissions[$context] ?? [], static fn(string $code): bool => !empty(PermissaoTransacao::obter($code)['pode_visualizar'])));
if (!$allowed) { http_response_code(403); exit(IdiomaService::t('questionario_execucao.erro.sem_permissao', ['pt' => 'Sem permissão para executar questionários neste contexto.', 'en' => 'Not allowed to run questionnaires in this context.', 'es' => 'Sin permiso para ejecutar cuestionarios en este contexto.'])); }
if (empty($_SESSION['questionario_execucao_csrf'])) $_SESSION['questionario_execucao_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['questionario_execucao_csrf'];
$filters = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$filters = is_array($filters) ? $filters : [];
$supplierId = (int)($_GET['CODFORNECEDOR'] ?? $_GET['origem_id'] ?? 0);
$unit = (int)($_GET['CODUNIDADE'] ?? $_GET['codunidade'] ?? $filters['CODUNIDADE'] ?? $filters['codunidade'] ?? 0);
$originId = $supplierId > 0 ? (string)$supplierId : (string)($_GET['origem_id'] ?? '');
// Migração 296: o fornecedor externo só responde questionários dos fornecedores ligados a ele.
// Migração 316: o interno restrito, só os de fornecedores das suas unidades (ou pelos quais responde).
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
if (HomologacaoFornecedorAcesso::externo() || (HomologacaoFornecedorAcesso::unidade() && $supplierId > 0)) {
    require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
    require_once dirname(__DIR__) . '/classes/TenantContext.php';
    try { $acessoPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); } catch (Throwable) { http_response_code(401); exit(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])); }
    HomologacaoFornecedorAcesso::exigirFornecedor($acessoPdo, $supplierId);
    $acessoPdo = null;
}
if ($originId === '' && $context !== '') {
    $originValues = [];
    foreach ($filters as $key => $value) if (is_scalar($value) && trim((string)$value) !== '' && !in_array(strtoupper((string)$key), ['CODUNIDADE', 'CODUSUARIO'], true)) $originValues[] = strtoupper((string)$key) . ':' . trim((string)$value);
    $originId = substr(implode('|', $originValues), 0, 100);
}
$origin = $context !== '' ? 'QUESTIONNAIRE_' . $context : ($supplierId > 0 ? 'fo_fornecedores' : '');
$workflow = $context !== '' ? $context . '_QUESTIONNAIRE' : ($supplierId > 0 ? 'SUPPLIER_QUESTIONNAIRE' : '');
$contextLabels = ['LEGAL' => ['pt' => 'Legal', 'en' => 'Legal', 'es' => 'Legal'], 'SGA' => ['pt' => 'SGA', 'en' => 'EMS', 'es' => 'SGA'], 'QUALITY' => ['pt' => 'Qualidade', 'en' => 'Quality', 'es' => 'Calidad'], 'WORKFLOW' => ['pt' => 'Workflow', 'en' => 'Workflow', 'es' => 'Workflow'], 'SUPPLIER' => ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'], 'ESG' => ['pt' => 'ESG', 'en' => 'ESG', 'es' => 'ESG'], 'PROJECTS' => ['pt' => 'Projetos', 'en' => 'Projects', 'es' => 'Proyectos'], 'EMPLOYEE_INTEGRATION' => ['pt' => 'Integração de Funcionários', 'en' => 'Employee onboarding', 'es' => 'Integración de empleados']];
$contextLabel = isset($contextLabels[$context]) ? IdiomaService::t('questionario_execucao.contexto.' . strtolower($context), $contextLabels[$context]) : ($supplierId > 0 ? IdiomaService::t('questionario_execucao.contexto.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor']) . ' #' . $supplierId : IdiomaService::t('questionario_execucao.contexto.universal', ['pt' => 'Execução universal', 'en' => 'General run', 'es' => 'Ejecución universal']));
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $tt('questionario_execucao.titulo', ['pt' => 'Executar questionário', 'en' => 'Run questionnaire', 'es' => 'Ejecutar cuestionario']) ?></title><link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=17"></head>
<body class="p-2 bg-light"><main class="container-fluid" data-questionnaire-execution data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" data-context="<?= htmlspecialchars($context, ENT_QUOTES, 'UTF-8') ?>" data-origin-entity="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>" data-origin-id="<?= htmlspecialchars($originId, ENT_QUOTES, 'UTF-8') ?>" data-unit="<?= $unit ?>" data-workflow="<?= htmlspecialchars($workflow, ENT_QUOTES, 'UTF-8') ?>">
<div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0"><i class="bi bi-ui-checks-grid me-2"></i><?= $tt('questionario_execucao.titulo', ['pt' => 'Executar questionário', 'en' => 'Run questionnaire', 'es' => 'Ejecutar cuestionario']) ?></h5><span class="small text-muted" data-context-label><?= htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
<section class="card shadow-sm border-0"><div class="card-body"><div class="row g-2 align-items-end"><div class="col-md-8"><label class="form-label"><?= $tt('questionario_execucao.campo.questionario', ['pt' => 'Questionário publicado', 'en' => 'Published questionnaire', 'es' => 'Cuestionario publicado']) ?></label><select class="form-select" data-questionnaire-select><option value=""><?= $tt('questionario_execucao.msg.carregando', ['pt' => 'Carregando...', 'en' => 'Loading...', 'es' => 'Cargando...']) ?></option></select></div><div class="col-md-4"><button type="button" class="btn btn-success w-100" data-start><i class="bi bi-play-fill me-1"></i><?= $tt('questionario_execucao.botao.iniciar', ['pt' => 'Iniciar execução', 'en' => 'Start', 'es' => 'Iniciar ejecución']) ?></button></div></div><div class="alert d-none mt-2 py-2" data-message></div></div></section>
<section class="card shadow-sm border-0 mt-2 d-none" data-form-card><div class="card-body"><h6 data-form-title></h6><form data-answer-form><div data-questions></div><div class="mt-3"><button type="submit" class="btn btn-success" data-submit><i class="bi bi-check2 me-1"></i><?= $tt('questionario_execucao.botao.concluir', ['pt' => 'Concluir questionário', 'en' => 'Complete questionnaire', 'es' => 'Concluir cuestionario']) ?></button></div></form></div></section>
</main><?= IdiomaService::script([], [
    'questionario_execucao.js.erro' => ['pt' => 'Não foi possível concluir.', 'en' => 'Could not complete.', 'es' => 'No fue posible concluir.'],
    'questionario_execucao.js.selecione' => ['pt' => 'Selecione...', 'en' => 'Select...', 'es' => 'Seleccione...'],
    'questionario_execucao.js.anexos' => ['pt' => 'Anexos serão associados na etapa de evidências.', 'en' => 'Attachments will be linked in the evidence step.', 'es' => 'Los adjuntos se asociarán en la etapa de evidencias.'],
    'questionario_execucao.js.nenhum' => ['pt' => 'Nenhum questionário publicado para este contexto', 'en' => 'No published questionnaire for this context', 'es' => 'Ningún cuestionario publicado para este contexto'],
    'questionario_execucao.js.erro_carregar' => ['pt' => 'Erro ao carregar', 'en' => 'Loading error', 'es' => 'Error al cargar'],
    'questionario_execucao.js.selecione_questionario' => ['pt' => 'Selecione um questionário publicado.', 'en' => 'Select a published questionnaire.', 'es' => 'Seleccione un cuestionario publicado.'],
    'questionario_execucao.js.execucao' => ['pt' => 'execução', 'en' => 'run', 'es' => 'ejecución'],
    'questionario_execucao.js.ja_concluida' => ['pt' => 'Esta execução já foi concluída.', 'en' => 'This run has already been completed.', 'es' => 'Esta ejecución ya fue concluida.'],
    'questionario_execucao.js.iniciada' => ['pt' => 'Execução iniciada.', 'en' => 'Run started.', 'es' => 'Ejecución iniciada.'],
    'questionario_execucao.js.concluido' => ['pt' => 'Questionário concluído. Percentual: {percentual}%.', 'en' => 'Questionnaire completed. Score: {percentual}%.', 'es' => 'Cuestionario concluido. Porcentaje: {percentual}%.'],
]) ?><script>window.greenPortalIdioma=<?= json_encode(IdiomaService::idioma()) ?>;</script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/questionario-execucao.js?v=3"></script></body></html>

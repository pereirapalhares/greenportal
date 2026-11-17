<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/QuestionnaireService.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$json = static function (array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);

$context = strtoupper(trim((string)($_POST['contexto'] ?? $_GET['contexto'] ?? '')));
$contextPermissions = [
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
$contextAllowed = $context !== '' && (bool)array_filter($contextPermissions[$context] ?? [], static fn(string $code): bool => !empty(PermissaoTransacao::obter($code)['pode_visualizar']));
if (!$admin && !$supplier && !$contextAllowed) $json(['ok' => false, 'message' => IdiomaService::t('api.questionario.sem_permissao', ['pt' => 'Sem permissão para executar questionários neste contexto.', 'en' => 'Not allowed to run questionnaires in this context.', 'es' => 'Sin permiso para ejecutar cuestionarios en este contexto.'])], 403);

$env = GreenPortalDatabase::env();
$tenant = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);
$universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$service = new QuestionnaireService($tenant, $universal);
$csrf = (string)($_SESSION['questionario_execucao_csrf'] ?? '');
if ($csrf === '') { $csrf = bin2hex(random_bytes(32)); $_SESSION['questionario_execucao_csrf'] = $csrf; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'catalog')));
$userId = (int)($_SESSION['usuario']['codusuario'] ?? 0);

try {
    if ($action === 'catalog') {
        $rows = $context !== '' ? $service->catalogForContext($context) : $service->catalog($_GET['modulo'] ?? null);
        $json(['ok' => true, 'rows' => $rows, 'contexto' => $context]);
    }
    if ($action === 'start') {
        $workflow = trim((string)($_POST['workflow_code'] ?? ''));
        if ($context !== '') $workflow = $service->contextWorkflow($context);
        if ($workflow !== '' && !in_array(strtoupper($workflow), ['SUPPLIER_QUESTIONNAIRE', 'LEGAL_QUESTIONNAIRE', 'SGA_QUESTIONNAIRE', 'QUALITY_QUESTIONNAIRE', 'WORKFLOW_QUESTIONNAIRE', 'ESG_QUESTIONNAIRE', 'PROJECTS_QUESTIONNAIRE', 'EMPLOYEE_INTEGRATION_QUESTIONNAIRE'], true)) $workflow = null;
        $unit = ($_POST['codunidade'] ?? '') === '' ? null : (int)$_POST['codunidade'];
        $originEntity = ($_POST['origem_entidade'] ?? '') ?: null;
        $originId = ($_POST['origem_id'] ?? '') ?: null;
        if ($context !== '') $originEntity = 'QUESTIONNAIRE_' . $context;
        $execution = $service->start((string)($_POST['codigo'] ?? ''), $userId, $unit, $originEntity, $originId, ($_POST['chave_idempotencia'] ?? '') ?: null, $workflow);
        $json(['ok' => true, 'execution' => $execution, 'questions' => $service->questions((int)$execution['codexecucao'])]);
    }
    if ($action === 'submit') {
        $answers = json_decode((string)($_POST['answers'] ?? '{}'), true);
        if (!is_array($answers)) $json(['ok' => false, 'message' => IdiomaService::t('api.questionario.respostas', ['pt' => 'Respostas inválidas.', 'en' => 'Invalid answers.', 'es' => 'Respuestas inválidas.'])], 422);
        $json(['ok' => true, 'result' => $service->submit((int)($_POST['codexecucao'] ?? 0), $userId, $answers)]);
    }
    if ($action === 'questions') $json(['ok' => true, 'questions' => $service->questions((int)($_GET['codexecucao'] ?? 0))]);
    if ($action === 'details') $json(['ok' => true, 'details' => $service->details((int)($_GET['codexecucao'] ?? 0), $userId)]);
    if ($action === 'evidence') {
        $question = ($_POST['codpergunta'] ?? '') === '' ? null : (int)$_POST['codpergunta'];
        $evidence = $service->addEvidence((int)($_POST['codexecucao'] ?? 0), $userId, $question, (string)($_POST['entidade'] ?? ''), (string)($_POST['registro_id'] ?? ''));
        $json(['ok' => true, 'evidence' => $evidence]);
    }
    $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.acao_invalida', ['pt' => IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']), 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);
} catch (Throwable $exception) {
    $json(['ok' => false, 'message' => $exception->getMessage()], 422);
}

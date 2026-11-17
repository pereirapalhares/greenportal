<?php
declare(strict_types=1);

/*
 * API da tela Administração › Questionários (abas Legal, Fornecedores e Perfil de atribuições).
 * Escopo (migração 291, classes/QuestionarioVersaoService.php): ADMINISTRADOR altera a versão universal; os demais perfis
 * com Editar em Questionários alteram a versão da própria empresa. Publicar a universal faz o merge nas empresas.
 *
 *   GET  catalog&modulo=        questionários do módulo com a versão de trabalho do escopo e as informações de versão
 *   GET  builder&codversao=     perguntas, opções e blocos da versão
 *   GET  versoes&codquestionario=   histórico de versões (universal + da empresa)
 *   POST nova_versao            cria o rascunho do escopo (cópia da versão em uso)
 *   POST descartar              arquiva o rascunho do escopo
 *   POST pergunta / editar_pergunta / excluir_pergunta / opcoes (rascunho do escopo)
 *   POST resolver_conflito      tipo, codigo, acao = MANTER | UNIVERSAL
 *   POST publicar               publica o rascunho do escopo (universal → merge nas empresas)
 *   POST criar                  novo questionário (só ADMINISTRADOR)
 */

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/QuestionnaireService.php';
require_once dirname(__DIR__, 2) . '/classes/QuestionarioVersaoService.php';
require_once dirname(__DIR__, 2) . '/classes/MigracaoCrud.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$json = static function (array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
if (!PermissaoTransacao::obter('QUESTIONARIOS')['pode_visualizar']) $json(['ok' => false, 'message' => 'Sem permissão para administrar questionários.'], 403);
$env = GreenPortalDatabase::env();
$tenant = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
$universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new QuestionnaireService($tenant, $universal);
$versoes = new QuestionarioVersaoService($universal);
$escopo = QuestionarioVersaoService::escopo($_SESSION);
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'catalog')));
$csrf = (string)($_SESSION['questionarios_csrf'] ?? '');
if ($csrf === '') { $csrf = bin2hex(random_bytes(32)); $_SESSION['questionarios_csrf'] = $csrf; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => 'Token de segurança inválido.'], 419);
$userId = (int)($_SESSION['usuario']['codusuario'] ?? 0);
session_write_close();
$modulos = ['LEGAL', 'SGA', 'QUALITY', 'WORKFLOW', 'SUPPLIER', 'ESG', 'PROJECTS', 'EMPLOYEE_INTEGRATION', 'USER_PROFILE'];
$versaoDaPergunta = static function (int $codpergunta) use ($universal): int {
    return (int)(MigracaoCrud::primeiro($universal, 'amb_questionario_pergunta', ['codpergunta' => $codpergunta])['codversao'] ?? 0);
};
$escopoInfo = ['admin' => $escopo['admin'], 'codempresa' => $escopo['codempresa'], 'empresa' => $escopo['empresa'], 'pode_editar' => $escopo['pode_editar']];

try {
    if ($action === 'catalog') {
        $module = strtoupper(trim((string)($_GET['modulo'] ?? $_POST['modulo'] ?? '')));
        if ($module !== '' && !in_array($module, $modulos, true)) $json(['ok' => false, 'message' => 'Tipo de questionário inválido.'], 422);
        $rows = [];
        foreach (MigracaoCrud::selecionar($universal, 'amb_questionario', $module !== '' ? ['modulo' => $module] : []) as $q) {
            if ((int)$q['ativo'] !== 1 || ($module !== '' && strtoupper((string)$q['modulo']) !== $module)) continue;
            if ($r = $versoes->resumo($q, $escopo)) $rows[] = $r;
        }
        usort($rows, static fn($a, $b) => strcmp((string)$a['nome_pt'], (string)$b['nome_pt']));
        $json(['ok' => true, 'rows' => $rows, 'escopo' => $escopoInfo]);
    }
    if ($action === 'builder') $json(['ok' => true, 'builder' => $service->builder((int)($_GET['codversao'] ?? $_POST['codversao'] ?? 0))]);
    if ($action === 'versoes') {
        $cq = (int)($_GET['codquestionario'] ?? 0);
        $json(['ok' => true, 'rows' => array_map(static fn($v) => ['codversao' => (int)$v['codversao'], 'versao' => (int)$v['versao'], 'status' => $v['status'],
            'escopo' => (int)$v['codempresa'] === 0 ? 'UNIVERSAL' : 'EMPRESA', 'origem' => $v['origem'] ?? '', 'observacao' => $v['observacao'] ?? '',
            'criado_em' => $v['criado_em'] ?? null, 'publicado_em' => $v['publicado_em'] ?? null], $versoes->versoes($cq, (int)$escopo['codempresa']))]);
    }
    if ($action === 'nova_versao') $json(['ok' => true, 'versao' => $versoes->novaVersao((int)($_POST['codquestionario'] ?? 0), $escopo, $userId)]);
    if ($action === 'descartar') { $versoes->descartar((int)($_POST['codversao'] ?? 0), $escopo); $json(['ok' => true]); }
    if ($action === 'criar') {
        if (!$escopo['admin']) $json(['ok' => false, 'message' => 'Só o ADMINISTRADOR cria questionários no catálogo universal.'], 403);
        $json(['ok' => true, 'draft' => $service->createDraft($_POST, $userId)]);
    }
    if ($action === 'pergunta') {
        $versoes->exigirEdicao((int)($_POST['codversao'] ?? 0), $escopo);
        $id = $service->addQuestion((int)$_POST['codversao'], $_POST, $userId);
        if (isset($_POST['opcoes'])) $versoes->salvarOpcoes($id, (array)json_decode((string)$_POST['opcoes'], true), $escopo);
        $json(['ok' => true, 'codpergunta' => $id]);
    }
    if ($action === 'editar_pergunta') {
        $cp = (int)($_POST['codpergunta'] ?? 0);
        $versoes->exigirEdicao($versaoDaPergunta($cp), $escopo);
        $ok = $service->updateQuestion($cp, $_POST, $userId);
        if (isset($_POST['opcoes'])) $versoes->salvarOpcoes($cp, (array)json_decode((string)$_POST['opcoes'], true), $escopo);
        $json(['ok' => true, 'updated' => $ok]);
    }
    if ($action === 'excluir_pergunta') {
        $cp = (int)($_POST['codpergunta'] ?? 0);
        $versoes->exigirEdicao($versaoDaPergunta($cp), $escopo);
        $json(['ok' => true, 'deleted' => $service->deleteQuestion($cp, $userId)]);
    }
    if ($action === 'opcoes') {
        $versoes->salvarOpcoes((int)($_POST['codpergunta'] ?? 0), (array)json_decode((string)($_POST['opcoes'] ?? '[]'), true), $escopo);
        $json(['ok' => true]);
    }
    if ($action === 'resolver_conflito') {
        $acao = strtoupper((string)($_POST['acao'] ?? ''));
        if (!in_array($acao, ['MANTER', 'UNIVERSAL'], true)) $json(['ok' => false, 'message' => 'Ação inválida.'], 422);
        $versoes->resolverConflito((int)($_POST['codversao'] ?? 0), strtoupper((string)($_POST['tipo'] ?? '')), (string)($_POST['codigo'] ?? ''), $acao, $escopo);
        $json(['ok' => true]);
    }
    if ($action === 'publicar') $json(['ok' => true, 'publication' => $versoes->publicar((int)($_POST['codversao'] ?? 0), $escopo, $userId)]);
    $json(['ok' => false, 'message' => 'Ação não reconhecida.'], 400);
} catch (Throwable $exception) {
    $json(['ok' => false, 'message' => $exception->getMessage()], 422);
}

<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
$json = static function (array $body, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
$view = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['view'] ?? ''));
$allowedViews = ['vw_usuarios_grid','vw_unidades_grid','vw_areas_grid','vw_atividades_grid','vw_perigos_grid','vw_riscos_grid','vw_aspectos_grid','vw_impactos_grid','lais_partes_interessadas','lais_aspectossociais','lais_impactossociais','li_licencas','documentos','mm_medicao','mm_equipamento','li_status_protocolo','li_classificacao','li_frequencia'];
// Além da lista fixa, valem as fontes que um grid declarou como combo carregado sob demanda
// (ManutencaoLegalCadastroGrid registra cada uma na sessão), para que um grid novo não fique sem opções.
$allowedViews = array_merge($allowedViews, ['vw_homologacao_fornecedores_grid', 'vw_homologacao_tipos_funcionarios_grid'], array_keys((array)($_SESSION['grid_lazy_views'] ?? [])));
if (!in_array($view, $allowedViews, true)) $json(['ok' => false, 'message' => 'Fonte de opções não autorizada.'], 400);
$env = GreenPortalDatabase::env(); $pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
$filters = json_decode((string)($_GET['filters'] ?? '{}'), true); if (!is_array($filters)) $filters = [];
$search = trim((string)($_GET['busca'] ?? ''));
$rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,:busca,5000,0)', ['tabela' => $view, 'filtros' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'busca' => $search]);
// Registros provisórios criados pela migração 217 (fornecedores e funções do sistema antigo sem cadastro)
// só preservam vínculos antigos: não devem ser oferecidos para novos lançamentos.
$rows = array_values(array_filter($rows, static function (array $row): bool {
    foreach ($row as $valor) if (is_string($valor) && preg_match('/- migra\S{1,3}o 217\s*$/u', $valor)) return false;
    return true;
}));
$label = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['label'] ?? ''));
if ($label !== '') usort($rows, static fn(array $a, array $b): int => strnatcasecmp(trim((string)($a[$label] ?? '')), trim((string)($b[$label] ?? ''))));
$json(['ok' => true, 'rows' => $rows]);

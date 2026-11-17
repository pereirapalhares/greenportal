<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
$fail = static function (string $message, int $status = 400): never { http_response_code($status); echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $fail(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']), 401);

$relations = [
    'unidades' => ['title' => 'Unidades', 'table' => 'documentosxunidades', 'view' => 'vw_controle_documentos_rel_unidades', 'doc' => 'CODDOCUMENTO', 'id' => 'CODUNIDADE', 'summary' => 'UNIDADES'],
    'atividades' => ['title' => 'Atividades', 'table' => 'documentosxatividades', 'view' => 'vw_controle_documentos_rel_atividades', 'doc' => 'CODDOCUMENTO', 'id' => 'CODATIVIDADE', 'summary' => 'ATIVIDADES'],
    'aspectos' => ['title' => 'Aspectos', 'table' => 'documentosxaspectos', 'view' => 'vw_controle_documentos_rel_aspectos', 'doc' => 'CODDOCUMENTO', 'id' => 'CODASPECTO', 'summary' => 'ASPECTOS'],
    'impactos' => ['title' => 'Impactos', 'table' => 'documentosximpactos', 'view' => 'vw_controle_documentos_rel_impactos', 'doc' => 'CODDOCUMENTO', 'id' => 'CODIMPACTO', 'summary' => 'IMPACTOS'],
    'perigos' => ['title' => 'Perigos', 'table' => 'documentosxperigos', 'view' => 'vw_controle_documentos_rel_perigos', 'doc' => 'CODDOCUMENTO', 'id' => 'CODPERIGO', 'summary' => 'PERIGOS'],
    'riscos' => ['title' => 'Riscos', 'table' => 'documentosxriscos', 'view' => 'vw_controle_documentos_rel_riscos', 'doc' => 'CODDOCUMENTO', 'id' => 'CODRISCO', 'summary' => 'RISCOS'],
    'palavras_chaves' => ['title' => 'Palavras-chave', 'table' => 'documentosxpalavraschave', 'view' => 'vw_controle_documentos_rel_palavras_chaves', 'doc' => 'coddocumento', 'id' => 'codpalavrachave', 'summary' => 'PALAVRAS_CHAVES'],
    'aspectos_sociais' => ['title' => 'Aspectos sociais', 'table' => 'lais_documentosxaspectos', 'view' => 'vw_controle_documentos_rel_aspectos_sociais', 'doc' => 'CODDOCUMENTO', 'id' => 'CODASPECTOSOCIAL', 'summary' => 'ASPECTOS_SOCIAIS'],
    'impactos_sociais' => ['title' => 'Impactos sociais', 'table' => 'lais_documentosximpactos', 'view' => 'vw_controle_documentos_rel_impactos_sociais', 'doc' => 'CODDOCUMENTO', 'id' => 'CODIMPACTOSOCIAL', 'summary' => 'IMPACTOS_SOCIAIS'],
];
$key = strtolower(trim((string)($_POST['relacionamento'] ?? $_GET['relacionamento'] ?? '')));
if (!isset($relations[$key])) $fail(IdiomaService::t('modulolegal_controle.erro.relacionamento', ['pt' => 'Relacionamento inválido.', 'en' => 'Invalid relationship.', 'es' => 'Relación inválida.']));
$relation = $relations[$key];
$relation['title'] = IdiomaService::t('modulolegal_controle.botao.' . $key, $relation['title']);
$documentId = max(0, (int)($_POST['coddocumento'] ?? $_GET['coddocumento'] ?? 0));
if ($documentId < 1) $fail(IdiomaService::t('modulolegal_controle.erro.documento', ['pt' => 'Documento inválido.', 'en' => 'Invalid document.', 'es' => 'Documento inválido.']));

try {
$env = GreenPortalDatabase::env();
$tenant = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $fail(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']), 401);
$permission = PermissaoTransacao::obter('CONTROLE_DOCUMENTOS');
if (!$permission['pode_visualizar']) $fail(IdiomaService::t('modulolegal_controle.erro.ver_relacionamentos', ['pt' => 'Sem permissão para visualizar relacionamentos.', 'en' => 'You are not allowed to view relationships.', 'es' => 'Sin permiso para ver las relaciones.']), 403);
$call = static fn(string $procedure, array $params = []): array => GreenPortalDatabase::call($tenant, $procedure, $params);
$json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$documentRows = $call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'documentos', 'filtros' => $json(['CODDOCUMENTO' => $documentId])]);
if (!$documentRows) $fail(IdiomaService::t('modulolegal_controle.erro.documento_nao_localizado', ['pt' => 'Documento não localizado.', 'en' => 'Document not found.', 'es' => 'Documento no encontrado.']), 404);

$optionsRows = $call('CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', ['tabela' => $relation['view'], 'filtros' => '{}']);
$available = [];
foreach ($optionsRows as $row) $available[(int)($row['RELATION_ID'] ?? 0)] = trim((string)($row['RELATION_LABEL'] ?? ''));
$linkedRows = $call('CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', ['tabela' => $relation['table'], 'filtros' => $json([$relation['doc'] => $documentId])]);
$linked = [];
foreach ($linkedRows as $row) { $id = (int)($row[$relation['id']] ?? 0); if ($id > 0 && isset($available[$id])) $linked[$id] = true; }
$linked = array_keys($linked);
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'list')));

$response = static function (array $ids) use ($available, $relation, $permission): never {
    $summary = [];
    foreach ($ids as $id) if (isset($available[$id])) $summary[] = $available[$id];
    $options = [];
    foreach ($available as $id => $label) $options[] = ['id' => (int)$id, 'label' => $label];
    echo json_encode(['ok' => true, 'title' => $relation['title'], 'linked' => array_values($ids), 'available' => $options, 'summary' => implode('; ', $summary), 'summary_field' => $relation['summary'], 'can_edit' => (bool)$permission['pode_editar'], 'can_add' => (bool)$permission['pode_incluir'], 'can_delete' => (bool)$permission['pode_excluir']], JSON_UNESCAPED_UNICODE);
    exit;
};
if ($action === 'list') $response($linked);
if ($action !== 'sync' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $fail(IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));
$posted = json_decode((string)($_POST['relation_ids'] ?? '[]'), true);
if (!is_array($posted)) $fail(IdiomaService::t('modulolegal_controle.erro.lista_relacionamentos', ['pt' => 'Lista de relacionamentos inválida.', 'en' => 'Invalid relationship list.', 'es' => 'Lista de relaciones inválida.']));
$selected = [];
foreach ($posted as $value) { $id = (int)$value; if ($id > 0 && isset($available[$id])) $selected[$id] = true; }
$selectedIds = array_keys($selected); $current = array_fill_keys($linked, true);
if (!$permission['pode_editar'] && (!$permission['pode_incluir'] || !$permission['pode_excluir'])) $fail(IdiomaService::t('modulolegal_controle.erro.sincronizar', ['pt' => 'Sem permissão para sincronizar relacionamentos.', 'en' => 'You are not allowed to update relationships.', 'es' => 'Sin permiso para actualizar las relaciones.']), 403);
if (!$permission['pode_excluir']) foreach ($linked as $id) if (!isset($selected[$id])) $fail(IdiomaService::t('modulolegal_controle.erro.remover', ['pt' => 'Sem permissão para remover relacionamentos.', 'en' => 'You are not allowed to remove relationships.', 'es' => 'Sin permiso para quitar relaciones.']), 403);
if (!$permission['pode_incluir']) foreach ($selectedIds as $id) if (!isset($current[$id])) $fail(IdiomaService::t('modulolegal_controle.erro.incluir', ['pt' => 'Sem permissão para incluir relacionamentos.', 'en' => 'You are not allowed to add relationships.', 'es' => 'Sin permiso para agregar relaciones.']), 403);
foreach ($linked as $id) if (!isset($selected[$id])) $call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $relation['table'], 'chave' => $json([$relation['doc'] => $documentId, $relation['id'] => $id])]);
foreach ($selectedIds as $id) if (!isset($current[$id])) $call('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => $relation['table'], 'dados' => $json([$relation['doc'] => $documentId, $relation['id'] => $id])]);
$response($selectedIds);
} catch (Throwable $erro) {
    // Banco fora do ar ou aviso de procedure: mensagem no idioma do usuário.
    error_log('controle_documentos_relacionamentos: ' . $erro->getMessage());
    $fail(IdiomaService::erro($erro, 'associar'), 422);
}

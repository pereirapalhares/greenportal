<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
$fail = static function (string $message, int $status = 400): never { http_response_code($status); echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $fail(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']), 401);

$relations = [
    'treinamentos_palavras_chaves' => ['title' => IdiomaService::t('comum.relacionamentos.titulo_palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']), 'permission' => 'MODULOLEGAL_TREINAMENTOS', 'parent' => 'ml_treinamentos', 'parentField' => 'codtreinamento', 'table' => 'ml_treinamentosxpalavraschave', 'id' => 'codpalavrachave', 'view' => 'vw_modulolegal_rel_palavras_chaves', 'summary' => 'PALAVRAS_CHAVE', 'mode' => 'keywords'],
    'infraestrutura_palavras_chaves' => ['title' => IdiomaService::t('comum.relacionamentos.titulo_palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']), 'permission' => 'MODULOLEGAL_INFRAESTRUTURA', 'parent' => 'ml_infraestrutura', 'parentField' => 'codinfraestrutura', 'table' => 'ml_infraestruturaxpalavraschave', 'id' => 'codpalavrachave', 'view' => 'vw_modulolegal_rel_palavras_chaves', 'summary' => 'PALAVRAS_CHAVE', 'mode' => 'keywords'],
    'infraestrutura_atributos' => ['title' => IdiomaService::t('comum.relacionamentos.titulo_atributos', ['pt' => 'Atributos', 'en' => 'Attributes', 'es' => 'Atributos']), 'permission' => 'MODULOLEGAL_INFRAESTRUTURA', 'parent' => 'ml_infraestrutura', 'parentField' => 'codinfraestrutura', 'table' => 'ml_infraestruturaxatributos', 'id' => 'codatributo', 'view' => 'vw_modulolegal_infraestrutura_atributos', 'summary' => 'ATRIBUTOS', 'mode' => 'attributes'],
];
$key = strtolower(trim((string)($_POST['relacionamento'] ?? $_GET['relacionamento'] ?? '')));
if (!isset($relations[$key])) $fail(IdiomaService::t('api.relacionamentos.invalido', ['pt' => 'Relacionamento inválido.', 'en' => 'Invalid link.', 'es' => 'Relación inválida.']));
$relation = $relations[$key];
$parentId = max(0, (int)($_POST['registro'] ?? $_GET['registro'] ?? 0));
if ($parentId < 1) $fail(IdiomaService::t('api.registro_invalido', ['pt' => 'Registro inválido.', 'en' => 'Invalid record.', 'es' => 'Registro inválido.']));

$env = GreenPortalDatabase::env();
$tenant = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $fail(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']), 401);
$permission = PermissaoTransacao::obter($relation['permission']);
if (!$permission['pode_visualizar']) $fail(IdiomaService::t('api.relacionamentos.sem_permissao_ver', ['pt' => 'Sem permissão para visualizar relacionamentos.', 'en' => 'Not allowed to view links.', 'es' => 'Sin permiso para ver relaciones.']), 403);
$call = static fn(string $procedure, array $params = []): array => GreenPortalDatabase::call($tenant, $procedure, $params);
$json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!$call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => $relation['parent'], 'filtros' => $json([$relation['parentField'] => $parentId])])) $fail(IdiomaService::t('api.registro_nao_localizado', ['pt' => 'Registro não localizado.', 'en' => 'Record not found.', 'es' => 'Registro no encontrado.']), 404);

$available = [];
foreach ($call('CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', ['tabela' => $relation['view'], 'filtros' => '{}']) as $row) $available[(int)($row['RELATION_ID'] ?? 0)] = trim((string)($row['RELATION_LABEL'] ?? ''));
$currentRows = $call('CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', ['tabela' => $relation['table'], 'filtros' => $json([$relation['parentField'] => $parentId])]);
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'list')));

$respond = static function (array $rows) use ($available, $relation, $permission): never {
    $payloadRows = [];
    foreach ($rows as $row) {
        $id = (int)($row[$relation['id']] ?? 0);
        if ($relation['mode'] === 'attributes') $payloadRows[] = ['id' => $id, 'descricao' => (string)($row['dscatributo'] ?? ''), 'valor' => (string)($row['valoratributo'] ?? '')];
        elseif ($id > 0 && isset($available[$id])) $payloadRows[] = ['id' => $id, 'label' => $available[$id]];
    }
    echo json_encode(['ok' => true, 'title' => $relation['title'], 'mode' => $relation['mode'], 'rows' => $payloadRows, 'available' => array_map(static fn($id, $label): array => ['id' => (int)$id, 'label' => $label], array_keys($available), array_values($available)), 'summary_field' => $relation['summary'], 'can_edit' => (bool)$permission['pode_editar'], 'can_add' => (bool)$permission['pode_incluir'], 'can_delete' => (bool)$permission['pode_excluir']], JSON_UNESCAPED_UNICODE);
    exit;
};
if ($action === 'list') $respond($currentRows);
if ($action !== 'sync' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $fail(IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));

if ($relation['mode'] === 'keywords') {
    $posted = json_decode((string)($_POST['relation_ids'] ?? '[]'), true);
    if (!is_array($posted)) $fail(IdiomaService::t('api.relacionamentos.lista', ['pt' => 'Lista de relacionamentos inválida.', 'en' => 'Invalid link list.', 'es' => 'Lista de relaciones inválida.']));
    $selected = [];
    foreach ($posted as $value) { $id = (int)$value; if ($id > 0 && isset($available[$id])) $selected[$id] = true; }
    $current = []; foreach ($currentRows as $row) { $id = (int)($row[$relation['id']] ?? 0); if ($id > 0) $current[$id] = true; }
    if (!$permission['pode_editar'] && !$permission['pode_incluir'] && !$permission['pode_excluir']) $fail(IdiomaService::t('api.relacionamentos.sem_permissao', ['pt' => 'Sem permissão para alterar relacionamentos.', 'en' => 'Not allowed to change links.', 'es' => 'Sin permiso para modificar relaciones.']), 403);
    if (!$permission['pode_excluir']) foreach ($current as $id => $_) if (!isset($selected[$id])) $fail(IdiomaService::t('api.relacionamentos.sem_permissao_remover', ['pt' => 'Sem permissão para remover relacionamentos.', 'en' => 'Not allowed to remove links.', 'es' => 'Sin permiso para quitar relaciones.']), 403);
    if (!$permission['pode_incluir']) foreach ($selected as $id => $_) if (!isset($current[$id])) $fail(IdiomaService::t('api.relacionamentos.sem_permissao_incluir', ['pt' => 'Sem permissão para incluir relacionamentos.', 'en' => 'Not allowed to add links.', 'es' => 'Sin permiso para incluir relaciones.']), 403);
    foreach ($current as $id => $_) if (!isset($selected[$id])) $call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $relation['table'], 'chave' => $json([$relation['parentField'] => $parentId, $relation['id'] => $id])]);
    foreach ($selected as $id => $_) if (!isset($current[$id])) $call('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => $relation['table'], 'dados' => $json([$relation['parentField'] => $parentId, $relation['id'] => $id])]);
} else {
    $posted = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($posted)) $fail(IdiomaService::t('api.relacionamentos.atributos', ['pt' => 'Lista de atributos inválida.', 'en' => 'Invalid attribute list.', 'es' => 'Lista de atributos inválida.']));
    $current = []; foreach ($currentRows as $row) $current[(int)($row[$relation['id']] ?? 0)] = $row;
    $keep = [];
    foreach ($posted as $item) {
        if (!is_array($item)) continue;
        $id = max(0, (int)($item['id'] ?? 0)); $descricao = trim((string)($item['descricao'] ?? '')); $valor = trim((string)($item['valor'] ?? ''));
        if ($descricao === '') continue;
        if ($id > 0 && isset($current[$id])) { $keep[$id] = true; if (!$permission['pode_editar']) $fail(IdiomaService::t('api.relacionamentos.sem_permissao_alterar_atributos', ['pt' => 'Sem permissão para alterar atributos.', 'en' => 'Not allowed to change attributes.', 'es' => 'Sin permiso para modificar atributos.']), 403); $call('CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => $relation['table'], 'chave' => $json([$relation['id'] => $id]), 'dados' => $json(['dscatributo' => $descricao, 'valoratributo' => $valor])]); }
        elseif ($permission['pode_incluir']) $call('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => $relation['table'], 'dados' => $json([$relation['parentField'] => $parentId, 'dscatributo' => $descricao, 'valoratributo' => $valor])]);
        else $fail(IdiomaService::t('api.relacionamentos.sem_permissao_incluir_atributos', ['pt' => 'Sem permissão para incluir atributos.', 'en' => 'Not allowed to add attributes.', 'es' => 'Sin permiso para incluir atributos.']), 403);
    }
    if (!$permission['pode_excluir']) foreach ($current as $id => $_) if (!isset($keep[$id])) $fail(IdiomaService::t('api.relacionamentos.sem_permissao_remover_atributos', ['pt' => 'Sem permissão para remover atributos.', 'en' => 'Not allowed to remove attributes.', 'es' => 'Sin permiso para quitar atributos.']), 403);
    foreach ($current as $id => $_) if (!isset($keep[$id]) && $permission['pode_excluir']) $call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $relation['table'], 'chave' => $json([$relation['id'] => $id])]);
}
$currentRows = $call('CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', ['tabela' => $relation['table'], 'filtros' => $json([$relation['parentField'] => $parentId])]);
$respond($currentRows);

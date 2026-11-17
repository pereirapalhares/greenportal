<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit('Sessão expirada.'); }
$env = GreenPortalDatabase::env(); $pdo = GreenPortalDatabase::connect($env, (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit('Sessão expirada.'); }
if (!PermissaoTransacao::obter('HOMOLOGACAO_FORNECEDOR_FORNECEDOR')['pode_visualizar']) { http_response_code(403); exit('Sem permissão para consultar responsabilidades.'); }
$contextFilters = json_decode((string)($_GET['filtros'] ?? '{}'), true);
if (!is_array($contextFilters)) {
    $contextFilters = [];
}
$id = filter_input(INPUT_GET, 'CODFORNECEDOR', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id && isset($contextFilters['CODFORNECEDOR'])) {
    $id = filter_var($contextFilters['CODFORNECEDOR'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}
if (!$id) { http_response_code(400); exit('Fornecedor inválido.'); }
// Migração 296: o fornecedor externo só consulta os fornecedores ligados a ele.
HomologacaoFornecedorAcesso::exigirFornecedor($pdo, (int)$id);
$json = static fn(array $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_responsabilidade_fornecedores', 'filtros' => $json(['CODFORNECEDOR' => $id])]);
$supplier = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_homologacao_fornecedores_grid', 'filtros' => $json(['CODFORNECEDOR' => $id])])[0] ?? [];
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Responsabilidades do fornecedor</title><link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=1"><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head><body class="p-3 bg-light"><main class="container-fluid">
<div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><h4 class="mb-1"><i class="bi bi-people me-2"></i>Responsabilidades do fornecedor</h4><div class="text-muted small">Fornecedor <?= $e($id) ?> — <?= $e($supplier['RAZAOSOCIAL'] ?? 'Não localizado') ?></div></div><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" data-testid="toggle-manter" href="fornecedor_responsabilidades_edicao.php?CODFORNECEDOR=<?= $e($id) ?>"><i class="bi bi-pencil-square me-1"></i>Manter</a><span class="badge text-bg-secondary align-self-center">Consulta</span></div></div>
<?php if (!$supplier): ?><div class="alert alert-warning">O fornecedor não foi localizado.</div><?php elseif (!$rows): ?><div class="alert alert-info">Não há responsabilidades cadastradas para este fornecedor.</div><?php else: ?><div class="table-responsive border rounded bg-white"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Finalidade</th><th>Responsável</th><th>Destino</th><th>Origem</th><th>Entidade</th><th>Situação</th><th>Validade</th></tr></thead><tbody>
<?php foreach ($rows as $row): $active = (string)$row['SITUACAO'] === 'ATIVA'; ?><tr data-testid="responsibility-consulta-row" data-situacao="<?= $e($row['SITUACAO']) ?>"><td><?= $e($row['TIPO_NOME'] ?: 'Responsabilidade do fornecedor') ?></td><td><?= $e($row['RESPONSAVEL_EXIBICAO']) ?></td><td><?= $e($row['TIPO_DESTINO'] === 'GRUPO' ? 'Grupo' : 'Usuário') ?></td><td><?= $e($row['ORIGEM']) ?></td><td><?= $e($row['ENTIDADE']) ?></td><td><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $e($row['SITUACAO']) ?></span></td><td><?= $e($row['VALIDO_DE']) ?><?= $row['VALIDO_ATE'] ? ' a ' . $e($row['VALIDO_ATE']) : ' em diante' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></main></body></html>

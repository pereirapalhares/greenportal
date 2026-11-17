<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Textos em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');
$sessaoExpirada = IdiomaService::t('licenca_responsabilidades.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']);
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) {
    http_response_code(401);
    exit($sessaoExpirada);
}

$env = GreenPortalDatabase::env();
$pdo = TransactionSecurity::connect('LICENCAS');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) {
    http_response_code(401);
    exit($sessaoExpirada);
}
if (!PermissaoTransacao::obter('LICENCAS')['pode_visualizar']) {
    http_response_code(403);
    exit(IdiomaService::t('licenca_responsabilidades.erro.sem_permissao', ['pt' => 'Sem permissão para consultar responsabilidades.', 'en' => 'Not allowed to view responsibilities.', 'es' => 'Sin permiso para consultar responsabilidades.']));
}

$licenseId = filter_input(INPUT_GET, 'CODLICENCA', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$licenseId) {
    http_response_code(400);
    exit(IdiomaService::t('licenca_responsabilidades.erro.licenca_invalida', ['pt' => 'Licença inválida.', 'en' => 'Invalid license.', 'es' => 'Licencia inválida.']));
}

$call = static function (PDO $connection, string $procedure, array $params): array {
    return GreenPortalDatabase::call($connection, $procedure, $params);
};
$json = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$license = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
    'tabela' => 'vw_licencas_grid', 'filtros' => $json(['CODLICENCA' => $licenseId]),
])[0] ?? [];
$rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', [
    'tabela' => 'vw_responsabilidade_licencas', 'filtros' => $json(['CODLICENCA' => $licenseId]),
]);
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $tt('licenca_responsabilidades.titulo_pagina', ['pt' => 'Responsabilidades da licença', 'en' => 'License responsibilities', 'es' => 'Responsabilidades de la licencia']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=1"><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="p-3 bg-light"><main class="container-fluid">
<div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><h4 class="mb-1"><i class="bi bi-people me-2"></i><?= $tt('licenca_responsabilidades.titulo', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']) ?></h4><div class="text-muted small"><?= $tt('licenca_responsabilidades.licenca', ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia']) ?> <?= $e($licenseId) ?> — <?= $e($license['DSCLICENCA'] ?? IdiomaService::t('licenca_responsabilidades.nao_localizada', ['pt' => 'Não localizada', 'en' => 'Not found', 'es' => 'No encontrada'])) ?></div></div><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" data-testid="toggle-manter" href="licenca_responsabilidades_edicao.php?CODLICENCA=<?= $e($licenseId) ?>"><i class="bi bi-pencil-square me-1"></i><?= $tt('licenca_responsabilidades.botao.manter', ['pt' => 'Manter', 'en' => 'Edit', 'es' => 'Mantener']) ?></a><span class="badge text-bg-secondary align-self-center"><?= $tt('licenca_responsabilidades.modo.consulta', ['pt' => 'Consulta', 'en' => 'View only', 'es' => 'Consulta']) ?></span></div></div>
<?php if (!$license): ?><div class="alert alert-warning"><?= $tt('licenca_responsabilidades.msg.licenca_nao_localizada', ['pt' => 'A licença não foi localizada no cadastro atual.', 'en' => 'The license was not found in the current records.', 'es' => 'La licencia no fue encontrada en el registro actual.']) ?></div><?php elseif (!$rows): ?><div class="alert alert-info"><?= $tt('licenca_responsabilidades.msg.sem_responsabilidades', ['pt' => 'Não há responsabilidades cadastradas para esta licença.', 'en' => 'There are no responsibilities registered for this license.', 'es' => 'No hay responsabilidades registradas para esta licencia.']) ?></div><?php else: ?>
<div class="table-responsive border rounded bg-white"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th><?= $tt('licenca_responsabilidades.coluna.finalidade', ['pt' => 'Finalidade', 'en' => 'Purpose', 'es' => 'Finalidad']) ?></th><th><?= $tt('licenca_responsabilidades.coluna.responsavel', ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable']) ?></th><th><?= $tt('licenca_responsabilidades.coluna.destino', ['pt' => 'Destino', 'en' => 'Assigned to', 'es' => 'Destino']) ?></th><th><?= $tt('licenca_responsabilidades.coluna.origem', ['pt' => 'Origem', 'en' => 'Origin', 'es' => 'Origen']) ?></th><th><?= $tt('licenca_responsabilidades.coluna.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']) ?></th><th><?= $tt('licenca_responsabilidades.coluna.validade', ['pt' => 'Validade', 'en' => 'Validity', 'es' => 'Validez']) ?></th></tr></thead><tbody>
<?php foreach ($rows as $row): $active = (string)($row['SITUACAO'] ?? '') === 'ATIVA'; $destination = (string)($row['TIPO_DESTINO'] ?? '') === 'GRUPO' ? IdiomaService::t('licenca_responsabilidades.destino.grupo', ['pt' => 'Grupo', 'en' => 'Group', 'es' => 'Grupo']) : IdiomaService::t('licenca_responsabilidades.destino.usuario', ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario']); $groupCount = (int)($row['USUARIOS_ATIVOS_GRUPO'] ?? 0); ?>
<tr data-testid="responsibility-consulta-row" data-situacao="<?= $e($row['SITUACAO']) ?>"><td><strong><?= $e($row['TIPO_NOME'] ?: $row['CATEGORIA_NOME'] ?: IdiomaService::t('licenca_responsabilidades.responsabilidade_direta', ['pt' => 'Responsabilidade direta', 'en' => 'Direct responsibility', 'es' => 'Responsabilidad directa'])) ?></strong><?php if (!empty($row['CATEGORIA_NOME'])): ?><div class="small text-muted"><?= $e($row['CATEGORIA_NOME']) ?></div><?php endif; ?></td><td><?= $e($row['RESPONSAVEL_EXIBICAO']) ?><?php if ($groupCount > 0): ?><div class="small text-muted"><?= $e($groupCount) ?> <?= $tt('licenca_responsabilidades.usuarios_ativos', ['pt' => 'usuário(s) ativo(s)', 'en' => 'active user(s)', 'es' => 'usuario(s) activo(s)']) ?></div><?php endif; ?></td><td><?= $e($destination) ?></td><td><?= $e($row['ORIGEM']) ?></td><td><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $e($row['SITUACAO']) ?></span></td><td class="text-nowrap"><?= $e($row['VALIDO_DE']) ?><?= !empty($row['VALIDO_ATE']) ? ' ' . IdiomaService::t('licenca_responsabilidades.validade.ate', ['pt' => 'a', 'en' => 'to', 'es' => 'a']) . ' ' . $e($row['VALIDO_ATE']) : ' ' . IdiomaService::t('licenca_responsabilidades.validade.em_diante', ['pt' => 'em diante', 'en' => 'onwards', 'es' => 'en adelante']) ?></td></tr>
<?php endforeach; ?></tbody></table></div><div class="small text-muted mt-2"><?= $tt('licenca_responsabilidades.msg.historico', ['pt' => 'Responsabilidades históricas são mantidas para rastreabilidade e não representam atribuições ativas.', 'en' => 'Past responsibilities are kept for traceability and are not active assignments.', 'es' => 'Las responsabilidades históricas se mantienen para trazabilidad y no representan asignaciones activas.']) ?></div>
<?php endif; ?></main></body></html>

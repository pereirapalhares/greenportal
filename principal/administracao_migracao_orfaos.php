<?php
declare(strict_types=1);
session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessão expirada.'); }
if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR') { http_response_code(403); exit('Acesso restrito.'); }
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$env = GreenPortalDatabase::env();
$banco = strtolower((string)($_SESSION['tenant']['banco_dados'] ?? ''));
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) { http_response_code(400); exit('Banco da empresa inválido.'); }
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$tituloIntegridade = 'Integridade da migração — ' . strtoupper($banco);
$msg = ''; $erro = ''; $usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $dados = []; $acao = strtoupper(trim((string)($_POST['acao'] ?? 'JUSTIFICAR')));
        if ($acao === 'VINCULAR_LICENCA') $dados = ['CODLICENCA' => (int)($_POST['valor'] ?? 0)];
        if ($acao === 'ATRIBUIR_RESPONSAVEL') $dados = ['CODUSUARIO' => (int)($_POST['valor'] ?? 0)];
        AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_migracao_orfao_decidir', [
            (string)$_POST['tipo'], (int)$_POST['registro_id'], $acao,
            json_encode($dados, JSON_UNESCAPED_UNICODE), trim((string)$_POST['justificativa']), $usuario,
        ]);
        $msg = 'Decisão registrada e vínculo atualizado quando aplicável.';
    } catch (Throwable $x) { $erro = $x->getMessage(); }
}
$tipo = trim((string)($_GET['tipo'] ?? ''));
$rows = [];
try { $rows = AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_crud_select', ['vw_migracao_orfaos_integridade', $tipo !== '' ? json_encode(['tipo' => $tipo], JSON_UNESCAPED_UNICODE) : '{}', null, 200, 0]); } catch (Throwable $x) { $erro = $erro ?: $x->getMessage(); }
$licencas = []; $users = [];
try {
    $licencas = AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_crud_select', ['li_licencas', '{}', null, 1000, 0]);
    $users = AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_crud_select', ['vw_usuarios_grid', json_encode(['ativo' => 1], JSON_UNESCAPED_UNICODE), null, 1000, 0]);
} catch (Throwable $x) { $erro = $erro ?: $x->getMessage(); }
?><!doctype html>
<script>document.addEventListener('DOMContentLoaded',function(){var titulo=<?= json_encode($tituloIntegridade, JSON_UNESCAPED_UNICODE) ?>;document.title=titulo;var h=document.querySelector('h4');if(h)h.textContent=titulo;});</script>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Integridade da migração — Crown</title><?php greenPortalEstiloPagina(); ?><style>body{padding:18px}.table-wrap{overflow:auto;max-height:65vh}.decision{min-width:300px}.decision .form-control,.decision .form-select{max-width:220px}</style></head>
<body><main>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h4 class="mb-1">Integridade da migração — Crown</h4><p class="text-muted mb-0">Corrija ou justifique vínculos históricos antes da homologação final.</p></div><a class="btn btn-outline-secondary btn-sm" href="administracao_migracao_empresas.php"><i class="bi bi-arrow-left"></i> Voltar</a></div>
<?php if ($msg): ?><div class="alert alert-success"><?= $e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><?= $e($erro) ?></div><?php endif; ?>
<div class="card"><div class="card-body"><div class="d-flex gap-2 mb-3"><a class="btn btn-sm <?= $tipo === '' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?">Todos</a><a class="btn btn-sm <?= $tipo === 'CONDICIONANTE_SEM_LICENCA' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?tipo=CONDICIONANTE_SEM_LICENCA">Sem licença</a><a class="btn btn-sm <?= $tipo === 'RESPONSAVEL_INEXISTENTE' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?tipo=RESPONSAVEL_INEXISTENTE">Responsável inexistente</a><span class="badge text-bg-secondary align-self-center"><?= count($rows) ?> exibidos</span></div>
<div class="table-wrap"><table class="table table-sm align-middle"><thead><tr><th>Tipo</th><th>Registro</th><th>Referência</th><th>Descrição</th><th>Decisão</th><th>Ação administrativa</th></tr></thead><tbody>
<?php foreach ($rows as $row): $tipoRow = (string)($row['tipo'] ?? ''); $id = (int)($row['registro_id'] ?? 0); $isLicense = $tipoRow === 'CONDICIONANTE_SEM_LICENCA'; ?><tr><td><?= $e($tipoRow) ?></td><td><?= $id ?></td><td><?= $e($row['referencia_id'] ?? '') ?></td><td style="min-width:280px"><?= $e($row['descricao'] ?? '') ?></td><td><?= $e($row['decisao'] ?? 'PENDENTE') ?></td><td><form method="post" class="decision d-flex flex-wrap gap-1"><input type="hidden" name="tipo" value="<?= $e($tipoRow) ?>"><input type="hidden" name="registro_id" value="<?= $id ?>"><select name="acao" class="form-select form-select-sm" onchange="this.form.querySelector('[name=valor]').disabled=!['VINCULAR_LICENCA','ATRIBUIR_RESPONSAVEL'].includes(this.value)"><option value="JUSTIFICAR">Justificar</option><option value="INATIVAR">Inativar/encerrar</option><?php if ($isLicense): ?><option value="VINCULAR_LICENCA">Vincular licença válida</option><?php else: ?><option value="ATRIBUIR_RESPONSAVEL">Atribuir responsável ativo</option><option value="REMOVER_RESPONSAVEL">Remover responsável</option><?php endif; ?></select><?php if ($isLicense): ?><select name="valor" class="form-select form-select-sm" disabled><option value="">Licença válida</option><?php foreach ($licencas as $license): ?><option value="<?= $e($license['CODLICENCA'] ?? $license['codlicenca'] ?? '') ?>"><?= $e($license['DSCLICENCA'] ?? $license['dscl icenca'] ?? '') ?></option><?php endforeach; ?></select><?php else: ?><select name="valor" class="form-select form-select-sm" disabled><option value="">Usuário ativo</option><?php foreach ($users as $user): ?><option value="<?= $e($user['codusuario'] ?? $user['CODUSUARIO'] ?? '') ?>"><?= $e($user['nome'] ?? $user['NOME'] ?? $user['userid'] ?? '') ?></option><?php endforeach; ?></select><?php endif; ?><input name="justificativa" required class="form-control form-control-sm" placeholder="Justificativa"><button class="btn btn-sm btn-success" title="Registrar decisão"><i class="bi bi-check2"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
</main></body></html>

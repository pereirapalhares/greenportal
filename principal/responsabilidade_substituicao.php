<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/ResponsibilityService.php';

$transaction = 'RESPONSABILIDADE_SUBSTITUICAO';
$pdo = TransactionSecurity::connect($transaction);
$service = new ResponsibilityService($pdo, new TaskService($pdo));
$permission = PermissaoTransacao::obter($transaction);
$userId = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$message = '';
$error = '';

try {
    $action = (string)($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'request') {
            if (empty($permission['pode_incluir'])) throw new RuntimeException('Sem permissão para solicitar substituição.');
            $id = $service->createSubstitution([
                'CODATRIBUICAO' => (int)($_POST['CODATRIBUICAO'] ?? 0),
                'CODMODULO' => (int)($_POST['CODMODULO'] ?? 0),
                'TIPO_DESTINO' => (string)($_POST['TIPO_DESTINO'] ?? 'USUARIO'),
                'CODUSUARIO' => $_POST['CODUSUARIO'] ?? null,
                'CODGRUPO' => $_POST['CODGRUPO'] ?? null,
                'VALIDO_DE' => $_POST['VALIDO_DE'] ?? date('Y-m-d'),
                'VALIDO_ATE' => $_POST['VALIDO_ATE'] ?? null,
                'MOTIVO' => $_POST['MOTIVO'] ?? '',
            ], $userId);
            $message = 'Substituição solicitada com sucesso. Código: ' . $id . '.';
        } elseif ($action === 'approve') {
            if (empty($permission['pode_editar'])) throw new RuntimeException('Sem permissão para aprovar substituição.');
            $service->approveSubstitution((int)($_POST['CODSUBSTITUICAO'] ?? 0), $userId);
            $message = 'Substituição aprovada.';
        } elseif ($action === 'revoke') {
            if (empty($permission['pode_editar'])) throw new RuntimeException('Sem permissão para revogar substituição.');
            $service->revokeSubstitution((int)($_POST['CODSUBSTITUICAO'] ?? 0), $userId);
            $message = 'Substituição revogada.';
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$attributions = $pdo->query(
    "SELECT CODATRIBUICAO,CODMODULO,MODULO_NOME,MODULO_CODIGO,ENTIDADE,REGISTRO_ID,TIPO_DESTINO,
            CODUSUARIO,CODGRUPO,PRIORIDADE,PRINCIPAL,VALIDO_DE,VALIDO_ATE,
            COALESCE(TIPO_NOME, CATEGORIA_NOME, ORIGEM) AS REFERENCIA
       FROM vw_responsabilidade_atribuicoes_atuais
      ORDER BY MODULO_NOME, ENTIDADE, REGISTRO_ID, CODATRIBUICAO
      LIMIT 1000"
)->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT codusuario,userid,nome FROM usuarios WHERE ativo=1 ORDER BY nome,userid LIMIT 10000")->fetchAll(PDO::FETCH_ASSOC);
$groups = $pdo->query("SELECT codgrupo,nome FROM grupos WHERE ativo=1 ORDER BY nome LIMIT 10000")->fetchAll(PDO::FETCH_ASSOC);
$substitutions = $pdo->query(
    "SELECT s.*,m.NOME AS MODULO_NOME,a.ENTIDADE,a.REGISTRO_ID,
            COALESCE(u.nome,u.userid,g.nome,CONCAT(s.TIPO_DESTINO,' ',s.CODUSUARIO,s.CODGRUPO)) AS SUBSTITUTO,
            sol.nome AS SOLICITANTE, apr.nome AS APROVADOR
       FROM responsabilidade_substituicoes s
       JOIN responsabilidade_modulos m ON m.CODMODULO=s.CODMODULO
       JOIN responsabilidade_atribuicoes a ON a.CODATRIBUICAO=s.CODATRIBUICAO
       LEFT JOIN usuarios u ON u.codusuario=s.CODUSUARIO
       LEFT JOIN grupos g ON g.codgrupo=s.CODGRUPO
       JOIN usuarios sol ON sol.codusuario=s.CODUSUARIOSOLICITANTE
       LEFT JOIN usuarios apr ON apr.codusuario=s.CODUSUARIOAPROVADOR
      ORDER BY s.CRIADO_EM DESC, s.CODSUBSTITUICAO DESC
      LIMIT 1000"
)->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Substituições de responsabilidades</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
    <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2">
    <link rel="stylesheet" href="../assets/css/app.css?v=1">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="p-3 bg-light"><main class="container-fluid">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div><h4 class="mb-1"><i class="bi bi-person-arrows me-2"></i>Substituições de responsabilidades</h4><div class="text-muted small">Solicitação, aprovação e revogação com vigência e auditoria.</div></div>
        <span class="badge text-bg-secondary align-self-center">GreenSolutions 4.0.1</span>
    </div>
    <?php if ($message !== ''): ?><div class="alert alert-success py-2"><?= $e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger py-2"><?= $e($error) ?></div><?php endif; ?>
    <?php if (!empty($permission['pode_incluir'])): ?>
    <section class="card shadow-sm mb-3"><div class="card-body">
        <h6 class="mb-3">Solicitar substituição</h6>
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="request"><input type="hidden" name="CODMODULO" id="substitutionModule" value="">
            <div class="col-md-5"><label class="form-label small">Responsabilidade</label><select class="form-select form-select-sm" name="CODATRIBUICAO" required><option value="">Selecione</option><?php foreach ($attributions as $row): ?><option value="<?= $e($row['CODATRIBUICAO']) ?>" data-module="<?= $e($row['CODMODULO']) ?>"><?= $e($row['MODULO_NOME'].' — '.$row['ENTIDADE'].' / '.$row['REGISTRO_ID'].' — '.$row['REFERENCIA']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small">Destino</label><select class="form-select form-select-sm" name="TIPO_DESTINO" id="substitutionDestination"><option value="USUARIO">Usuário</option><option value="GRUPO">Grupo</option></select></div>
            <div class="col-md-5"><label class="form-label small">Substituto</label><select class="form-select form-select-sm" name="CODUSUARIO" id="substitutionUser"><option value="">Selecione</option><?php foreach ($users as $row): ?><option value="<?= $e($row['codusuario']) ?>"><?= $e(($row['nome'] ?: $row['userid']).' — '.$row['userid']) ?></option><?php endforeach; ?></select><select class="form-select form-select-sm d-none" name="CODGRUPO" id="substitutionGroup"><option value="">Selecione</option><?php foreach ($groups as $row): ?><option value="<?= $e($row['codgrupo']) ?>"><?= $e($row['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small">Início</label><input class="form-control form-control-sm" type="date" name="VALIDO_DE" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-2"><label class="form-label small">Fim</label><input class="form-control form-control-sm" type="date" name="VALIDO_ATE"></div>
            <div class="col-md-6"><label class="form-label small">Motivo</label><input class="form-control form-control-sm" name="MOTIVO" maxlength="1000" required></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-send me-1"></i>Solicitar</button></div>
        </form>
    </div></section>
    <?php endif; ?>
    <section class="card shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Solicitações</h6><button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()" title="Recarregar"><i class="bi bi-arrow-clockwise"></i></button></div><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Código</th><th>Módulo</th><th>Registro</th><th>Substituto</th><th>Vigência</th><th>Status</th><th>Motivo</th><th>Ações</th></tr></thead><tbody><?php foreach ($substitutions as $row): ?><tr><td><?= $e($row['CODSUBSTITUICAO']) ?></td><td><?= $e($row['MODULO_NOME']) ?></td><td><?= $e($row['ENTIDADE'].' / '.$row['REGISTRO_ID']) ?></td><td><?= $e($row['SUBSTITUTO']) ?></td><td><?= $e($row['VALIDO_DE'].' a '.($row['VALIDO_ATE'] ?: 'indefinida')) ?></td><td><span class="badge text-bg-<?= $row['STATUS']==='ATIVA'?'success':($row['STATUS']==='PENDENTE'?'warning':'secondary') ?>"><?= $e($row['STATUS']) ?></span></td><td><?= $e($row['MOTIVO']) ?></td><td><?php if ($row['STATUS']==='PENDENTE' && !empty($permission['pode_editar'])): ?><form method="post" class="d-inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="CODSUBSTITUICAO" value="<?= $e($row['CODSUBSTITUICAO']) ?>"><button class="btn btn-sm btn-outline-success" title="Aprovar"><i class="bi bi-check2"></i></button></form><?php endif; ?><?php if ($row['STATUS']==='ATIVA' && !empty($permission['pode_editar'])): ?><form method="post" class="d-inline"><input type="hidden" name="action" value="revoke"><input type="hidden" name="CODSUBSTITUICAO" value="<?= $e($row['CODSUBSTITUICAO']) ?>"><button class="btn btn-sm btn-outline-danger" title="Revogar"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></td></tr><?php endforeach; if (!$substitutions): ?><tr><td colspan="8" class="text-muted">Nenhuma solicitação encontrada.</td></tr><?php endif; ?></tbody></table></div></div></section>
</main><script>document.addEventListener('DOMContentLoaded',function(){var d=document.getElementById('substitutionDestination'),u=document.getElementById('substitutionUser'),g=document.getElementById('substitutionGroup'),a=document.querySelector('[name="CODATRIBUICAO"]'),m=document.getElementById('substitutionModule');function toggle(){var group=d.value==='GRUPO';u.classList.toggle('d-none',group);g.classList.toggle('d-none',!group);u.disabled=group;g.disabled=!group;}function module(){var option=a.options[a.selectedIndex];m.value=option?option.dataset.module||'':'';}d?.addEventListener('change',toggle);a?.addEventListener('change',module);toggle();module();});</script></body></html>

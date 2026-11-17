<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) {
    http_response_code(401);
    exit('Sessão expirada.');
}

$pdo = TransactionSecurity::connect('CONTROLE_DOCUMENTOS');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) {
    http_response_code(401);
    exit('Sessão expirada.');
}

$permission = PermissaoTransacao::obter('CONTROLE_DOCUMENTOS');
if (!$permission['pode_visualizar']) {
    http_response_code(403);
    exit('Sem permissão para consultar responsabilidades.');
}

$filters = json_decode((string)($_GET['filtros'] ?? '{}'), true) ?: [];
$id = filter_var($filters['CODDOCUMENTO'] ?? $_GET['CODDOCUMENTO'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit('Controle inválido.');
}

$json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$call = static fn(string $sql, array $params = []): array => GreenPortalDatabase::call($pdo, $sql, $params);

$types = $call('CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', [
    'tabela' => 'vw_responsabilidade_tipos',
    'filtros' => $json(['MODULO_CODIGO' => 'CONTROLE', 'ATIVO' => 1]),
]);
$users = $call('CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', [
    'tabela' => 'vw_responsabilidade_usuarios_ativos',
    'filtros' => '{}',
]);
$groups = $call('CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', [
    'tabela' => 'vw_responsabilidade_grupos_ativos',
    'filtros' => '{}',
]);
$context = $call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
    'tabela' => 'vw_controle_documentos_grid',
    'filtros' => $json(['CODDOCUMENTO' => $id]),
])[0] ?? [];
if (!$context) {
    http_response_code(404);
    exit('Controle não localizado.');
}

$moduleId = (int)($types[0]['CODMODULO'] ?? 0);
$message = '';
$error = '';
$canWrite = !empty($permission['pode_incluir']) || !empty($permission['pode_editar']) || !empty($permission['pode_excluir']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rows') {
    if (!$canWrite) {
        http_response_code(403);
        exit('Sem permissão para alterar responsabilidades.');
    }
    try {
        $existing = $call('CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', [
            'tabela' => 'vw_responsabilidade_controle_documentos',
            'filtros' => $json(['CODDOCUMENTO' => $id, 'ATIVO' => 1]),
        ]);
        $postedKeys = array_map('strval', array_keys((array)($_POST['rows'] ?? [])));
        foreach ($existing as $old) {
            if (!in_array((string)$old['CODATRIBUICAO'], $postedKeys, true) && !empty($permission['pode_excluir'])) {
                $call('CALL sp_crud_update(:tabela,:chave,:dados)', [
                    'tabela' => 'responsabilidade_atribuicoes',
                    'chave' => $json(['CODATRIBUICAO' => (int)$old['CODATRIBUICAO']]),
                    'dados' => $json(['ATIVO' => 0, 'CODUSUARIOALTERACAO' => (int)$_SESSION['usuario']['codusuario']]),
                ]);
            }
        }
        $validTypes = array_map('intval', array_column($types, 'CODTIPORESPONSABILIDADE'));
        foreach ((array)($_POST['rows'] ?? []) as $key => $row) {
            $assignmentId = ctype_digit((string)$key) ? (int)$key : 0;
            if (!empty($row['_delete'])) {
                if ($assignmentId && !empty($permission['pode_excluir'])) {
                    $call('CALL sp_crud_update(:tabela,:chave,:dados)', [
                        'tabela' => 'responsabilidade_atribuicoes',
                        'chave' => $json(['CODATRIBUICAO' => $assignmentId]),
                        'dados' => $json(['ATIVO' => 0, 'CODUSUARIOALTERACAO' => (int)$_SESSION['usuario']['codusuario']]),
                    ]);
                }
                continue;
            }
            $destination = strtoupper(trim((string)($row['TIPO_DESTINO'] ?? '')));
            $type = (int)($row['CODTIPORESPONSABILIDADE'] ?? 0);
            $userId = $destination === 'USUARIO' ? max(0, (int)($row['CODUSUARIO'] ?? 0)) : null;
            $groupId = $destination === 'GRUPO' ? max(0, (int)($row['CODGRUPO'] ?? 0)) : null;
            if (!$type || !in_array($type, $validTypes, true) || !in_array($destination, ['USUARIO', 'GRUPO'], true) || ($destination === 'USUARIO' && !$userId) || ($destination === 'GRUPO' && !$groupId)) {
                throw new RuntimeException('Preencha finalidade e destino em todas as linhas.');
            }
            $from = trim((string)($row['VALIDO_DE'] ?? '')) ?: date('Y-m-d');
            $to = trim((string)($row['VALIDO_ATE'] ?? '')) ?: null;
            $duplicateParams = [
                'atribuicao' => $assignmentId ?: null, 'modulo' => $moduleId, 'origem' => 'DIRETA',
                'tipo' => $type, 'categoria' => null, 'entidade' => 'documentos', 'registro' => (string)$id,
                'destino' => $destination, 'usuario' => $userId, 'grupo' => $groupId, 'de' => $from, 'ate' => $to,
            ];
            $call('CALL sp_responsabilidade_validar_duplicidade(:atribuicao,:modulo,:origem,:tipo,:categoria,:entidade,:registro,:destino,:usuario,:grupo,:de,:ate)', $duplicateParams);
            $data = [
                'CODMODULO' => $moduleId, 'ORIGEM' => 'DIRETA', 'CODTIPORESPONSABILIDADE' => $type,
                'ENTIDADE' => 'documentos', 'REGISTRO_ID' => (string)$id, 'TIPO_DESTINO' => $destination,
                'CODUSUARIO' => $userId, 'CODGRUPO' => $groupId, 'PRIORIDADE' => (int)($row['PRIORIDADE'] ?? 0),
                'PRINCIPAL' => !empty($row['PRINCIPAL']) ? 1 : 0, 'VALIDO_DE' => $from, 'VALIDO_ATE' => $to,
                'OBSERVACAO' => trim((string)($row['OBSERVACAO'] ?? '')), 'ATIVO' => 1,
                'CODUSUARIOALTERACAO' => (int)$_SESSION['usuario']['codusuario'],
            ];
            if ($assignmentId && !empty($permission['pode_editar'])) {
                $call('CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'responsabilidade_atribuicoes', 'chave' => $json(['CODATRIBUICAO' => $assignmentId]), 'dados' => $json($data)]);
            } elseif (!$assignmentId && !empty($permission['pode_incluir'])) {
                $call('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'responsabilidade_atribuicoes', 'dados' => $json($data)]);
            }
        }
        $message = 'Responsabilidades salvas com sucesso.';
    } catch (Throwable $exception) {
        $error = 'Não foi possível salvar as responsabilidades. Verifique os dados informados.';
    }
}

$rows = $call('CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', [
    'tabela' => 'vw_responsabilidade_controle_documentos',
    'filtros' => $json(['CODDOCUMENTO' => $id, 'ATIVO' => 1]),
]);
$permissionJs = json_encode(['include' => !empty($permission['pode_incluir']), 'edit' => !empty($permission['pode_editar']), 'delete' => !empty($permission['pode_excluir'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Responsabilidades do controle</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
    <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2">
    <link rel="stylesheet" href="../assets/css/app.css?v=1">
    <link rel="stylesheet" href="../assets/css/main.css?v=18">
    <link rel="stylesheet" href="../assets/css/botoes-crud.css?v=2">
    <style>
        html, body { height:100%; }
        body.responsibility-page { overflow:hidden; }
        .responsibility-shell { height:100%; min-height:0; display:flex; flex-direction:column; }
        .responsibility-form { min-height:0; flex:1 1 auto; display:flex; flex-direction:column; }
        .responsibility-form .menu-grid-scroll { min-height:0; flex:1 1 auto; overflow:auto !important; }
        .responsibility-form .menu-batch-grid { width:max-content; min-width:100%; table-layout:fixed; }
        .responsibility-form .menu-batch-grid th { white-space:nowrap; }
        .responsibility-form .menu-batch-grid td { min-width:150px; }
        .responsibility-form .menu-batch-grid td:nth-child(1) { min-width:190px; }
        .responsibility-form .menu-batch-grid td:nth-child(3) { min-width:280px; }
        .responsibility-form .menu-batch-grid td:nth-child(5) { min-width:84px; width:84px; }
        .responsibility-form .menu-batch-grid tr.selected td { background:var(--gp-primary-soft); }
        .responsibility-form .grid-input { border-color:var(--gp-border); background:#fff; }
        .responsibility-form .grid-input:focus { border-color:var(--gp-accent); }
        .responsibility-empty { padding:32px 16px; text-align:center; color:var(--gp-muted); }
    </style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="p-3 bg-light responsibility-page">
<main class="container-fluid responsibility-shell">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-people me-2"></i>Responsabilidades do controle</h4>
            <div class="text-muted small">Controle <?= $escape($context['CODIGO'] ?? $id) ?> — <?= $escape($context['TITULO'] ?? 'Não localizado') ?></div>
        </div>
        <span class="badge text-bg-secondary align-self-center">Manutenção</span>
    </div>
    <?php if ($message): ?><div class="alert alert-success py-2"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= $escape($error) ?></div><?php endif; ?>
    <?php if (!$canWrite): ?><div class="alert alert-warning">Seu perfil não possui permissão para alterar responsabilidades.</div><?php endif; ?>
    <form method="post" id="responsibilityForm" class="responsibility-form">
        <input type="hidden" name="action" value="save_rows">
        <div class="crud-toolbar menu-grid-toolbar mb-2">
            <div class="crud-actions crud-actions-standard">
                <button type="button" class="btn btn-sm btn-outline-primary grid-icon-button" id="addRow" title="Incluir" aria-label="Incluir"><i class="bi bi-plus-lg"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger grid-icon-button" id="deleteRow" disabled title="Excluir" aria-label="Excluir"><i class="bi bi-trash"></i></button>
                <button type="submit" class="btn btn-sm btn-success grid-icon-button" title="Confirmar" aria-label="Confirmar"><i class="bi bi-check2"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary grid-icon-button" onclick="window.close()" title="Cancelar" aria-label="Cancelar"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="menu-grid-scroll">
            <table class="table table-sm table-hover align-middle menu-batch-grid mb-0" id="responsibilityTable">
                <colgroup><col style="width:190px"><col style="width:130px"><col style="width:280px"><col style="width:100px"><col style="width:85px"><col style="width:140px"><col style="width:140px"></colgroup>
                <thead class="table-light"><tr><th>Finalidade</th><th>Destino</th><th>Responsável</th><th>Prioridade</th><th class="text-center">Principal</th><th>Vigência inicial</th><th>Vigência final</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="7" class="responsibility-empty">Nenhuma responsabilidade cadastrada para este controle.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): $key = (int)$row['CODATRIBUICAO']; ?>
                    <tr data-row-key="<?= $key ?>">
                        <td><select class="grid-input form-select form-select-sm" name="rows[<?= $key ?>][CODTIPORESPONSABILIDADE]"><?php foreach ($types as $type): ?><option value="<?= $escape($type['CODTIPORESPONSABILIDADE']) ?>"<?= (string)$type['CODIGO'] === (string)$row['TIPO_CODIGO'] ? ' selected' : '' ?>><?= $escape($type['NOME']) ?></option><?php endforeach; ?></select></td>
                        <td><select class="form-select form-select-sm js-destination" name="rows[<?= $key ?>][TIPO_DESTINO]"><option value="USUARIO"<?= $row['TIPO_DESTINO'] === 'USUARIO' ? ' selected' : '' ?>>Usuário</option><option value="GRUPO"<?= $row['TIPO_DESTINO'] === 'GRUPO' ? ' selected' : '' ?>>Grupo</option></select></td>
                        <td><select class="form-select form-select-sm js-user" name="rows[<?= $key ?>][CODUSUARIO]"><option value="">Selecione</option><?php foreach ($users as $user): ?><option value="<?= $escape($user['CODUSUARIO']) ?>"<?= (int)$user['CODUSUARIO'] === (int)$row['CODUSUARIO'] ? ' selected' : '' ?>><?= $escape($user['NOME']) ?></option><?php endforeach; ?></select><select class="form-select form-select-sm js-group" name="rows[<?= $key ?>][CODGRUPO]"><option value="">Selecione</option><?php foreach ($groups as $group): ?><option value="<?= $escape($group['CODGRUPO']) ?>"<?= (int)$group['CODGRUPO'] === (int)$row['CODGRUPO'] ? ' selected' : '' ?>><?= $escape($group['NOME']) ?></option><?php endforeach; ?></select></td>
                        <td><input class="grid-input form-control form-control-sm" type="number" min="0" name="rows[<?= $key ?>][PRIORIDADE]" value="<?= $escape($row['PRIORIDADE']) ?>"></td>
                        <td class="text-center"><input type="checkbox" name="rows[<?= $key ?>][PRINCIPAL]" value="1"<?= !empty($row['PRINCIPAL']) ? ' checked' : '' ?>></td>
                        <td><input class="grid-input form-control form-control-sm" type="date" name="rows[<?= $key ?>][VALIDO_DE]" value="<?= $escape($row['VALIDO_DE']) ?>"></td>
                        <td><input class="grid-input form-control form-control-sm" type="date" name="rows[<?= $key ?>][VALIDO_ATE]" value="<?= $escape($row['VALIDO_ATE']) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</main>
<script>
const types=<?= json_encode($types, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,users=<?= json_encode($users, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,groups=<?= json_encode($groups, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
let sequence=0,selected=null;const tbody=document.querySelector('#responsibilityTable tbody'),permissions=<?= $permissionJs ?>;
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
function options(items,id){return '<option value="">Selecione</option>'+items.map(x=>'<option value="'+esc(x[id])+'">'+esc(x.NOME)+'</option>').join('');}
function updateDestination(row){const destination=row.querySelector('.js-destination'),user=row.querySelector('.js-user'),group=row.querySelector('.js-group');if(!destination||!user||!group)return;const isUser=destination.value==='USUARIO';user.classList.toggle('d-none',!isUser);group.classList.toggle('d-none',isUser);if(isUser)group.value='';else user.value='';}
function selectRow(row){document.querySelectorAll('#responsibilityTable tbody tr').forEach(item=>item.classList.remove('table-active','selected'));selected=row;if(row){row.classList.add('table-active','selected');document.querySelector('#deleteRow').disabled=String(row.dataset.rowKey).startsWith('novo_')?!permissions.include:!permissions.delete;}}
function bind(row){row.addEventListener('click',()=>selectRow(row));row.querySelector('.js-destination')?.addEventListener('change',()=>updateDestination(row));updateDestination(row);}
document.querySelectorAll('#responsibilityTable tbody tr[data-row-key]').forEach(bind);
document.querySelector('#deleteRow').addEventListener('click',()=>{if(selected){if(!String(selected.dataset.rowKey).startsWith('novo_')){const hidden=document.createElement('input');hidden.type='hidden';hidden.name='rows['+selected.dataset.rowKey+'][_delete]';hidden.value='1';selected.appendChild(hidden);}selected.remove();selectRow(null);}});
document.querySelector('#addRow').addEventListener('click',()=>{const key='novo_'+(++sequence),row=document.createElement('tr');row.dataset.rowKey=key;row.innerHTML='<td><select class="grid-input form-select form-select-sm" name="rows['+key+'][CODTIPORESPONSABILIDADE]">'+types.map(x=>'<option value="'+esc(x.CODTIPORESPONSABILIDADE)+'">'+esc(x.NOME)+'</option>').join('')+'</select></td><td><select class="form-select form-select-sm js-destination" name="rows['+key+'][TIPO_DESTINO]"><option value="USUARIO">Usuário</option><option value="GRUPO">Grupo</option></select></td><td><select class="form-select form-select-sm js-user" name="rows['+key+'][CODUSUARIO]">'+options(users,'CODUSUARIO')+'</select><select class="form-select form-select-sm js-group" name="rows['+key+'][CODGRUPO]">'+options(groups,'CODGRUPO')+'</select></td><td><input class="grid-input form-control form-control-sm" type="number" min="0" name="rows['+key+'][PRIORIDADE]" value="0"></td><td class="text-center"><input type="checkbox" name="rows['+key+'][PRINCIPAL]" value="1"></td><td><input class="grid-input form-control form-control-sm" type="date" name="rows['+key+'][VALIDO_DE]" value="<?= date('Y-m-d') ?>"></td><td><input class="grid-input form-control form-control-sm" type="date" name="rows['+key+'][VALIDO_ATE]" value=""></td>';tbody.appendChild(row);bind(row);selectRow(row);});
</script>
<script>window.mlResponsibilityPermission=<?= $permissionJs ?>;</script>
<script src="../assets/js/responsabilidades-permissoes.js?v=2"></script>
<script src="../assets/js/treinamento-ml-responsabilidades.js?v=2"></script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?></body>
</html>

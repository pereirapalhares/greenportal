<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Critérios dos Fornecedores › Aprovadores dos documentos (Revisão G item 152; migração 420).
 *
 * Cada regra diz quem aprova um documento exigido (critério) — ou todos os documentos — dos fornecedores: um usuário ou um grupo
 * (valem os usuários do grupo). O painel de Aprovações mostra cada documento "Pendente com a empresa" só para quem o aprova;
 * documento sem regra continua com o responsável pelo fornecedor. O ADMINISTRADOR vê todos.
 * Permissão: a do menu HOMOLOGACAO_CRITERIOS_FORNECEDORES (visualizar; incluir / editar / excluir para gravar).
 * Leitura por sp_crud_select (vw_hom_documento_aprovador, vw_homologacao_criterios_fornecedores_grid, listas de pessoas e grupos);
 * gravação por sp_crud_insert / sp_crud_update / sp_crud_delete na tabela hom_documento_aprovador. Nenhum aviso é enviado.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit(htmlspecialchars($m, ENT_QUOTES, 'UTF-8')); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/ResponsaveisRegras.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, 'Sessão expirada.');
$idioma = IdiomaService::idioma();
$t = static fn(string $c, string $pt, string $en, string $es): string => IdiomaService::t('hom_aprovadores.' . $c, ['pt' => $pt, 'en' => $en, 'es' => $es]);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$perm = PermissaoTransacao::obter('HOMOLOGACAO_CRITERIOS_FORNECEDORES');
if (empty($perm['pode_visualizar'])) $sair(403, $t('negado', 'Perfil sem permissão para ver os aprovadores dos documentos.', 'Your profile cannot see the document approvers.', 'Su perfil no puede ver los aprobadores de los documentos.'));
if (!ResponsaveisRegras::existe($pdo, 'hom_documento_aprovador')) $sair(503, $t('sem_migracao', 'Aprovadores dos documentos ainda não foram ativados nesta empresa (migração 420).', 'Document approvers are not active for this company yet (migration 420).', 'Aprobadores de los documentos aún no activados en esta empresa (migración 420).'));
AuditService::record($pdo, 'homologacaofornecedores_aprovadores_documentos', 'ACESSAR', 'SUCESSO', 'hom_documento_aprovador', '');
$eu = (int)($_SESSION['usuario']['codusuario'] ?? 0);

$ler = static function (string $view, array $filtros = [], int $max = 20000) use ($pdo): array {
    if (!ResponsaveisRegras::existe($pdo, $view)) return [];
    $cols = ResponsaveisRegras::colunas($pdo, $view);
    $f = [];
    foreach ($filtros as $k => $v) if (isset($cols[strtolower($k)])) $f[$cols[strtolower($k)]] = $v;
    $out = [];
    for ($off = 0; $off < $max; $off += 1000) {
        $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1000, ?)');
        $s->execute([$view, $f ? json_encode($f, JSON_UNESCAPED_UNICODE) : null, $off]);
        $lote = array_map('array_change_key_case', $s->fetchAll(PDO::FETCH_ASSOC)); $s->closeCursor();
        array_push($out, ...$lote);
        if (count($lote) < 1000) break;
    }
    return $out;
};
$crud = static function (string $sql, array $p) use ($pdo): void { $s = $pdo->prepare($sql); $s->execute($p); $s->closeCursor(); };

// listas
$criterios = [];
foreach (ResponsaveisRegras::existe($pdo, 'vw_homologacao_criterios_fornecedores_grid') ? $ler('vw_homologacao_criterios_fornecedores_grid') : $ler('fo_criteriofornecedores') as $c) {
    $nome = trim((string)($idioma === 'en' && trim((string)($c['dscriterio_en'] ?? '')) !== '' ? $c['dscriterio_en'] : ($c['dscriterio'] ?? '')));
    $criterios[(int)($c['codcriteriofornecedor'] ?? 0)] = mb_substr(preg_replace('/\s+/', ' ', $nome), 0, 120);
}
unset($criterios[0]);
$usuarios = [];
foreach (ResponsaveisRegras::existe($pdo, 'vw_resp_regras_pessoas') ? $ler('vw_resp_regras_pessoas') : (ResponsaveisRegras::existe($pdo, 'vw_responsabilidade_usuarios_ativos') ? $ler('vw_responsabilidade_usuarios_ativos') : $ler('usuarios', ['ativo' => 1])) as $u)
    $usuarios[(int)$u['codusuario']] = trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? $u['codusuario']);
$grupos = [];
foreach (ResponsaveisRegras::existe($pdo, 'vw_responsabilidade_grupos_ativos') ? $ler('vw_responsabilidade_grupos_ativos') : $ler('grupos', ['ativo' => 1]) as $g)
    $grupos[(int)$g['codgrupo']] = (string)($g['nome'] ?? $g['codgrupo']);
foreach ([&$criterios, &$usuarios, &$grupos] as &$lista) asort($lista, SORT_NATURAL | SORT_FLAG_CASE);
unset($lista);

$carregar = static fn(): array => $ler('vw_hom_documento_aprovador');
$regras = $carregar();

// ---------------------------------------------------------------- gravação
if (empty($_SESSION['hom_aprovadores_csrf'])) $_SESSION['hom_aprovadores_csrf'] = bin2hex(random_bytes(16));
$msg = ''; $erros = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
    if (!hash_equals((string)$_SESSION['hom_aprovadores_csrf'], (string)($_POST['csrf'] ?? ''))) $sair(400, $t('csrf', 'A página expirou. Abra de novo e grave.', 'The page expired. Open it again and save.', 'La página expiró. Ábrala de nuevo y guarde.'));
    if (empty($perm['pode_incluir']) && empty($perm['pode_editar']) && empty($perm['pode_excluir'])) $sair(403, $t('sem_gravar', 'Perfil sem permissão para alterar os aprovadores.', 'Your profile cannot change the approvers.', 'Su perfil no puede cambiar los aprobadores.'));
    $existentes = [];
    foreach ($regras as $r) $existentes[(int)$r['codaprovador']] = $r;
    $chaves = [];
    foreach ($existentes as $id => $r) $chaves[$id] = (int)($r['codcriteriofornecedor'] ?? 0) . '|' . (int)($r['codusuario'] ?? 0) . '|' . (int)($r['codgrupo'] ?? 0);
    $ignorados = 0; $feitos = 0;
    $posts = (array)($_POST['rows'] ?? []);
    // exclusões primeiro (liberam a combinação para uma linha nova igual)
    foreach ($posts as $chave => $row) {
        $id = ctype_digit((string)$chave) ? (int)$chave : 0;
        if (!$id || !isset($existentes[$id]) || empty(((array)$row)['_delete'])) continue;
        if (empty($perm['pode_excluir'])) { $ignorados++; continue; }
        $crud('CALL sp_crud_delete(?, ?)', ['hom_documento_aprovador', json_encode(['CODAPROVADOR' => $id])]); $feitos++;
        unset($chaves[$id]);
    }
    foreach ($posts as $chave => $row) {
        $row = (array)$row;
        $id = ctype_digit((string)$chave) ? (int)$chave : 0;
        if ($id && !isset($existentes[$id])) continue;
        if (!empty($row['_delete'])) continue;
        if ($id ? empty($perm['pode_editar']) : empty($perm['pode_incluir'])) { $ignorados++; continue; }
        $antes = $id ? $existentes[$id] : [];
        $destino = strtoupper((string)($row['TIPO_DESTINO'] ?? 'USUARIO')) === 'GRUPO' ? 'GRUPO' : 'USUARIO';
        $usu = (int)($row['CODUSUARIO'] ?? 0); $gru = (int)($row['CODGRUPO'] ?? 0); $cri = (int)($row['CODCRITERIOFORNECEDOR'] ?? 0);
        $vale = static fn(int $v, array $lista, string $campo): bool => $v > 0 && (isset($lista[$v]) || (int)($antes[$campo] ?? 0) === $v);
        $linha = $id ? '#' . $id : $t('nova', 'nova regra', 'new rule', 'nueva regla');
        if ($destino === 'USUARIO' && !$vale($usu, $usuarios, 'codusuario')) { $erros[] = $linha . ': ' . $t('falta_usuario', 'escolha o usuário.', 'choose the user.', 'elija el usuario.'); continue; }
        if ($destino === 'GRUPO' && !$vale($gru, $grupos, 'codgrupo')) { $erros[] = $linha . ': ' . $t('falta_grupo', 'escolha o grupo.', 'choose the group.', 'elija el grupo.'); continue; }
        if ($cri && !$vale($cri, $criterios, 'codcriteriofornecedor')) $cri = 0;
        $dados = ['CODCRITERIOFORNECEDOR' => $cri ?: null, 'CODUSUARIO' => $destino === 'USUARIO' ? $usu : null, 'CODGRUPO' => $destino === 'GRUPO' ? $gru : null, 'ATIVO' => !empty($row['ATIVO']) ? 'S' : 'N'];
        $k = $cri . '|' . (int)$dados['CODUSUARIO'] . '|' . (int)$dados['CODGRUPO'];
        $outra = array_search($k, $chaves, true);
        if ($outra !== false && $outra !== $id) { $erros[] = $linha . ': ' . $t('repetida', 'já existe uma regra igual (mesmo documento e aprovador).', 'an identical rule already exists (same document and approver).', 'ya existe una regla igual (mismo documento y aprobador).'); continue; }
        try {
            if ($id) {
                $mudou = false;
                foreach ($dados as $c => $v) if ((string)($antes[strtolower($c)] ?? '') !== (string)($v ?? '')) $mudou = true;
                if (!$mudou) continue;
                $crud('CALL sp_crud_update(?, ?, ?)', ['hom_documento_aprovador', json_encode(['CODAPROVADOR' => $id]), json_encode($dados, JSON_UNESCAPED_UNICODE)]);
                $chaves[$id] = $k;
            } else {
                $crud('CALL sp_crud_insert(?, ?)', ['hom_documento_aprovador', json_encode($dados + ['CODUSUARIO_INCLUSAO' => $eu ?: null], JSON_UNESCAPED_UNICODE)]);
                $chaves['n' . $chave] = $k;
            }
            $feitos++;
        } catch (PDOException $x) { $erros[] = $linha . ': ' . IdiomaService::erro($x, 'gravar'); }
    }
    $msg = $feitos ? $t('gravado', 'Aprovadores gravados. Valem na hora no painel de Aprovações.', 'Approvers saved. They apply right away in the Approvals panel.', 'Aprobadores guardados. Valen de inmediato en el panel de Aprobaciones.') : ($erros ? '' : $t('nada', 'Nada mudou.', 'Nothing changed.', 'Nada cambió.'));
    if ($ignorados) $msg .= ' ' . $t('ignorados', 'Alterações sem permissão do seu perfil foram ignoradas.', 'Changes your profile is not allowed to make were ignored.', 'Los cambios sin permiso de su perfil fueron ignorados.');
    AuditService::record($pdo, 'homologacaofornecedores_aprovadores_documentos', 'ALTERAR', $erros ? 'PARCIAL' : 'SUCESSO', 'hom_documento_aprovador', '', ['gravadas' => $feitos, 'erros' => count($erros)]);
    $regras = $carregar();
}
$csrf = (string)$_SESSION['hom_aprovadores_csrf'];
$podeAlterar = !empty($perm['pode_incluir']) || !empty($perm['pode_editar']) || !empty($perm['pode_excluir']);
$titulo = $t('titulo', 'Aprovadores dos documentos de fornecedor', 'Supplier document approvers', 'Aprobadores de los documentos de proveedor');
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
usort($regras, static fn($a, $b) => strcmp((string)($criterios[(int)($a['codcriteriofornecedor'] ?? 0)] ?? ''), (string)($criterios[(int)($b['codcriteriofornecedor'] ?? 0)] ?? '')));

$opcoes = static function (array $lista, int $atual, string $vazio, string $sufixoAusente = '') use ($e): string {
    $h = '<option value="">' . $e($vazio) . '</option>';
    if ($atual && !isset($lista[$atual])) $h .= '<option value="' . $atual . '" selected>#' . $atual . $e($sufixoAusente) . '</option>';
    foreach ($lista as $k => $v) $h .= '<option value="' . (int)$k . '"' . ($k === $atual ? ' selected' : '') . '>' . $e($v) . '</option>';
    return $h;
};
$txtTodos = $t('todos_docs', 'Todos os documentos', 'All documents', 'Todos los documentos'); $txtEscolha = $t('escolha', 'Escolha…', 'Choose…', 'Elija…');
$txtInativo = ' ' . $t('inativo', '(inativo)', '(inactive)', '(inactivo)');
$linha = static function (string $chave, array $r) use ($e, $t, $opcoes, $criterios, $usuarios, $grupos, $podeAlterar, $txtTodos, $txtEscolha, $txtInativo): string {
    $dest = (int)($r['codgrupo'] ?? 0) > 0 ? 'GRUPO' : 'USUARIO';
    $dis = $podeAlterar ? '' : ' disabled';
    $n = 'rows[' . $e($chave) . ']';
    $ativo = !isset($r['ativo']) || strtoupper((string)$r['ativo']) === 'S';
    return '<tr data-linha="' . $e($chave) . '">'
        . '<td><select class="form-select form-select-sm" name="' . $n . '[CODCRITERIOFORNECEDOR]"' . $dis . '>' . $opcoes($criterios, (int)($r['codcriteriofornecedor'] ?? 0), $txtTodos, ' ' . $t('excluido', '(excluído)', '(deleted)', '(eliminado)')) . '</select></td>'
        . '<td><select class="form-select form-select-sm js-destino" name="' . $n . '[TIPO_DESTINO]"' . $dis . '><option value="USUARIO"' . ($dest !== 'GRUPO' ? ' selected' : '') . '>' . $e($t('usuario', 'Usuário', 'User', 'Usuario')) . '</option><option value="GRUPO"' . ($dest === 'GRUPO' ? ' selected' : '') . '>' . $e($t('grupo', 'Grupo', 'Group', 'Grupo')) . '</option></select></td>'
        . '<td><select class="form-select form-select-sm js-usuario' . ($dest === 'GRUPO' ? ' d-none' : '') . '" name="' . $n . '[CODUSUARIO]"' . $dis . '>' . $opcoes($usuarios, (int)($r['codusuario'] ?? 0), $txtEscolha, $txtInativo) . '</select>'
        . '<select class="form-select form-select-sm js-grupo' . ($dest === 'GRUPO' ? '' : ' d-none') . '" name="' . $n . '[CODGRUPO]"' . $dis . '>' . $opcoes($grupos, (int)($r['codgrupo'] ?? 0), $txtEscolha, $txtInativo) . '</select></td>'
        . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n . '[ATIVO]" value="1"' . ($ativo ? ' checked' : '') . $dis . '></td>'
        . '<td>' . ($podeAlterar ? '<button type="button" class="btn btn-sm btn-outline-danger js-excluir" title="' . $e($t('excluir', 'Excluir', 'Delete', 'Eliminar')) . '"><i class="bi bi-trash"></i></button>' : '') . '</td></tr>';
};
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=3" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
<style>body{font-size:13px}.ha{padding:14px 16px}.ha-ajuda{color:var(--gp-muted,#6c757d);max-width:980px}.ha table select{min-width:160px}.ha td:first-child select{min-width:320px}</style></head>
<body class="theme-<?= $e($theme) ?>" data-transacao="homologacaofornecedores_aprovadores_documentos"><main class="ha">
<h5 class="mb-1"><i class="bi bi-person-check me-2"></i><?= $e($titulo) ?></h5>
<p class="ha-ajuda small mb-3"><?= $e($t('ajuda', 'Escolha quem aprova cada documento exigido dos fornecedores (ou todos os documentos): um usuário ou um grupo (valem os usuários do grupo). No painel de Aprovações, cada documento "Pendente com a empresa" aparece só para quem o aprova. Documento sem regra continua com o responsável pelo fornecedor. Nenhum aviso ou e-mail é enviado ao gravar.', 'Choose who approves each required supplier document (or all documents): a user or a group (its users). In the Approvals panel, each document "Pending with the company" shows only to its approvers. A document without a rule stays with the supplier owner. No alert or e-mail is sent when saving.', 'Elija quién aprueba cada documento exigido de los proveedores (o todos): un usuario o un grupo (valen sus usuarios). En el panel de Aprobaciones, cada documento "Pendiente con la empresa" aparece solo para quien lo aprueba. Documento sin regla sigue con el responsable del proveedor. No se envía ningún aviso ni correo al guardar.')) ?></p>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= $e($msg) ?></div><?php endif; ?>
<?php foreach ($erros as $er): ?><div class="alert alert-danger py-2 mb-1"><?= $e($er) ?></div><?php endforeach; ?>
<form method="post" id="haForm"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<div class="table-responsive border rounded bg-white"><table class="table table-sm align-middle mb-0" id="haTabela"><thead class="table-light"><tr>
<th><?= $e($t('documento', 'Documento exigido', 'Required document', 'Documento exigido')) ?></th><th><?= $e($t('destino', 'Destino', 'Target', 'Destino')) ?></th><th><?= $e($t('aprovador', 'Aprovador', 'Approver', 'Aprobador')) ?></th><th class="text-center"><?= $e($t('ativa', 'Ativa', 'Active', 'Activa')) ?></th><th></th></tr></thead>
<tbody><?php foreach ($regras as $r) echo $linha((string)(int)$r['codaprovador'], $r); ?>
<?php if (!$regras): ?><tr class="ha-vazio"><td colspan="5" class="text-muted py-3"><?= $e($t('vazio', 'Nenhuma regra: os documentos ficam com o responsável pelo fornecedor.', 'No rules: documents stay with the supplier owner.', 'Ninguna regla: los documentos siguen con el responsable del proveedor.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<template id="haModelo"><?= $linha('__N__', []) ?></template>
<?php if ($podeAlterar): ?>
<div class="d-flex justify-content-between mt-3">
<?php if (!empty($perm['pode_incluir'])): ?><button type="button" class="btn btn-sm btn-outline-primary" id="haAdicionar"><i class="bi bi-plus-lg me-1"></i><?= $e($t('adicionar', 'Adicionar aprovador', 'Add approver', 'Agregar aprobador')) ?></button><?php else: ?><span></span><?php endif; ?>
<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i><?= $e($t('salvar', 'Salvar', 'Save', 'Guardar')) ?></button></div>
<?php else: ?><div class="alert alert-warning mt-3 py-2"><?= $e($t('so_consulta', 'Seu perfil só consulta os aprovadores.', 'Your profile can only view the approvers.', 'Su perfil solo consulta los aprobadores.')) ?></div><?php endif; ?>
</form></main>
<script>
(function () {
  var tab = document.querySelector('#haTabela tbody'), seq = 0;
  function ligar(tr) {
    var d = tr.querySelector('.js-destino'); if (!d) return;
    d.addEventListener('change', function () { var g = d.value === 'GRUPO'; tr.querySelector('.js-usuario').classList.toggle('d-none', g); tr.querySelector('.js-grupo').classList.toggle('d-none', !g); });
  }
  tab.querySelectorAll('tr[data-linha]').forEach(ligar);
  var add = document.getElementById('haAdicionar');
  if (add) add.addEventListener('click', function () {
    var vazio = tab.querySelector('.ha-vazio'); if (vazio) vazio.remove();
    var html = document.getElementById('haModelo').innerHTML.split('__N__').join('novo_' + (++seq));
    var corpo = document.createElement('tbody'); corpo.innerHTML = html;
    var tr = corpo.firstElementChild; tab.appendChild(tr); ligar(tr);
  });
  tab.addEventListener('click', function (ev) {
    var b = ev.target.closest('.js-excluir'); if (!b) return;
    var tr = b.closest('tr'), k = tr.getAttribute('data-linha');
    if (/^\d+$/.test(k)) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'rows[' + k + '][_delete]'; h.value = '1'; document.getElementById('haForm').appendChild(h); }
    tr.remove();
  });
}());
</script></body></html>

<?php
declare(strict_types=1);

/*
 * Licenças › Campos personalizados (Revisão G item 63; migração 421, 26/11/2026).
 * Sem ?licenca: cadastro dos campos (menu LICENCAS_CAMPOS): nome, tipo (texto, número, data, lista, sim/não), opções da lista,
 *   obrigatório, ordem e ativo. Campo já preenchido em alguma licença não é excluído (pode ser desativado).
 * Com ?licenca=N: valores dos campos ativos de uma licença (botão "Campos personalizados" do grid de Licenças; permissão LICENCAS:
 *   visualizar para ver, editar para gravar). Os valores ficam na licença original (vale para as versões) — CODLICENCA_BASE.
 * Leitura por sp_crud_select; gravação por sp_crud_insert / sp_crud_update / sp_crud_delete.
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
use PaginaSimples as P;

$licenca = (int)($_GET['licenca'] ?? $_POST['licenca'] ?? 0);
P::abrir($licenca > 0 ? 'LICENCAS' : 'LICENCAS_CAMPOS');
if (!P::existe('lic_campo')) P::sair(503, P::L('Campos personalizados ainda não instalados nesta empresa (migração 421).', 'Custom fields are not installed yet (migration 421).', 'Campos personalizados aún no instalados (migración 421).'));
$csrf = P::csrf('lic_campos_csrf');
$TIPOS = ['TEXTO' => P::L('Texto', 'Text', 'Texto'), 'NUMERO' => P::L('Número', 'Number', 'Número'), 'DATA' => P::L('Data', 'Date', 'Fecha'), 'LISTA' => P::L('Lista de opções', 'Option list', 'Lista de opciones'), 'SIMNAO' => P::L('Sim/Não', 'Yes/No', 'Sí/No')];
$msg = ''; $erros = [];
$campos = static fn(): array => (function () { $c = P::sel('vw_lic_campo', [], 1000); usort($c, static fn($a, $b) => [(int)$a['ORDEM'], (string)$a['NOME']] <=> [(int)$b['ORDEM'], (string)$b['NOME']]); return $c; })();

if ($licenca > 0) {
    // ---------------------------------------------------------------- valores de uma licença
    $lic = P::sel('li_licencas', ['CODLICENCA' => $licenca], 1)[0] ?? null;
    if (!$lic) P::sair(404, P::L('Licença não encontrada.', 'License not found.', 'Licencia no encontrada.'));
    $base = (int)($lic['CODLICENCA_BASE'] ?? 0) ?: $licenca;
    $ativos = array_values(array_filter($campos(), static fn($c) => (string)$c['ATIVO'] === 'S'));
    $valores = [];
    foreach (P::sel('lic_campo_valor', ['CODLICENCA' => $base], 1000) as $v) $valores[(int)$v['CODCAMPO']] = $v;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        P::conferirCsrf('lic_campos_csrf');
        if (!P::pode('editar')) P::sair(403, P::L('Seu perfil não pode alterar licenças.', 'Your profile cannot change licenses.', 'Su perfil no puede cambiar licencias.'));
        $n = 0;
        foreach ($ativos as $c) {
            $cod = (int)$c['CODCAMPO']; $v = trim((string)($_POST['campo'][$cod] ?? ''));
            if ($v !== '' && (string)$c['TIPO'] === 'NUMERO' && !is_numeric(str_replace(',', '.', $v))) { $erros[] = $c['NOME'] . ': ' . P::L('informe um número.', 'enter a number.', 'informe un número.'); continue; }
            if ($v !== '' && (string)$c['TIPO'] === 'DATA' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $erros[] = $c['NOME'] . ': ' . P::L('data inválida.', 'invalid date.', 'fecha inválida.'); continue; }
            if ($v === '' && (string)$c['OBRIGATORIO'] === 'S') { $erros[] = $c['NOME'] . ': ' . P::L('obrigatório.', 'required.', 'obligatorio.'); continue; }
            $atual = $valores[$cod] ?? null;
            if ((string)($atual['VALOR'] ?? '') === $v) continue;
            if ($atual && $v === '') P::del('lic_campo_valor', ['CODVALOR' => (int)$atual['CODVALOR']]);   // apagado: o campo deixa de estar "em uso" nesta licença
            elseif ($atual) P::upd('lic_campo_valor', ['CODVALOR' => (int)$atual['CODVALOR']], ['VALOR' => mb_substr($v, 0, 2000), 'CODUSUARIO' => P::$uid ?: null]);
            else P::ins('lic_campo_valor', ['CODLICENCA' => $base, 'CODCAMPO' => $cod, 'VALOR' => mb_substr($v, 0, 2000), 'CODUSUARIO' => P::$uid ?: null]);
            $n++;
        }
        AuditService::record(P::$pdo, 'licencas_campos', 'SALVAR_VALORES', $erros ? 'PARCIAL' : 'SUCESSO', 'lic_campo_valor', (string)$base, ['qtd' => $n]);
        $msg = $n ? P::L('Campos gravados.', 'Fields saved.', 'Campos guardados.') : ($erros ? '' : P::L('Nada mudou.', 'Nothing changed.', 'Nada cambió.'));
        $valores = [];
        foreach (P::sel('lic_campo_valor', ['CODLICENCA' => $base], 1000) as $v) $valores[(int)$v['CODCAMPO']] = $v;
    }
    session_write_close();
    P::cabecalho(P::L('Campos personalizados da licença', 'License custom fields', 'Campos personalizados de la licencia'), 'bi-input-cursor-text', 'licencas_campos');
    echo '<div class="mb-2 small"><b>' . P::e((string)($lic['DSCLICENCA'] ?? '#' . $licenca)) . '</b></div>';
    if ($msg !== '') echo '<div class="alert alert-success py-2">' . P::e($msg) . '</div>';
    foreach ($erros as $er) echo '<div class="alert alert-danger py-1 mb-1">' . P::e($er) . '</div>';
    if (!$ativos) { echo '<div class="alert alert-info">' . P::e(P::L('Nenhum campo personalizado cadastrado. O administrador cadastra em Licenças › Campos personalizados.', 'No custom field yet. The administrator creates them in Licenses › Custom fields.', 'Ningún campo personalizado. El administrador los crea en Licencias › Campos personalizados.')) . '</div>'; P::rodape(); exit; }
    $dis = P::pode('editar') ? '' : ' disabled';
    echo '<form method="post" style="max-width:720px"><input type="hidden" name="csrf" value="' . P::e($csrf) . '"><input type="hidden" name="licenca" value="' . $licenca . '">';
    foreach ($ativos as $c) {
        $cod = (int)$c['CODCAMPO']; $v = (string)($valores[$cod]['VALOR'] ?? ''); $n = 'campo[' . $cod . ']';
        echo '<div class="mb-2"><label class="form-label small fw-semibold mb-0">' . P::e($c['NOME']) . ((string)$c['OBRIGATORIO'] === 'S' ? ' *' : '') . '</label>';
        switch ((string)$c['TIPO']) {
            case 'NUMERO': echo '<input class="form-control form-control-sm" inputmode="decimal" name="' . $n . '" value="' . P::e($v) . '"' . $dis . '>'; break;
            case 'DATA': echo '<input type="date" class="form-control form-control-sm" style="max-width:200px" name="' . $n . '" value="' . P::e($v) . '"' . $dis . '>'; break;
            case 'SIMNAO': echo '<select class="form-select form-select-sm" style="max-width:200px" name="' . $n . '"' . $dis . '>' . P::opcoes(['Sim' => P::L('Sim', 'Yes', 'Sí'), 'Não' => P::L('Não', 'No', 'No')], $v, ' ') . '</select>'; break;
            case 'LISTA':
                $ops = array_values(array_filter(array_map('trim', explode(';', (string)$c['OPCOES'])), 'strlen'));
                echo '<select class="form-select form-select-sm" name="' . $n . '"' . $dis . '>' . P::opcoes(array_combine($ops, $ops) ?: [], $v, ' ') . '</select>'; break;
            default: echo '<input class="form-control form-control-sm" maxlength="2000" name="' . $n . '" value="' . P::e($v) . '"' . $dis . '>';
        }
        echo '</div>';
    }
    if (P::pode('editar')) echo '<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>' . P::e(P::L('Salvar', 'Save', 'Guardar')) . '</button>';
    echo '</form>';
    P::rodape(); exit;
}

// ---------------------------------------------------------------- cadastro dos campos
$lista = $campos();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    P::conferirCsrf('lic_campos_csrf');
    $existentes = [];
    foreach ($lista as $c) $existentes[(int)$c['CODCAMPO']] = $c;
    $nomes = [];
    foreach ($lista as $c) $nomes[mb_strtolower(trim((string)$c['NOME']))] = (int)$c['CODCAMPO'];
    $n = 0;
    foreach ((array)($_POST['rows'] ?? []) as $k => $row) {
        $row = (array)$row; $id = ctype_digit((string)$k) ? (int)$k : 0;
        if ($id && !isset($existentes[$id])) continue;
        if (!empty($row['_delete'])) {
            if (!$id) continue;
            if (!P::pode('excluir')) { $erros[] = P::L('Seu perfil não pode excluir.', 'Your profile cannot delete.', 'Su perfil no puede eliminar.'); continue; }
            if ((int)$existentes[$id]['QTD_USO'] > 0) { $erros[] = $existentes[$id]['NOME'] . ': ' . P::L('já está preenchido em licenças; desative em vez de excluir.', 'already filled in licenses; deactivate instead.', 'ya está completado en licencias; desactívelo.'); continue; }
            P::del('lic_campo', ['CODCAMPO' => $id]); $n++; continue;
        }
        if ($id ? !P::pode('editar') : !P::pode('incluir')) continue;
        $nome = mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)($row['NOME'] ?? ''))), 0, 100);
        if ($nome === '') { if (!$id) continue; $erros[] = '#' . $id . ': ' . P::L('informe o nome.', 'enter the name.', 'informe el nombre.'); continue; }
        if (($nomes[mb_strtolower($nome)] ?? $id) !== $id) { $erros[] = $nome . ': ' . P::L('já existe um campo com esse nome.', 'a field with this name already exists.', 'ya existe un campo con ese nombre.'); continue; }
        $tipo = isset($TIPOS[(string)($row['TIPO'] ?? '')]) ? (string)$row['TIPO'] : 'TEXTO';
        $ops = $tipo === 'LISTA' ? mb_substr(implode('; ', array_values(array_filter(array_map('trim', explode(';', (string)($row['OPCOES'] ?? ''))), 'strlen'))), 0, 1000) : null;
        if ($tipo === 'LISTA' && !$ops) { $erros[] = $nome . ': ' . P::L('informe as opções separadas por ;', 'enter the options separated by ;', 'informe las opciones separadas por ;'); continue; }
        $dados = ['NOME' => $nome, 'TIPO' => $tipo, 'OPCOES' => $ops, 'OBRIGATORIO' => !empty($row['OBRIGATORIO']) ? 'S' : 'N', 'ORDEM' => (int)($row['ORDEM'] ?? 0), 'ATIVO' => !empty($row['ATIVO']) ? 'S' : 'N'];
        if ($id) {
            $mud = false; foreach ($dados as $c => $v) if ((string)($existentes[$id][$c] ?? '') !== (string)($v ?? '')) $mud = true;
            if (!$mud) continue;
            P::upd('lic_campo', ['CODCAMPO' => $id], $dados);
        } else { P::ins('lic_campo', $dados); $nomes[mb_strtolower($nome)] = -1; }
        $n++;
    }
    AuditService::record(P::$pdo, 'licencas_campos', 'SALVAR', $erros ? 'PARCIAL' : 'SUCESSO', 'lic_campo', null, ['qtd' => $n]);
    $msg = $n ? P::L('Campos gravados.', 'Fields saved.', 'Campos guardados.') : ($erros ? '' : P::L('Nada mudou.', 'Nothing changed.', 'Nada cambió.'));
    $lista = $campos();
}
session_write_close();
$pode = P::pode('incluir') || P::pode('editar') || P::pode('excluir');
$linha = static function (string $k, array $c) use ($TIPOS, $pode): string {
    $n = 'rows[' . P::e($k) . ']'; $dis = $pode ? '' : ' disabled';
    return '<tr data-linha="' . P::e($k) . '"><td><input class="form-control form-control-sm" maxlength="100" name="' . $n . '[NOME]" value="' . P::e($c['NOME'] ?? '') . '"' . $dis . '></td>'
        . '<td><select class="form-select form-select-sm" name="' . $n . '[TIPO]"' . $dis . '>' . P::opcoes($TIPOS, (string)($c['TIPO'] ?? 'TEXTO')) . '</select></td>'
        . '<td><input class="form-control form-control-sm" maxlength="1000" name="' . $n . '[OPCOES]" value="' . P::e($c['OPCOES'] ?? '') . '" placeholder="A; B; C"' . $dis . '></td>'
        . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n . '[OBRIGATORIO]" value="1"' . ((string)($c['OBRIGATORIO'] ?? 'N') === 'S' ? ' checked' : '') . $dis . '></td>'
        . '<td><input type="number" class="form-control form-control-sm" style="width:80px" name="' . $n . '[ORDEM]" value="' . (int)($c['ORDEM'] ?? 0) . '"' . $dis . '></td>'
        . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n . '[ATIVO]" value="1"' . ((string)($c['ATIVO'] ?? 'S') === 'S' ? ' checked' : '') . $dis . '></td>'
        . '<td class="small text-muted">' . (int)($c['QTD_USO'] ?? 0) . '</td>'
        . '<td>' . ($pode ? '<button type="button" class="btn btn-sm btn-outline-danger js-excluir"><i class="bi bi-trash"></i></button>' : '') . '</td></tr>';
};
P::cabecalho(P::L('Campos personalizados das licenças', 'License custom fields', 'Campos personalizados de las licencias'), 'bi-input-cursor-text', 'licencas_campos', '.ps table input.form-control{min-width:140px}');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Crie campos próprios da empresa para as licenças (ex.: "Órgão emissor", "Nº do processo", "Área responsável"). Eles aparecem no botão "Campos personalizados" do grid de Licenças. Tipo Lista: opções separadas por ponto e vírgula.', 'Create company-specific fields for licenses. They appear in the "Custom fields" button of the Licenses grid. List type: options separated by semicolons.', 'Cree campos propios para las licencias. Aparecen en el botón "Campos personalizados" de la grilla de Licencias. Tipo Lista: opciones separadas por punto y coma.')) ?></p>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= P::e($msg) ?></div><?php endif; foreach ($erros as $er): ?><div class="alert alert-danger py-1 mb-1"><?= P::e($er) ?></div><?php endforeach; ?>
<form method="post" id="lcForm"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>">
<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0" id="lcTab"><thead class="table-light"><tr><th><?= P::e(P::L('Nome', 'Name', 'Nombre')) ?></th><th><?= P::e(P::L('Tipo', 'Type', 'Tipo')) ?></th><th><?= P::e(P::L('Opções (lista)', 'Options (list)', 'Opciones (lista)')) ?></th><th class="text-center"><?= P::e(P::L('Obrigatório', 'Required', 'Obligatorio')) ?></th><th><?= P::e(P::L('Ordem', 'Order', 'Orden')) ?></th><th class="text-center"><?= P::e(P::L('Ativo', 'Active', 'Activo')) ?></th><th><?= P::e(P::L('Em uso', 'In use', 'En uso')) ?></th><th></th></tr></thead>
<tbody><?php foreach ($lista as $c) echo $linha((string)(int)$c['CODCAMPO'], $c); ?></tbody></table></div>
<template id="lcModelo"><?= $linha('__N__', []) ?></template>
<?php if ($pode): ?><div class="d-flex justify-content-between mt-2"><?php if (P::pode('incluir')): ?><button type="button" class="btn btn-sm btn-outline-primary" id="lcAdd"><i class="bi bi-plus-lg me-1"></i><?= P::e(P::L('Adicionar campo', 'Add field', 'Agregar campo')) ?></button><?php else: ?><span></span><?php endif; ?>
<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i><?= P::e(P::L('Salvar', 'Save', 'Guardar')) ?></button></div><?php endif; ?></form>
<script>
(function () { var tab = document.querySelector('#lcTab tbody'), seq = 0, add = document.getElementById('lcAdd');
  if (add) add.addEventListener('click', function () { var t = document.createElement('tbody'); t.innerHTML = document.getElementById('lcModelo').innerHTML.split('__N__').join('novo_' + (++seq)); tab.appendChild(t.firstElementChild); });
  tab.addEventListener('click', function (ev) { var b = ev.target.closest('.js-excluir'); if (!b) return; var tr = b.closest('tr'), k = tr.dataset.linha; if (/^\d+$/.test(k)) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'rows[' + k + '][_delete]'; h.value = '1'; document.getElementById('lcForm').appendChild(h); } tr.remove(); });
}());
</script>
<?php P::rodape(); ?>

<?php
declare(strict_types=1);

/*
 * Homologação › Tipos de veículo e equipamento (Revisão G itens 149 e 150; migração 421, 26/11/2026).
 * Tipos (categoria Veículo ou Equipamento) e, para cada tipo, os documentos exigidos para a prestação do serviço (obrigatório,
 * validade usual em meses). Os veículos e equipamentos de cada fornecedor ficam em Gestão de fornecedores › Veículos e equipamentos.
 * Tipo ou documento já usado não é excluído (pode ser desativado). Permissão: menu HOM_TIPOS_ATIVOS.
 * Leitura por sp_crud_select; gravação por sp_crud_insert / sp_crud_update / sp_crud_delete.
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
use PaginaSimples as P;

P::abrir('HOM_TIPOS_ATIVOS');
if (!P::existe('hom_ativo_tipo')) P::sair(503, P::L('Tipos de veículo e equipamento ainda não instalados nesta empresa (migração 421).', 'Not installed yet (migration 421).', 'Aún no instalados (migración 421).'));
$csrf = P::csrf('hom_tipos_ativos_csrf');
$CAT = ['VEICULO' => P::L('Veículo', 'Vehicle', 'Vehículo'), 'EQUIPAMENTO' => P::L('Equipamento', 'Equipment', 'Equipo')];
$tipoSel = (int)($_POST['tipo'] ?? $_GET['tipo'] ?? 0);
$msg = ''; $erros = [];
$tipos = static function (): array { $t = P::sel('vw_hom_ativo_tipo', [], 1000); usort($t, static fn($a, $b) => [(string)$a['CATEGORIA'], (string)$a['NOME']] <=> [(string)$b['CATEGORIA'], (string)$b['NOME']]); return $t; };
$lista = $tipos();
$porId = []; foreach ($lista as $t) $porId[(int)$t['CODTIPOATIVO']] = $t;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    P::conferirCsrf('hom_tipos_ativos_csrf');
    $n = 0;
    $usoDoc = [];
    foreach (P::sel('hom_ativo_doc', [], 50000) as $d) $usoDoc[(int)$d['CODTIPODOC']] = true;
    $grupo = (string)($_POST['acao'] ?? '') === 'docs' ? 'docs' : 'tipos';
    $existentes = [];
    if ($grupo === 'docs') { if (!isset($porId[$tipoSel])) P::sair(400, 'Tipo inválido.'); foreach (P::sel('vw_hom_ativo_tipo_doc', ['CODTIPOATIVO' => $tipoSel], 500) as $d) $existentes[(int)$d['CODTIPODOC']] = $d; }
    else $existentes = $porId;
    foreach ((array)($_POST['rows'] ?? []) as $k => $row) {
        $row = (array)$row; $id = ctype_digit((string)$k) ? (int)$k : 0;
        if ($id && !isset($existentes[$id])) continue;
        if (!empty($row['_delete'])) {
            if (!$id || !P::pode('excluir')) continue;
            if ($grupo === 'tipos' && ((int)$existentes[$id]['QTD_USO'] > 0 || (int)$existentes[$id]['QTD_DOCS'] > 0)) { $erros[] = $existentes[$id]['NOME'] . ': ' . P::L('tem documentos ou está em uso; desative.', 'has documents or is in use; deactivate it.', 'tiene documentos o está en uso; desactívelo.'); continue; }
            if ($grupo === 'docs' && isset($usoDoc[$id])) { $erros[] = $existentes[$id]['NOME'] . ': ' . P::L('já usado em veículos/equipamentos; desative.', 'already used; deactivate it.', 'ya usado; desactívelo.'); continue; }
            P::del($grupo === 'tipos' ? 'hom_ativo_tipo' : 'hom_ativo_tipo_doc', [$grupo === 'tipos' ? 'CODTIPOATIVO' : 'CODTIPODOC' => $id]); $n++; continue;
        }
        if ($id ? !P::pode('editar') : !P::pode('incluir')) continue;
        $nome = mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)($row['NOME'] ?? ''))), 0, $grupo === 'tipos' ? 120 : 200);
        if ($nome === '') { if ($id) $erros[] = '#' . $id . ': ' . P::L('informe o nome.', 'enter the name.', 'informe el nombre.'); continue; }
        if ($grupo === 'tipos') {
            $cat = isset($CAT[(string)($row['CATEGORIA'] ?? '')]) ? (string)$row['CATEGORIA'] : 'VEICULO';
            $dados = ['CATEGORIA' => $cat, 'NOME' => $nome, 'ATIVO' => !empty($row['ATIVO']) ? 'S' : 'N'];
            $tab = 'hom_ativo_tipo'; $pk = 'CODTIPOATIVO';
        } else {
            $dados = ['NOME' => $nome, 'OBRIGATORIO' => !empty($row['OBRIGATORIO']) ? 'S' : 'N', 'VALIDADE_MESES' => (int)($row['VALIDADE_MESES'] ?? 0) ?: null, 'ATIVO' => !empty($row['ATIVO']) ? 'S' : 'N'];
            $tab = 'hom_ativo_tipo_doc'; $pk = 'CODTIPODOC';
        }
        if ($id) { $mud = false; foreach ($dados as $c => $v) if ((string)($existentes[$id][$c] ?? '') !== (string)($v ?? '')) $mud = true; if (!$mud) continue; P::upd($tab, [$pk => $id], $dados); }
        else P::ins($tab, $dados + ($grupo === 'docs' ? ['CODTIPOATIVO' => $tipoSel] : []));
        $n++;
    }
    AuditService::record(P::$pdo, 'homologacaofornecedores_tipos_ativos', 'SALVAR', $erros ? 'PARCIAL' : 'SUCESSO', $grupo === 'tipos' ? 'hom_ativo_tipo' : 'hom_ativo_tipo_doc', (string)$tipoSel, ['qtd' => $n]);
    $msg = $n ? P::L('Gravado.', 'Saved.', 'Guardado.') : ($erros ? '' : P::L('Nada mudou.', 'Nothing changed.', 'Nada cambió.'));
    $lista = $tipos(); $porId = []; foreach ($lista as $t) $porId[(int)$t['CODTIPOATIVO']] = $t;
}
$docs = isset($porId[$tipoSel]) ? P::sel('vw_hom_ativo_tipo_doc', ['CODTIPOATIVO' => $tipoSel], 500) : [];
usort($docs, static fn($a, $b) => strnatcasecmp((string)$a['NOME'], (string)$b['NOME']));
session_write_close();
$pode = P::pode('incluir') || P::pode('editar') || P::pode('excluir');
$dis = $pode ? '' : ' disabled';
$lTipo = static function (string $k, array $t) use ($CAT, $dis, $pode): string {
    $n = 'rows[' . P::e($k) . ']'; $id = (int)($t['CODTIPOATIVO'] ?? 0);
    return '<tr data-linha="' . P::e($k) . '"><td><select class="form-select form-select-sm" name="' . $n . '[CATEGORIA]"' . $dis . '>' . P::opcoes($CAT, (string)($t['CATEGORIA'] ?? 'VEICULO')) . '</select></td>'
        . '<td><input class="form-control form-control-sm" maxlength="120" name="' . $n . '[NOME]" value="' . P::e($t['NOME'] ?? '') . '"' . $dis . '></td>'
        . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n . '[ATIVO]" value="1"' . ((string)($t['ATIVO'] ?? 'S') === 'S' ? ' checked' : '') . $dis . '></td>'
        . '<td class="small text-muted">' . (int)($t['QTD_DOCS'] ?? 0) . ' / ' . (int)($t['QTD_USO'] ?? 0) . '</td>'
        . '<td class="text-nowrap">' . ($id ? '<a class="btn btn-sm btn-outline-primary" href="?tipo=' . $id . '#docs"><i class="bi bi-file-earmark-check me-1"></i>' . P::e(P::L('Documentos exigidos', 'Required documents', 'Documentos exigidos')) . '</a> ' : '') . ($pode ? '<button type="button" class="btn btn-sm btn-outline-danger js-excluir"><i class="bi bi-trash"></i></button>' : '') . '</td></tr>';
};
$lDoc = static function (string $k, array $d) use ($dis, $pode): string {
    $n = 'rows[' . P::e($k) . ']';
    return '<tr data-linha="' . P::e($k) . '"><td><input class="form-control form-control-sm" maxlength="200" name="' . $n . '[NOME]" value="' . P::e($d['NOME'] ?? '') . '"' . $dis . '></td>'
        . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n . '[OBRIGATORIO]" value="1"' . ((string)($d['OBRIGATORIO'] ?? 'S') === 'S' ? ' checked' : '') . $dis . '></td>'
        . '<td><input type="number" min="0" class="form-control form-control-sm" style="width:100px" name="' . $n . '[VALIDADE_MESES]" value="' . P::e($d['VALIDADE_MESES'] ?? '') . '"' . $dis . '></td>'
        . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n . '[ATIVO]" value="1"' . ((string)($d['ATIVO'] ?? 'S') === 'S' ? ' checked' : '') . $dis . '></td>'
        . '<td>' . ($pode ? '<button type="button" class="btn btn-sm btn-outline-danger js-excluir"><i class="bi bi-trash"></i></button>' : '') . '</td></tr>';
};
P::cabecalho(P::L('Tipos de veículo e equipamento', 'Vehicle and equipment types', 'Tipos de vehículo y equipo'), 'bi-truck', 'homologacaofornecedores_tipos_ativos', '.ps table input.form-control{min-width:160px}');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Cadastre os tipos (ex.: Caminhão, Empilhadeira, Ferramenta elétrica) e os documentos que cada tipo precisa para a prestação do serviço (ex.: CRLV, checklist do veículo, laudo de isolamento). Eles são cobrados em Gestão de fornecedores › Veículos e equipamentos, separados da homologação.', 'Register the types and the documents each type needs for service delivery. They are required in Supplier management › Vehicles and equipment, separate from approval.', 'Registre los tipos y los documentos que cada tipo necesita para la prestación del servicio. Se exigen en Gestión de proveedores › Vehículos y equipos, separados de la homologación.')) ?></p>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= P::e($msg) ?></div><?php endif; foreach ($erros as $er): ?><div class="alert alert-danger py-1 mb-1"><?= P::e($er) ?></div><?php endforeach; ?>
<form method="post" data-form><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>"><input type="hidden" name="acao" value="tipos">
<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0"><thead class="table-light"><tr><th><?= P::e(P::L('Categoria', 'Category', 'Categoría')) ?></th><th><?= P::e(P::L('Tipo', 'Type', 'Tipo')) ?></th><th class="text-center"><?= P::e(P::L('Ativo', 'Active', 'Activo')) ?></th><th><?= P::e(P::L('Docs / em uso', 'Docs / in use', 'Docs / en uso')) ?></th><th></th></tr></thead><tbody data-corpo><?php foreach ($lista as $t) echo $lTipo((string)(int)$t['CODTIPOATIVO'], $t); ?></tbody></table></div>
<template data-modelo><?= $lTipo('__N__', []) ?></template>
<?php if ($pode): ?><div class="d-flex justify-content-between mt-2"><?php if (P::pode('incluir')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-add><i class="bi bi-plus-lg me-1"></i><?= P::e(P::L('Adicionar tipo', 'Add type', 'Agregar tipo')) ?></button><?php else: ?><span></span><?php endif; ?><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i><?= P::e(P::L('Salvar', 'Save', 'Guardar')) ?></button></div><?php endif; ?></form>
<?php if (isset($porId[$tipoSel])): ?>
<h6 class="mt-4" id="docs"><?= P::e(P::L('Documentos exigidos de', 'Required documents of', 'Documentos exigidos de')) ?> <?= P::e(($CAT[(string)$porId[$tipoSel]['CATEGORIA']] ?? '') . ' · ' . $porId[$tipoSel]['NOME']) ?></h6>
<form method="post" data-form><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>"><input type="hidden" name="acao" value="docs"><input type="hidden" name="tipo" value="<?= $tipoSel ?>">
<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0"><thead class="table-light"><tr><th><?= P::e(P::L('Documento', 'Document', 'Documento')) ?></th><th class="text-center"><?= P::e(P::L('Obrigatório', 'Required', 'Obligatorio')) ?></th><th><?= P::e(P::L('Validade (meses)', 'Validity (months)', 'Validez (meses)')) ?></th><th class="text-center"><?= P::e(P::L('Ativo', 'Active', 'Activo')) ?></th><th></th></tr></thead><tbody data-corpo><?php foreach ($docs as $d) echo $lDoc((string)(int)$d['CODTIPODOC'], $d); ?></tbody></table></div>
<template data-modelo><?= $lDoc('__N__', []) ?></template>
<?php if ($pode): ?><div class="d-flex justify-content-between mt-2"><?php if (P::pode('incluir')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-add><i class="bi bi-plus-lg me-1"></i><?= P::e(P::L('Adicionar documento', 'Add document', 'Agregar documento')) ?></button><?php else: ?><span></span><?php endif; ?><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i><?= P::e(P::L('Salvar documentos', 'Save documents', 'Guardar documentos')) ?></button></div><?php endif; ?></form>
<?php endif; ?>
<script>
document.querySelectorAll('[data-form]').forEach(function (form) { var seq = 0, corpo = form.querySelector('[data-corpo]'), add = form.querySelector('[data-add]');
  if (add) add.addEventListener('click', function () { var t = document.createElement('tbody'); t.innerHTML = form.querySelector('[data-modelo]').innerHTML.split('__N__').join('novo_' + (++seq)); corpo.appendChild(t.firstElementChild); });
  corpo.addEventListener('click', function (ev) { var b = ev.target.closest('.js-excluir'); if (!b) return; var tr = b.closest('tr'), k = tr.dataset.linha; if (/^\d+$/.test(k)) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'rows[' + k + '][_delete]'; h.value = '1'; form.appendChild(h); } tr.remove(); });
});
</script>
<?php P::rodape(); ?>

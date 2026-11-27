<?php
declare(strict_types=1);

/*
 * Homologação › Gestão de fornecedores › Veículos e equipamentos (Revisão G itens 149 e 150; migração 421, 26/11/2026).
 * Documentos para a PRESTAÇÃO DO SERVIÇO (entrar na empresa), separados dos documentos da homologação: cada veículo ou equipamento
 * do fornecedor (placa, nº de série...) tem o tipo (Homologação › Tipos de veículo e equipamento) e os documentos exigidos do tipo,
 * com situação (Pendente, Enviado, Aprovado, Reprovado), validade e arquivos (GED, tipo homativo). Aprovado com validade vencida
 * aparece como Vencido.
 * Permissão: HOMOLOGACAO_FORNECEDOR_FORNECEDOR (visualizar; incluir/editar/excluir). Fornecedor externo: só os fornecedores ligados
 *   a ele (HomologacaoFornecedorAcesso) e não aprova nem reprova documentos.
 * Leitura por sp_crud_select; gravação por sp_crud_insert / sp_crud_update / sp_crud_delete.
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
use PaginaSimples as P;

P::abrir('HOMOLOGACAO_FORNECEDOR_FORNECEDOR');
if (!P::existe('hom_ativo')) P::sair(503, P::L('Veículos e equipamentos ainda não instalados nesta empresa (migração 421).', 'Vehicles and equipment are not installed yet (migration 421).', 'Vehículos y equipos aún no instalados (migración 421).'));
$forn = (int)($_POST['fornecedor'] ?? 0) ?: HomologacaoFornecedorAcesso::fornecedorDaRequisicao();
if ($forn <= 0) P::sair(400, P::L('Escolha o fornecedor no grid.', 'Choose the supplier in the grid.', 'Elija el proveedor en la grilla.'));
HomologacaoFornecedorAcesso::exigirFornecedor(P::$pdo, $forn);
if (!P::sel('fo_fornecedores', ['codfornecedor' => $forn], 1)) P::sair(404, P::L('Fornecedor não encontrado.', 'Supplier not found.', 'Proveedor no encontrado.'));
$externo = HomologacaoFornecedorAcesso::externo();
$csrf = P::csrf('hom_ativos_csrf');
$CAT = ['VEICULO' => P::L('Veículo', 'Vehicle', 'Vehículo'), 'EQUIPAMENTO' => P::L('Equipamento', 'Equipment', 'Equipo')];
$SIT = ['Pendente' => P::L('Pendente', 'Pending', 'Pendiente'), 'Enviado' => P::L('Enviado', 'Sent', 'Enviado'), 'Aprovado' => P::L('Aprovado', 'Approved', 'Aprobado'), 'Reprovado' => P::L('Reprovado', 'Rejected', 'Rechazado')];
$ativoSel = (int)($_POST['ativo'] ?? $_GET['ativo'] ?? 0);
$msg = ''; $erros = [];

$tipos = [];
foreach (P::sel('vw_hom_ativo_tipo', [], 1000) as $t) $tipos[(int)$t['CODTIPOATIVO']] = $t;
$tiposAtivos = array_filter($tipos, static fn($t) => (string)$t['ATIVO'] === 'S');
$opTipos = [];
foreach ($tiposAtivos as $c => $t) $opTipos[$c] = ($CAT[(string)$t['CATEGORIA']] ?? $t['CATEGORIA']) . ' · ' . $t['NOME'];
asort($opTipos, SORT_NATURAL | SORT_FLAG_CASE);
$ativos = static fn(): array => P::sel('vw_hom_ativo', ['CODFORNECEDOR' => $forn], 2000);
$docsTipo = static fn(int $tipo): array => array_values(array_filter(P::sel('vw_hom_ativo_tipo_doc', ['CODTIPOATIVO' => $tipo], 500), static fn($d) => (string)$d['ATIVO'] === 'S'));
/** Cria as linhas dos documentos exigidos que ainda faltam no veículo/equipamento. */
$sincronizar = static function (int $ativo, int $tipo) use ($docsTipo): void {
    $tem = [];
    foreach (P::sel('hom_ativo_doc', ['CODATIVO' => $ativo], 500) as $d) $tem[(int)$d['CODTIPODOC']] = true;
    foreach ($docsTipo($tipo) as $d) if (!isset($tem[(int)$d['CODTIPODOC']])) P::ins('hom_ativo_doc', ['CODATIVO' => $ativo, 'CODTIPODOC' => (int)$d['CODTIPODOC'], 'SITUACAO' => 'Pendente', 'CODUSUARIO' => P::$uid ?: null]);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    P::conferirCsrf('hom_ativos_csrf');
    $existentes = [];
    foreach ($ativos() as $a) $existentes[(int)$a['CODATIVO']] = $a;
    $n = 0;
    if (($_POST['acao'] ?? '') === 'ativos') {
        foreach ((array)($_POST['rows'] ?? []) as $k => $row) {
            $row = (array)$row; $id = ctype_digit((string)$k) ? (int)$k : 0;
            if ($id && !isset($existentes[$id])) continue;
            if (!empty($row['_delete'])) {
                if (!$id || !P::pode('excluir')) continue;
                foreach (P::sel('hom_ativo_doc', ['CODATIVO' => $id], 500) as $d) P::del('hom_ativo_doc', ['CODATIVODOC' => (int)$d['CODATIVODOC']]);
                P::del('hom_ativo', ['CODATIVO' => $id]); $n++; continue;
            }
            if ($id ? !P::pode('editar') : !P::pode('incluir')) continue;
            $tipo = (int)($row['CODTIPOATIVO'] ?? 0); $ident = mb_substr(trim((string)($row['IDENTIFICACAO'] ?? '')), 0, 60);
            if ($ident === '' && !$id) continue;
            if (!isset($tipos[$tipo])) { $erros[] = ($ident ?: '#' . $id) . ': ' . P::L('escolha o tipo.', 'choose the type.', 'elija el tipo.'); continue; }
            if ($ident === '') { $erros[] = '#' . $id . ': ' . P::L('informe a placa ou identificação.', 'enter the plate or identification.', 'informe la placa o identificación.'); continue; }
            $dados = ['CODTIPOATIVO' => $tipo, 'IDENTIFICACAO' => mb_strtoupper($ident), 'DESCRICAO' => mb_substr(trim((string)($row['DESCRICAO'] ?? '')), 0, 300) ?: null, 'ATIVO' => !empty($row['ATIVO']) ? 'S' : 'N'];
            if ($id) {
                $mud = false; foreach ($dados as $c => $v) if ((string)($existentes[$id][$c] ?? '') !== (string)($v ?? '')) $mud = true;
                if (!$mud) continue;
                P::upd('hom_ativo', ['CODATIVO' => $id], $dados);
                if ((int)$existentes[$id]['CODTIPOATIVO'] !== $tipo) $sincronizar($id, $tipo);
            } else {
                P::ins('hom_ativo', ['CODFORNECEDOR' => $forn, 'CODUSUARIO' => P::$uid ?: null] + $dados);
                $novo = array_values(array_filter($ativos(), static fn($a) => !isset($existentes[(int)$a['CODATIVO']])));
                foreach ($novo as $a) { $sincronizar((int)$a['CODATIVO'], (int)$a['CODTIPOATIVO']); $existentes[(int)$a['CODATIVO']] = $a; }
            }
            $n++;
        }
    } elseif (($_POST['acao'] ?? '') === 'docs' && isset($existentes[$ativoSel])) {
        $docs = [];
        foreach (P::sel('vw_hom_ativo_doc', ['CODATIVO' => $ativoSel], 500) as $d) $docs[(int)$d['CODATIVODOC']] = $d;
        foreach ((array)($_POST['docs'] ?? []) as $k => $row) {
            $id = (int)$k; $row = (array)$row;
            if (!isset($docs[$id]) || !P::pode('editar')) continue;
            $sit = isset($SIT[(string)($row['SITUACAO'] ?? '')]) ? (string)$row['SITUACAO'] : (string)$docs[$id]['SITUACAO'];
            if ($externo && in_array($sit, ['Aprovado', 'Reprovado'], true) && $sit !== (string)$docs[$id]['SITUACAO']) $sit = (string)$docs[$id]['SITUACAO'];
            $val = trim((string)($row['DATA_VALIDADE'] ?? ''));
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) $val = '';
            $dados = ['SITUACAO' => $sit, 'DATA_VALIDADE' => $val ?: null, 'OBS' => mb_substr(trim((string)($row['OBS'] ?? '')), 0, 500) ?: null];
            $mud = false; foreach ($dados as $c => $v) if ((string)($docs[$id][$c] ?? '') !== (string)($v ?? '')) $mud = true;
            if (!$mud) continue;
            P::upd('hom_ativo_doc', ['CODATIVODOC' => $id], $dados + ['CODUSUARIO' => P::$uid ?: null]); $n++;
        }
    }
    AuditService::record(P::$pdo, 'homologacao_fornecedor_ativos', 'SALVAR', $erros ? 'PARCIAL' : 'SUCESSO', 'hom_ativo', (string)$forn, ['qtd' => $n]);
    $msg = $n ? P::L('Gravado.', 'Saved.', 'Guardado.') : ($erros ? '' : P::L('Nada mudou.', 'Nothing changed.', 'Nada cambió.'));
}
$lista = $ativos();
usort($lista, static fn($a, $b) => [(string)$a['CATEGORIA'], (string)$a['IDENTIFICACAO']] <=> [(string)$b['CATEGORIA'], (string)$b['IDENTIFICACAO']]);
$resumo = [];
foreach (P::sel('vw_hom_ativo_doc', ['CODFORNECEDOR' => $forn], 5000) as $d) {
    $a = (int)$d['CODATIVO']; $resumo[$a] ??= ['ok' => 0, 'pend' => 0, 'venc' => 0];
    $s = (string)$d['SITUACAO_ATUAL'];
    if ($s === 'Aprovado') $resumo[$a]['ok']++; elseif ($s === 'Vencido') $resumo[$a]['venc']++; elseif ((string)$d['OBRIGATORIO'] === 'S') $resumo[$a]['pend']++;
}
$sel = null;
foreach ($lista as $a) if ((int)$a['CODATIVO'] === $ativoSel) $sel = $a;
$docs = [];
if ($sel) {
    if (P::pode('editar') || P::pode('incluir')) $sincronizar($ativoSel, (int)$sel['CODTIPOATIVO']);
    $docs = P::sel('vw_hom_ativo_doc', ['CODATIVO' => $ativoSel], 500);
    usort($docs, static fn($a, $b) => strnatcasecmp((string)$a['DOCUMENTO'], (string)$b['DOCUMENTO']));
}
$fr = P::sel('fo_fornecedores', ['codfornecedor' => $forn], 1)[0] ?? [];   // colunas de fo_fornecedores em minúsculas
session_write_close();
$pode = P::pode('incluir') || P::pode('editar') || P::pode('excluir');
$linha = static function (string $k, array $a) use ($opTipos, $pode, $resumo, $forn): string {
    $n = 'rows[' . P::e($k) . ']'; $dis = $pode ? '' : ' disabled'; $id = (int)($a['CODATIVO'] ?? 0); $r = $resumo[$id] ?? null;
    $st = !$id ? '' : (!$r ? '<span class="badge text-bg-light border">' . P::e(P::L('Sem documentos exigidos', 'No required documents', 'Sin documentos exigidos')) . '</span>'
        : ($r['venc'] ? '<span class="badge text-bg-danger">' . P::e(P::L('Vencido', 'Expired', 'Vencido')) . ' (' . $r['venc'] . ')</span> ' : '') . ($r['pend'] ? '<span class="badge text-bg-warning">' . P::e(P::L('Pendente', 'Pending', 'Pendiente')) . ' (' . $r['pend'] . ')</span>' : '')
            . (!$r['venc'] && !$r['pend'] ? '<span class="badge text-bg-success">' . P::e(P::L('Em dia', 'Up to date', 'Al día')) . '</span>' : ''));
    return '<tr data-linha="' . P::e($k) . '"><td><select class="form-select form-select-sm" name="' . $n . '[CODTIPOATIVO]"' . $dis . '>' . P::opcoes($opTipos, $a['CODTIPOATIVO'] ?? '', P::L('Escolha…', 'Choose…', 'Elija…')) . '</select></td>'
        . '<td><input class="form-control form-control-sm" maxlength="60" name="' . $n . '[IDENTIFICACAO]" value="' . P::e($a['IDENTIFICACAO'] ?? '') . '" placeholder="' . P::e(P::L('Placa / nº de série', 'Plate / serial no.', 'Placa / nº de serie')) . '"' . $dis . '></td>'
        . '<td><input class="form-control form-control-sm" maxlength="300" name="' . $n . '[DESCRICAO]" value="' . P::e($a['DESCRICAO'] ?? '') . '"' . $dis . '></td>'
        . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n . '[ATIVO]" value="1"' . ((string)($a['ATIVO'] ?? 'S') === 'S' ? ' checked' : '') . $dis . '></td>'
        . '<td class="small">' . $st . '</td><td class="text-nowrap">' . ($id ? '<a class="btn btn-sm btn-outline-primary" href="?CODFORNECEDOR=' . $forn . '&ativo=' . $id . '#docs"><i class="bi bi-file-earmark-check me-1"></i>' . P::e(P::L('Documentos', 'Documents', 'Documentos')) . '</a> ' : '')
        . ($pode ? '<button type="button" class="btn btn-sm btn-outline-danger js-excluir"><i class="bi bi-trash"></i></button>' : '') . '</td></tr>';
};
P::cabecalho(P::L('Veículos e equipamentos do fornecedor', 'Supplier vehicles and equipment', 'Vehículos y equipos del proveedor') . ' — ' . trim((string)($fr['RAZAOSOCIAL'] ?? $fr['NOMEFANTASIA'] ?? '#' . $forn)), 'bi-truck', 'homologacao_fornecedor_ativos', '.ps table input.form-control,.ps table select{min-width:130px}');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Documentos para a prestação do serviço (entrar na empresa), separados da homologação: cadastre cada veículo ou equipamento com o tipo; os documentos exigidos do tipo aparecem em Documentos, com situação, validade e arquivos.', 'Documents for service delivery (entering the company), separate from approval: register each vehicle or equipment with its type; the required documents of the type appear in Documents, with status, validity and files.', 'Documentos para la prestación del servicio, separados de la homologación: registre cada vehículo o equipo con su tipo; los documentos exigidos aparecen en Documentos, con situación, validez y archivos.')) ?></p>
<?php if (!$opTipos): ?><div class="alert alert-warning py-2"><?= P::e(P::L('Nenhum tipo de veículo ou equipamento cadastrado: cadastre em Homologação › Tipos de veículo e equipamento.', 'No vehicle or equipment type yet: register them in Approval › Vehicle and equipment types.', 'Ningún tipo registrado: regístrelos en Homologación › Tipos de vehículo y equipo.')) ?></div><?php endif; ?>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= P::e($msg) ?></div><?php endif; foreach ($erros as $er): ?><div class="alert alert-danger py-1 mb-1"><?= P::e($er) ?></div><?php endforeach; ?>
<form method="post" id="haForm"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>"><input type="hidden" name="acao" value="ativos"><input type="hidden" name="fornecedor" value="<?= $forn ?>"><input type="hidden" name="ativo" value="<?= $ativoSel ?>">
<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0" id="haTab"><thead class="table-light"><tr><th><?= P::e(P::L('Tipo', 'Type', 'Tipo')) ?></th><th><?= P::e(P::L('Identificação', 'Identification', 'Identificación')) ?></th><th><?= P::e(P::L('Descrição', 'Description', 'Descripción')) ?></th><th class="text-center"><?= P::e(P::L('Ativo', 'Active', 'Activo')) ?></th><th><?= P::e(P::L('Documentos', 'Documents', 'Documentos')) ?></th><th></th></tr></thead>
<tbody><?php foreach ($lista as $a) echo $linha((string)(int)$a['CODATIVO'], $a); if (!$lista): ?><tr class="ha-vazio"><td colspan="6" class="text-muted"><?= P::e(P::L('Nenhum veículo ou equipamento cadastrado.', 'No vehicle or equipment yet.', 'Ningún vehículo o equipo registrado.')) ?></td></tr><?php endif; ?></tbody></table></div>
<template id="haModelo"><?= $linha('__N__', []) ?></template>
<?php if ($pode): ?><div class="d-flex justify-content-between mt-2"><?php if (P::pode('incluir') && $opTipos): ?><button type="button" class="btn btn-sm btn-outline-primary" id="haAdd"><i class="bi bi-plus-lg me-1"></i><?= P::e(P::L('Adicionar veículo ou equipamento', 'Add vehicle or equipment', 'Agregar vehículo o equipo')) ?></button><?php else: ?><span></span><?php endif; ?>
<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i><?= P::e(P::L('Salvar', 'Save', 'Guardar')) ?></button></div><?php endif; ?></form>
<?php if ($sel): ?>
<h6 class="mt-4" id="docs"><i class="bi bi-file-earmark-check me-1"></i><?= P::e(P::L('Documentos de', 'Documents of', 'Documentos de')) ?> <?= P::e($sel['IDENTIFICACAO'] . ' (' . $sel['TIPO'] . ')') ?></h6>
<?php if (!$docs): ?><div class="alert alert-info py-2"><?= P::e(P::L('O tipo não tem documentos exigidos.', 'The type has no required documents.', 'El tipo no tiene documentos exigidos.')) ?></div><?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>"><input type="hidden" name="acao" value="docs"><input type="hidden" name="fornecedor" value="<?= $forn ?>"><input type="hidden" name="ativo" value="<?= $ativoSel ?>">
<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0"><thead class="table-light"><tr><th><?= P::e(P::L('Documento', 'Document', 'Documento')) ?></th><th><?= P::e(P::L('Obrigatório', 'Required', 'Obligatorio')) ?></th><th><?= P::e(P::L('Situação', 'Status', 'Situación')) ?></th><th><?= P::e(P::L('Validade', 'Valid until', 'Validez')) ?></th><th><?= P::e(P::L('Observação', 'Note', 'Observación')) ?></th><th><?= P::e(P::L('Arquivos', 'Files', 'Archivos')) ?></th></tr></thead><tbody>
<?php foreach ($docs as $d): $id = (int)$d['CODATIVODOC']; $n = 'docs[' . $id . ']'; $dis = P::pode('editar') ? '' : ' disabled'; $opSit = $externo ? array_intersect_key($SIT, array_flip(['Pendente', 'Enviado', (string)$d['SITUACAO']])) : $SIT; ?>
<tr><td><?= P::e($d['DOCUMENTO']) ?><?= $d['VALIDADE_MESES'] ? '<div class="small text-muted">' . P::e(sprintf(P::L('Validade usual: %d meses', 'Usual validity: %d months', 'Validez usual: %d meses'), (int)$d['VALIDADE_MESES'])) . '</div>' : '' ?></td>
<td class="small"><?= (string)$d['OBRIGATORIO'] === 'S' ? P::e(P::L('Sim', 'Yes', 'Sí')) : P::e(P::L('Não', 'No', 'No')) ?></td>
<td><select class="form-select form-select-sm" name="<?= $n ?>[SITUACAO]"<?= $dis ?>><?= P::opcoes($opSit, (string)$d['SITUACAO']) ?></select><?= (string)$d['SITUACAO_ATUAL'] === 'Vencido' ? '<span class="badge text-bg-danger mt-1">' . P::e(P::L('Vencido', 'Expired', 'Vencido')) . '</span>' : '' ?></td>
<td><input type="date" class="form-control form-control-sm" name="<?= $n ?>[DATA_VALIDADE]" value="<?= P::e(substr((string)$d['DATA_VALIDADE'], 0, 10)) ?>"<?= $dis ?>></td>
<td><input class="form-control form-control-sm" maxlength="500" name="<?= $n ?>[OBS]" value="<?= P::e($d['OBS'] ?? '') ?>"<?= $dis ?>></td>
<td><button type="button" class="btn btn-sm btn-outline-secondary" data-arquivos="<?= $id ?>" data-titulo="<?= P::e($d['DOCUMENTO'] . ' — ' . $sel['IDENTIFICACAO']) ?>"><i class="bi bi-paperclip"></i></button></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (P::pode('editar')): ?><div class="text-end mt-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i><?= P::e(P::L('Salvar documentos', 'Save documents', 'Guardar documentos')) ?></button></div><?php endif; ?></form><?php endif; ?>
<?php endif; ?>
<script>
(function () { var tab = document.querySelector('#haTab tbody'), seq = 0, add = document.getElementById('haAdd');
  if (add) add.addEventListener('click', function () { var v = tab.querySelector('.ha-vazio'); if (v) v.remove(); var t = document.createElement('tbody'); t.innerHTML = document.getElementById('haModelo').innerHTML.split('__N__').join('novo_' + (++seq)); tab.appendChild(t.firstElementChild); });
  tab.addEventListener('click', function (ev) { var b = ev.target.closest('.js-excluir'); if (!b) return; var tr = b.closest('tr'), k = tr.dataset.linha; if (/^\d+$/.test(k)) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'rows[' + k + '][_delete]'; h.value = '1'; document.getElementById('haForm').appendChild(h); } tr.remove(); });
  document.addEventListener('click', function (ev) { var b = ev.target.closest('[data-arquivos]'); if (!b) return;
    var host = window.top || window, url = (host.greenPortalBaseUrl || '..') + '/principal/gestao_arquivos.php?tipo=homativo&chave=CODATIVODOC&valor=' + encodeURIComponent(b.dataset.arquivos);
    if (host.GreenPortalPopups && host.jQuery) host.GreenPortalPopups.transacaoIndependente({ titulo: b.dataset.titulo, conteudo: host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }), amplo: true, redimensionavel: true });
    else window.open(url, '_blank'); });
}());
</script>
<?php P::rodape(); ?>

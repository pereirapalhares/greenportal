<?php
declare(strict_types=1);

/*
 * Módulo Legal › RCL — revisões e leitura (Revisão G item 42; migração 421, 26/11/2026).
 * A RCL (Relação de Leis) gerada pela consultoria grava o resumo do dia em legislacaoxrevisoes (migração 385). Aqui o usuário
 * da empresa vê as revisões, da mais nova para a mais antiga, e registra que leu ("Marcar como lida"): rcl_leitura.
 * Quem pode editar a tela (ou o ADM da empresa) vê também quem já leu cada revisão e quem ainda não leu.
 * Leitura por sp_crud_select; gravação por sp_crud_insert.
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
use PaginaSimples as P;

P::abrir('RCL_LEITURA');
if (!P::existe('rcl_leitura')) P::sair(503, P::L('A leitura da RCL ainda não foi instalada nesta empresa (migração 421).', 'RCL reading is not installed yet (migration 421).', 'La lectura de la RCL aún no fue instalada (migración 421).'));
$csrf = P::csrf('rcl_leitura_csrf');
$perfil = strtoupper((string)preg_replace('/[\s_-]+/', ' ', (string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$gestor = P::pode('editar') || in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true);

$revisoes = P::existe('legislacaoxrevisoes') ? P::sel('legislacaoxrevisoes', [], 2000) : [];
$pk = '';
foreach (array_keys($revisoes[0] ?? []) as $k) if (str_starts_with($k, 'COD')) { $pk = $k; break; }
$data = static fn(array $r): string => (string)($r['DATA'] ?? $r['DATA_REVISAO'] ?? '');
usort($revisoes, static fn($a, $b) => [$data($b), (int)($b[$pk] ?? 0)] <=> [$data($a), (int)($a[$pk] ?? 0)]);
$revisoes = array_slice($revisoes, 0, 300);

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $pk !== '') {
    P::conferirCsrf('rcl_leitura_csrf');
    $ids = array_filter(array_map('intval', (array)($_POST['lidas'] ?? [])));
    $minhas = [];
    foreach (P::sel('rcl_leitura', ['CODUSUARIO' => P::$uid], 5000) as $l) $minhas[(int)$l['CODREVISAO']] = true;
    $validas = array_flip(array_map(static fn($r) => (int)$r[$pk], $revisoes));
    $n = 0;
    foreach ($ids as $id) if (isset($validas[$id]) && !isset($minhas[$id])) { P::ins('rcl_leitura', ['CODREVISAO' => $id, 'CODUSUARIO' => P::$uid]); $n++; }
    AuditService::record(P::$pdo, 'modulolegal_rcl_leitura', 'MARCAR_LIDA', 'SUCESSO', 'rcl_leitura', null, ['qtd' => $n]);
    $msg = sprintf(P::L('%d revisão(ões) marcada(s) como lida(s).', '%d revision(s) marked as read.', '%d revisión(es) marcada(s) como leída(s).'), $n);
}
$leituras = [];
foreach (P::sel('vw_rcl_leitura', [], 50000) as $l) $leituras[(int)$l['CODREVISAO']][(int)$l['CODUSUARIO']] = ['nome' => (string)$l['USUARIO'], 'em' => (string)$l['LIDA_EM']];
$pessoas = $gestor ? P::usuarios() : [];
$fmt = static fn(string $d): string => $d !== '' && ($t = strtotime($d)) ? date(P::$idioma === 'en' ? 'm/d/Y' : 'd/m/Y', $t) : $d;
$fmtH = static fn(string $d): string => $d !== '' && ($t = strtotime($d)) ? date(P::$idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', $t) : $d;
$naoLidas = 0;
foreach ($revisoes as $r) if (!isset($leituras[(int)$r[$pk]][P::$uid])) $naoLidas++;
session_write_close();

$titulo = P::L('RCL — revisões e leitura', 'RCL — revisions and reading', 'RCL — revisiones y lectura');
P::cabecalho($titulo, 'bi-journal-check', 'modulolegal_rcl_leitura', '.rcl-txt{white-space:pre-wrap;max-height:9em;overflow:auto}.rcl-lidos{max-width:340px}');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Cada revisão da RCL (Relação de Leis) traz as leis novas, alteradas ou revogadas para a empresa. Leia e marque como lida: fica registrado quem leu e quando.', 'Each RCL revision lists the laws added, changed or revoked for the company. Read it and mark it as read: who read it and when is recorded.', 'Cada revisión de la RCL trae las leyes nuevas, modificadas o revocadas. Léala y márquela como leída: queda registrado quién la leyó y cuándo.')) ?>
<b><?= P::e(sprintf(P::L('%d não lida(s) por você.', '%d unread by you.', '%d no leída(s) por usted.'), $naoLidas)) ?></b></p>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= P::e($msg) ?></div><?php endif; ?>
<?php if (!$revisoes): ?><div class="alert alert-info"><?= P::e(P::L('Nenhuma revisão da RCL registrada para esta empresa ainda.', 'No RCL revision recorded for this company yet.', 'Ninguna revisión de la RCL registrada aún.')) ?></div><?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>">
<div class="d-flex gap-2 mb-2"><button type="submit" class="btn btn-sm btn-success" <?= $naoLidas ? '' : 'disabled' ?>><i class="bi bi-check2-all me-1"></i><?= P::e(P::L('Marcar as escolhidas como lidas', 'Mark selected as read', 'Marcar las elegidas como leídas')) ?></button>
<button type="button" class="btn btn-sm btn-link" onclick="document.querySelectorAll('[name=&quot;lidas[]&quot;]:not(:disabled)').forEach(function(c){c.checked=true})"><?= P::e(P::L('Escolher todas as não lidas', 'Select all unread', 'Elegir todas las no leídas')) ?></button></div>
<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0"><thead class="table-light"><tr><th></th><th><?= P::e(P::L('Data', 'Date', 'Fecha')) ?></th><th><?= P::e(P::L('Revisão', 'Revision', 'Revisión')) ?></th><th><?= P::e(P::L('Você', 'You', 'Usted')) ?></th><?php if ($gestor): ?><th><?= P::e(P::L('Quem leu', 'Read by', 'Quién leyó')) ?></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($revisoes as $r): $id = (int)$r[$pk]; $lidos = $leituras[$id] ?? []; $eu = $lidos[P::$uid] ?? null; ?>
<tr<?= $eu ? '' : ' class="fw-semibold"' ?>><td><input class="form-check-input" type="checkbox" name="lidas[]" value="<?= $id ?>"<?= $eu ? ' checked disabled' : '' ?>></td><td class="text-nowrap"><?= P::e($fmt($data($r))) ?></td>
<td><div class="rcl-txt small fw-normal"><?= P::e(trim((string)($r['DSCREVISAO'] ?? $r['REVISAO'] ?? ''))) ?></div></td>
<td class="small text-nowrap"><?= $eu ? '<span class="text-success"><i class="bi bi-check2-all"></i> ' . P::e($fmtH($eu['em'])) . '</span>' : '<span class="badge text-bg-warning">' . P::e(P::L('Não lida', 'Unread', 'No leída')) . '</span>' ?></td>
<?php if ($gestor): ?><td class="small rcl-lidos fw-normal"><?= P::e(count($lidos) . ' ' . P::L('leram', 'read', 'leyeron')) ?><?php if ($lidos): ?>: <?= P::e(implode(', ', array_map(static fn($l) => $l['nome'] . ' (' . $fmt($l['em']) . ')', $lidos))) ?><?php endif; ?>
<?php $faltam = array_diff_key($pessoas, $lidos); if ($faltam && count($faltam) <= 40): ?><div class="text-muted"><?= P::e(P::L('Ainda não leram', 'Not read yet', 'Aún no leyeron')) ?>: <?= P::e(implode(', ', $faltam)) ?></div><?php elseif ($faltam): ?><div class="text-muted"><?= P::e(count($faltam) . ' ' . P::L('pessoas ainda não leram', 'people have not read it', 'personas aún no leyeron')) ?></div><?php endif; ?></td><?php endif; ?></tr>
<?php endforeach; ?></tbody></table></div></form><?php endif; ?>
<?php P::rodape(); ?>

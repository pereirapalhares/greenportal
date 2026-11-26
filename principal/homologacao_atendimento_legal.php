<?php
declare(strict_types=1);

/*
 * Homologação › Gestão de fornecedores › "Atendimento legal" (Revisão G item 131, 26/11/2026).
 * Relatório de atendimento legal do fornecedor: as leis (requisitos legais) vinculadas ao fornecedor em cada unidade
 * (legislacaoxfornecedores), com a avaliação do responsável, a situação consolidada, o % de atendimento e o risco do
 * requisito (Requisitos legais). Sem fornecedor no filtro, mostra todos os fornecedores com leis vinculadas.
 * Leitura por sp_crud_select. Exportação em CSV e impressão (PDF pelo navegador) na própria tela.
 * Permissão: Gestão de fornecedores (HOMOLOGACAO_FORNECEDOR_FORNECEDOR) — visualizar.
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
date_default_timezone_set('America/Sao_Paulo');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$T = static fn(string $k, string $pt, string $en, string $es): string => IdiomaService::t('homologacao_atendimento_legal.' . $k, ['pt' => $pt, 'en' => $en, 'es' => $es]);
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$perm = PermissaoTransacao::obter('HOMOLOGACAO_FORNECEDOR_FORNECEDOR');
if (empty($perm['pode_visualizar'])) { http_response_code(403); exit($e($T('sem_permissao', 'Seu perfil não tem acesso à Gestão de fornecedores.', 'No access to Supplier management.', 'Sin acceso a la Gestión de proveedores.'))); }

$sel = static function (string $t, array $f, int $n = 1000) use ($pdo): array {
    $s = $pdo->prepare('CALL sp_crud_select(:t,:f,NULL,:n,0)');
    $s->bindValue('t', $t); $s->bindValue('f', json_encode($f ?: new stdClass(), JSON_UNESCAPED_UNICODE)); $s->bindValue('n', $n, PDO::PARAM_INT);
    $s->execute(); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r;
};
$exato = static fn($v): array => ['value' => (string)$v, 'exact' => true];
$filtros = json_decode((string)($_GET['filtros'] ?? '{}'), true); $filtros = is_array($filtros) ? $filtros : [];
$fv = $filtros['CODFORNECEDOR'] ?? ($_GET['CODFORNECEDOR'] ?? ''); if (is_array($fv)) $fv = $fv['value'] ?? '';
$codForn = (int)$fv;

$erro = '';
$linhas = [];
try {
    $vinculos = $sel('legislacaoxfornecedores', $codForn > 0 ? ['CODFORNECEDOR' => $exato($codForn)] : [], 1000);
    $fornNome = []; $req = [];
    foreach ($vinculos as $v) { $v = array_change_key_case($v, CASE_UPPER);
        $f = (int)($v['CODFORNECEDOR'] ?? $v['codfornecedor'] ?? 0); $l = (int)($v['CODLEGISLACAO'] ?? 0); $u = (int)($v['CODUNIDADE'] ?? $v['codunidade'] ?? 0);
        if ($f <= 0 || $l <= 0) continue;
        if (!isset($fornNome[$f])) { $fr = array_change_key_case($sel('fo_fornecedores', ['CODFORNECEDOR' => $exato($f)], 1)[0] ?? [], CASE_UPPER); $fornNome[$f] = trim((string)($fr['RAZAOSOCIAL'] ?? $fr['NOMEFANTASIA'] ?? '')) ?: ('#' . $f); }
        if (!isset($req[$l])) { $req[$l] = []; foreach ($sel('vw_modulolegal_requisitos_situacao_grid', ['CODLEGISLACAO' => $exato($l)], 200) as $r) $req[$l][(int)$r['CODUNIDADE']] = $r; }
        $r = $req[$l][$u] ?? null;
        $linhas[] = ['forn' => $fornNome[$f], 'codforn' => $f, 'unidade' => $r ? (string)($r['UNIDADE'] ?? $r['DSCUNIDADE'] ?? '') : '#' . $u,
            'lei' => $r ? (string)($r['CODIGO'] ?? '') : 'Lei ' . $l, 'requisito' => $r ? mb_strimwidth(trim((string)($r['PADRAO'] ?? '')), 0, 220, '…') : '',
            'atendido' => $r ? ((string)($r['AVALIACAO_LEGAL'] ?? '') === 'Não avaliado' ? 'Não avaliado' : (string)($r['ATENDIDO'] ?? '')) : $T('sem_requisito', '(não é requisito nesta unidade)', '(not a requirement at this unit)', '(no es requisito en esta unidad)'),
            'situacao' => $r ? (string)($r['SITUACAO_CONSOLIDADA'] ?? '') : '', 'pct' => $r && ($r['PCT_ATENDIMENTO'] ?? '') !== '' && $r['PCT_ATENDIMENTO'] !== null ? (int)$r['PCT_ATENDIMENTO'] . '%' : '',
            'risco' => $r ? (string)($r['RISCO_REQUISITO'] ?? '') : ''];
    }
    usort($linhas, static fn($a, $b) => [$a['forn'], $a['unidade'], $a['lei']] <=> [$b['forn'], $b['unidade'], $b['lei']]);
} catch (Throwable $x) { $erro = $x->getMessage(); }
$cont = [];
foreach ($linhas as $l) { $k = $l['situacao'] !== '' ? $l['situacao'] : '—'; $cont[$k] = ($cont[$k] ?? 0) + 1; }
$tom = static fn(string $s): string => match (true) { str_starts_with($s, 'Atendido') => 'success', str_starts_with($s, 'Parcial') => 'warning', str_starts_with($s, 'Não') => 'danger', default => 'secondary' };
$titulo = $T('titulo', 'Atendimento legal do fornecedor', 'Supplier legal compliance', 'Atención legal del proveedor') . ($codForn > 0 && $linhas ? ' — ' . $linhas[0]['forn'] : '');
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=2" rel="stylesheet">
<style>body{font-size:12.5px;padding:12px}table td,table th{vertical-align:top}@media print{.no-print{display:none!important}body{padding:0}}</style></head>
<body>
<div class="d-flex align-items-center gap-2 mb-2">
  <h5 class="mb-0 me-auto"><i class="bi bi-journal-check me-1"></i><?= $e($titulo) ?></h5>
  <button type="button" class="btn btn-sm btn-outline-success no-print" id="alCsv"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
  <button type="button" class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $e($T('imprimir', 'Imprimir / PDF', 'Print / PDF', 'Imprimir / PDF')) ?></button>
</div>
<p class="text-muted small mb-2"><?= $e($T('explica', 'Leis vinculadas ao fornecedor (botão Fornecedores nos Requisitos legais) com a situação de cada requisito na unidade. Emitido em', 'Laws linked to the supplier with the status of each requirement at the unit. Issued on', 'Leyes vinculadas al proveedor con la situación de cada requisito en la unidad. Emitido el')) ?> <?= date('d/m/Y H:i') ?>.</p>
<?php if ($erro !== ''): ?><div class="alert alert-danger"><?= $e($erro) ?></div><?php endif; ?>
<?php if ($linhas): ?>
<div class="d-flex flex-wrap gap-2 mb-2"><?php foreach ($cont as $k => $n): ?><span class="badge text-bg-<?= $tom($k) ?>"><?= $e($k) ?>: <?= $n ?></span><?php endforeach; ?><span class="badge text-bg-light border"><?= $e($T('total', 'Total', 'Total', 'Total')) ?>: <?= count($linhas) ?></span></div>
<table class="table table-sm table-striped" id="alTabela"><thead><tr>
<?php if ($codForn <= 0): ?><th><?= $e($T('c.forn', 'Fornecedor', 'Supplier', 'Proveedor')) ?></th><?php endif; ?>
<th><?= $e($T('c.unidade', 'Unidade', 'Unit', 'Unidad')) ?></th><th><?= $e($T('c.lei', 'Lei', 'Law', 'Ley')) ?></th><th><?= $e($T('c.requisito', 'Requisito', 'Requirement', 'Requisito')) ?></th>
<th><?= $e($T('c.atendido', 'Avaliação', 'Assessment', 'Evaluación')) ?></th><th><?= $e($T('c.situacao', 'Situação consolidada', 'Consolidated status', 'Situación consolidada')) ?></th><th>%</th><th><?= $e($T('c.risco', 'Risco', 'Risk', 'Riesgo')) ?></th></tr></thead><tbody>
<?php foreach ($linhas as $l): ?><tr>
<?php if ($codForn <= 0): ?><td><?= $e($l['forn']) ?></td><?php endif; ?>
<td><?= $e($l['unidade']) ?></td><td class="text-nowrap"><?= $e($l['lei']) ?></td><td><?= $e($l['requisito']) ?></td><td><?= $e($l['atendido']) ?></td>
<td><span class="badge text-bg-<?= $tom($l['situacao']) ?>"><?= $e($l['situacao'] ?: '—') ?></span></td><td><?= $e($l['pct']) ?></td><td><?= $e($l['risco']) ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php elseif ($erro === ''): ?><div class="alert alert-info"><?= $e($T('vazio', 'Nenhuma lei vinculada a este fornecedor. Vincule em Requisitos legais › Fornecedores.', 'No law linked to this supplier.', 'Ninguna ley vinculada a este proveedor.')) ?></div><?php endif; ?>
<script>
document.getElementById('alCsv')?.addEventListener('click', function () {
  var t = document.getElementById('alTabela'); if (!t) return;
  var csv = Array.prototype.map.call(t.rows, function (r) { return Array.prototype.map.call(r.cells, function (c) { return '"' + c.innerText.replace(/"/g, '""').trim() + '"'; }).join(';'); }).join('\r\n');
  var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); a.download = 'atendimento_legal_fornecedor.csv'; document.body.appendChild(a); a.click(); a.remove();
});
</script>
</body></html>

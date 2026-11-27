<?php
declare(strict_types=1);

/*
 * Relatório da auditoria em PDF (migração 423 — Revisão G, módulo de Auditoria: "gerar automaticamente o Relatório de Auditoria a
 * partir de um template"). Mesmo esquema do relatório da NC: Dompdf quando instalado; senão o HTML abre a impressão (salvar como PDF).
 * Conteúdo: identificação, planejamento, avaliação do plano, execução, checklist com respostas e evidências, constatações com as
 * RNCs e a situação de cada uma, e o histórico. Quem só tem Minhas Auditorias vê o relatório das auditorias de que participa.
 */
require_once dirname(__DIR__) . '/classes/Auditoria.php';

use PaginaSimples as P;
use Auditoria as A;

$id = (int)($_GET['id'] ?? 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
$todas = !empty(PermissaoTransacao::obter('AUDITORIA_GESTAO')['pode_visualizar']);
P::abrir($todas ? 'AUDITORIA_GESTAO' : 'MINHAS_AUDITORIAS');
$a = A::auditoria($id);
if (!$a) P::sair(404, P::L('Auditoria não encontrada.', 'Audit not found.', 'Auditoría no encontrada.'));
if (!$todas && !A::participa($a, P::$uid) && !P::admin()) P::sair(403, P::L('Você não participa desta auditoria.', 'You do not take part in this audit.', 'Usted no participa en esta auditoría.'));

$e = static fn($v) => P::e($v);
$fmt = static fn($d) => $d ? date('d/m/Y', strtotime(substr((string)$d, 0, 10))) : '';
$unid = '';
try { $unid = (string)(P::sel('vw_unidades_grid', ['CODUNIDADE' => (int)$a['CODUNIDADE']], 1)[0]['DSCUNIDADE'] ?? ''); } catch (Throwable) {}
$forn = '';
if ($a['CODFORNECEDOR']) { try { $forn = (string)(P::sel('fo_fornecedores', ['codfornecedor' => (int)$a['CODFORNECEDOR']], 1)[0]['RAZAOSOCIAL'] ?? ''); } catch (Throwable) {} }
$av = P::sel('vw_aud_avaliacao', ['CODAUDITORIA' => $id], 50);
$ex = P::sel('vw_aud_execucao', ['CODAUDITORIA' => $id], 50);
$ck = P::sel('vw_aud_checklist', ['CODAUDITORIA' => $id], 2000);
usort($ck, static fn($x, $y) => [(int)$x['ORDEM'], (int)$x['CODCHECKLIST']] <=> [(int)$y['ORDEM'], (int)$y['CODCHECKLIST']]);
$cs = P::sel('vw_aud_constatacao', ['CODAUDITORIA' => $id], 1000);
$wf = P::sel('vw_aud_workflow', ['CODAUDITORIA' => $id], 1000);
usort($wf, static fn($x, $y) => strcmp((string)$x['DATAOCORRENCIA'], (string)$y['DATAOCORRENCIA']));
$empresa = (string)($_SESSION['tenant']['nome'] ?? $_SESSION['tenant']['codigo'] ?? '');
$cont = array_count_values(array_column($cs, 'CLASSIFICACAO'));
$respostas = array_count_values(array_filter(array_column($ck, 'RESPOSTA')));

$L = static fn($pt, $en, $es) => $e(P::L($pt, $en, $es));
$linha = static fn(string $r, string $v) => '<tr><th style="width:28%">' . $r . '</th><td>' . $v . '</td></tr>';
ob_start(); ?>
<!doctype html><html><head><meta charset="utf-8"><title><?= $L('Relatório de auditoria', 'Audit report', 'Informe de auditoría') ?> #<?= $id ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:10.5px;color:#222;margin:18px}
h1{font-size:16px;margin:0 0 2px}h2{font-size:12.5px;margin:16px 0 6px;padding-bottom:3px;border-bottom:2px solid #1f6f4a;color:#1f6f4a}
table{width:100%;border-collapse:collapse;margin-bottom:6px}th,td{border:1px solid #ccd5d0;padding:4px 5px;vertical-align:top;text-align:left}th{background:#eef4f0;font-weight:bold}
.mut{color:#666}.tag{display:inline-block;padding:1px 6px;border-radius:8px;font-size:9.5px;color:#fff}
.NC_MAIOR{background:#c0392b}.NC_MENOR{background:#d68910}.OBSERVACAO{background:#2e86c1}.MELHORIA{background:#5b2c6f}.CONFORME{background:#1e8449}.NA{background:#7f8c8d}
.resumo td{text-align:center;font-size:12px}.resumo b{display:block;font-size:16px}
</style></head><body>
<table style="border:0"><tr><td style="border:0"><h1><?= $L('Relatório de auditoria', 'Audit report', 'Informe de auditoría') ?> nº <?= $id ?></h1><div class="mut"><?= $e($empresa) ?> · <?= $L('emitido em', 'issued on', 'emitido el') ?> <?= date('d/m/Y H:i') ?></div></td>
<td style="border:0;text-align:right"><b><?= $e(A::situacao((string)$a['STATUS'])) ?></b></td></tr></table>

<h2>1. <?= $L('Identificação', 'Identification', 'Identificación') ?></h2>
<table>
<?= $linha($L('Auditoria', 'Audit', 'Auditoría'), $e($a['DSCAUDITORIA'])) ?>
<?= $linha($L('Tipo', 'Type', 'Tipo'), $e(A::rotulo(A::TIPOS[$a['TIPO']] ?? [$a['TIPO'], $a['TIPO'], $a['TIPO']])) . ($forn ? ' — ' . $e($forn) : '')) ?>
<?= $linha($L('Norma / referência', 'Standard / reference', 'Norma / referencia'), $e($a['NORMA'] ?? '')) ?>
<?= $linha($L('Unidade / local', 'Unit / location', 'Unidad / lugar'), $e(trim($unid . ($a['LOCALIZACAO'] ? ' — ' . $a['LOCALIZACAO'] : ''), ' —'))) ?>
<?= $linha($L('Período', 'Period', 'Período'), $e($fmt($a['DATADEINICIO']) . ($a['DATADEFIM'] ? ' a ' . $fmt($a['DATADEFIM']) : ''))) ?>
<?= $linha($L('Objetivo', 'Objective', 'Objetivo'), nl2br($e($a['OBJETIVO'] ?? ''))) ?>
<?= $linha($L('Escopo', 'Scope', 'Alcance'), nl2br($e($a['ESCOPO'] ?? ''))) ?>
<?= $linha($L('Auditor líder', 'Lead auditor', 'Auditor líder'), $e($a['AUDITOR'] ?? '')) ?>
<?= $linha($L('Avaliador do plano', 'Plan reviewer', 'Evaluador del plan'), $e($a['AVALIADOR'] ?? '')) ?>
</table>

<h2>2. <?= $L('Resumo', 'Summary', 'Resumen') ?></h2>
<table class="resumo"><tr>
<td><b><?= count($ck) ?></b><?= $L('perguntas', 'questions', 'preguntas') ?></td><td><b><?= (int)($respostas['CONFORME'] ?? 0) ?></b><?= $L('conformes', 'conforming', 'conformes') ?></td>
<td><b><?= (int)($cont['NC_MAIOR'] ?? 0) ?></b><?= $L('NC maior', 'major NC', 'NC mayor') ?></td><td><b><?= (int)($cont['NC_MENOR'] ?? 0) ?></b><?= $L('NC menor', 'minor NC', 'NC menor') ?></td>
<td><b><?= (int)($cont['OBSERVACAO'] ?? 0) ?></b><?= $L('observações', 'observations', 'observaciones') ?></td><td><b><?= (int)($cont['MELHORIA'] ?? 0) ?></b><?= $L('melhorias', 'improvements', 'mejoras') ?></td>
<td><b><?= (int)$a['QTD_RNC'] ?></b><?= $L('RNC', 'NCR', 'RNC') ?> (<?= (int)$a['QTD_RNC_ABERTAS'] ?> <?= $L('abertas', 'open', 'abiertas') ?>)</td></tr></table>

<h2>3. <?= $L('Avaliação do plano e execução', 'Plan review and execution', 'Evaluación del plan y ejecución') ?></h2>
<table>
<?php foreach ($av as $x) echo $linha($L('Avaliação', 'Review', 'Evaluación') . ' ' . $e($fmt($x['DATAAVALIACAO'])), nl2br($e($x['DSCAVALIACAO'])) . ' <span class="mut">— ' . $e($x['AVALIADOR'] ?? '') . '</span>'); ?>
<?php foreach ($ex as $x) echo $linha($L('Execução', 'Execution', 'Ejecución') . ' ' . $e($fmt($x['DATAEXECUCAO'])) . ($x['DATAENCERRAMENTO'] ? ' / ' . $L('encerramento', 'closing', 'cierre') . ' ' . $e($fmt($x['DATAENCERRAMENTO'])) : ''), nl2br($e($x['OBSERVACAO'])) . ' <span class="mut">— ' . $e($x['AUDITOR'] ?? '') . '</span>'); ?>
<?php if (!$av && !$ex) echo '<tr><td class="mut">' . $L('Sem registros.', 'No records.', 'Sin registros.') . '</td></tr>'; ?>
</table>

<h2>4. <?= $L('Checklist', 'Checklist', 'Checklist') ?></h2>
<?php if (!$ck): ?><p class="mut"><?= $L('Sem perguntas.', 'No questions.', 'Sin preguntas.') ?></p><?php else: ?>
<table><tr><th style="width:9%"><?= $L('Cláusula', 'Clause', 'Cláusula') ?></th><th style="width:40%"><?= $L('Pergunta', 'Question', 'Pregunta') ?></th><th style="width:13%"><?= $L('Resposta', 'Answer', 'Respuesta') ?></th><th><?= $L('Evidência', 'Evidence', 'Evidencia') ?></th></tr>
<?php foreach ($ck as $c) echo '<tr><td>' . $e($c['REQUISITO']) . '</td><td>' . $e($c['PERGUNTA']) . '</td><td>' . ($c['RESPOSTA'] ? '<span class="tag ' . $e($c['RESPOSTA']) . '">' . $e(A::rotulo(A::RESPOSTAS[$c['RESPOSTA']] ?? [$c['RESPOSTA'], $c['RESPOSTA'], $c['RESPOSTA']])) . '</span>' : '<span class="mut">—</span>') . '</td><td>' . nl2br($e($c['EVIDENCIA'] ?? '')) . '</td></tr>'; ?>
</table><?php endif; ?>

<h2>5. <?= $L('Constatações', 'Findings', 'Hallazgos') ?></h2>
<?php if (!$cs): ?><p class="mut"><?= $L('Nenhuma constatação.', 'No findings.', 'Ningún hallazgo.') ?></p><?php else: ?>
<table><tr><th style="width:15%"><?= $L('Classificação', 'Classification', 'Clasificación') ?></th><th style="width:9%"><?= $L('Cláusula', 'Clause', 'Cláusula') ?></th><th><?= $L('Constatação e evidência', 'Finding and evidence', 'Hallazgo y evidencia') ?></th><th style="width:17%">RNC</th></tr>
<?php foreach ($cs as $c) echo '<tr><td><span class="tag ' . $e($c['CLASSIFICACAO']) . '">' . $e(A::rotulo(A::CLASSIFICACAO[$c['CLASSIFICACAO']][0] ?? [$c['CLASSIFICACAO'], '', ''])) . '</span></td><td>' . $e($c['REQUISITO']) . '</td><td>' . nl2br($e($c['DESCRICAO']))
    . ($c['EVIDENCIA'] ? '<div class="mut">' . $L('Evidência', 'Evidence', 'Evidencia') . ': ' . nl2br($e($c['EVIDENCIA'])) . '</div>' : '') . '</td><td>' . ($c['CODOCORRENCIA'] ? $e($c['RNC_NUMERO'] ?: '#' . $c['CODOCORRENCIA']) . '<div class="mut">' . $e($c['RNC_ETAPA']) . '</div>' : '<span class="mut">—</span>') . '</td></tr>'; ?>
</table><?php endif; ?>

<h2>6. <?= $L('Histórico', 'History', 'Historial') ?></h2>
<table><tr><th style="width:16%"><?= $L('Data', 'Date', 'Fecha') ?></th><th style="width:16%"><?= $L('Etapa', 'Stage', 'Etapa') ?></th><th><?= $L('Ocorrência', 'Event', 'Evento') ?></th><th style="width:20%"><?= $L('Usuário', 'User', 'Usuario') ?></th></tr>
<?php foreach ($wf as $w) echo '<tr><td>' . $e($w['DATAOCORRENCIA'] ? date('d/m/Y H:i', strtotime((string)$w['DATAOCORRENCIA'])) : '') . '</td><td>' . $e(A::situacao((string)$w['ETAPA'])) . '</td><td>' . $e($w['DSCOBSERVACAO']) . '</td><td>' . $e($w['USUARIO'] ?? '') . '</td></tr>'; ?>
</table>
</body></html>
<?php
$html = (string)ob_get_clean();
try { AuditService::record(P::$pdo, 'auditoria_gestao', 'RELATORIO', 'SUCESSO', 'auditoria_planejamento', (string)$id); } catch (Throwable) {}
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (($_GET['formato'] ?? '') !== 'html' && is_file($autoload)) {
    require_once $autoload;
    $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('defaultFont', 'Arial');
    $pdf = new Dompdf\Dompdf($o); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4', 'portrait'); $pdf->render();
    $bin = $pdf->output();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="auditoria_' . $id . '.pdf"'); header('Content-Length: ' . strlen($bin));
    echo $bin; exit;
}
header('Content-Type: text/html; charset=UTF-8');
// 27/11/2026: sem abrir a impressão sozinho (diálogo do navegador); botão Imprimir na página, fora da impressão
echo $html . '<style>@media print{.aud-imprimir{display:none}}</style><div class="aud-imprimir" style="position:fixed;top:10px;right:14px"><button type="button" onclick="window.print()" style="border:1px solid #1f6f4a;background:#1f6f4a;color:#fff;border-radius:6px;padding:5px 12px;font:12px Arial;cursor:pointer">'
    . htmlspecialchars(P::L('Imprimir', 'Print', 'Imprimir'), ENT_QUOTES, 'UTF-8') . '</button></div>';

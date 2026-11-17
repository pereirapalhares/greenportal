<?php
declare(strict_types=1);

/*
 * Próximos prazos (ícone da barra lateral): o que está sob a responsabilidade do usuário e vence nos próximos 30 dias
 * ou já venceu (até 1 ano), de todos os módulos — requisitos, licenças, medições, planos de ação de NC, tarefas,
 * responsabilidades e avaliações de fornecedor. Dados: vw_painel_prazos (migração 290) via classes/PainelLateralService.php.
 * Cada item abre o registro em popup (90% da tela, sempre na frente).
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';

try { $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); } catch (Throwable) { http_response_code(503); exit('Banco de dados indisponível.'); }
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma];
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$itens = (new PainelLateralService($pdo, (int)$_SESSION['usuario']['codusuario']))->prazos();
session_write_close();

$grupos = ['ATRASADO' => [], 'HOJE' => [], 'PROXIMO' => []];
foreach ($itens as $i) $grupos[$i['SITUACAO']][] = $i;
$titulos = [
    'ATRASADO' => [$L('Atrasados', 'Overdue', 'Atrasados'), 'bi-exclamation-triangle', 'atrasado'],
    'HOJE' => [$L('Vencem hoje', 'Due today', 'Vencen hoy'), 'bi-alarm', 'hoje'],
    'PROXIMO' => [$L('Próximos 30 dias', 'Next 30 days', 'Próximos 30 días'), 'bi-calendar-week', 'proximo'],
];
$data = static function (?string $d) use ($idioma): string { if (!$d) return ''; $t = strtotime($d); return $t ? date($idioma === 'en' ? 'm/d/Y' : 'd/m/Y', $t) : ''; };
$dias = static function (?string $d) use ($L): string {
    if (!$d) return ''; $n = (int)floor((strtotime(substr($d, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
    if ($n === 0) return $L('hoje', 'today', 'hoy');
    return $n < 0 ? sprintf($L('há %d dia(s)', '%d day(s) ago', 'hace %d día(s)'), -$n) : sprintf($L('em %d dia(s)', 'in %d day(s)', 'en %d día(s)'), $n);
};
?>
<!doctype html>
<html lang="<?= $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet"><link href="../assets/css/painel-lateral.css?v=2" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="dashboard-content painel-lista">
<section class="dashboard-panel"><div class="panel-title"><div><h3><?= $e($L('Próximos prazos', 'Upcoming deadlines', 'Próximos plazos')) ?></h3>
<p><?= $e($L('O que está sob a sua responsabilidade e vence nos próximos 30 dias ou já venceu.', 'What is under your responsibility and is due in the next 30 days or is already overdue.', 'Lo que está bajo su responsabilidad y vence en los próximos 30 días o ya venció.')) ?></p></div><a class="btn btn-sm btn-outline-secondary" href="painel_calendario.php"><i class="bi bi-calendar3 me-1"></i><?= $e($L('Calendário', 'Calendar', 'Calendario')) ?></a></div>
<?php if (!$itens): ?><div class="empty-dashboard"><i class="bi bi-calendar2-check"></i><strong><?= $e($L('Nenhum prazo próximo', 'No upcoming deadlines', 'Ningún plazo próximo')) ?></strong><span><?= $e($L('Quando um item sob sua responsabilidade estiver perto de vencer, ele aparece aqui.', 'When an item under your responsibility is close to its due date, it shows here.', 'Cuando un ítem bajo su responsabilidad esté por vencer, aparecerá aquí.')) ?></span></div>
<?php else: ?>
<div class="painel-resumo"><?php foreach ($titulos as $sit => [$t, $ic, $cls]): ?><a class="painel-chip painel-<?= $cls ?>" href="#grupo-<?= $cls ?>"><i class="bi <?= $ic ?>"></i><?= $e($t) ?> <strong><?= count($grupos[$sit]) ?></strong></a><?php endforeach; ?></div>
<?php foreach ($titulos as $sit => [$t, $ic, $cls]): if (!$grupos[$sit]) continue; ?>
<h4 class="painel-grupo painel-<?= $cls ?>" id="grupo-<?= $cls ?>"><i class="bi <?= $ic ?>"></i><?= $e($t) ?> <span><?= count($grupos[$sit]) ?></span></h4>
<div class="table-responsive"><table class="table table-sm painel-tabela"><thead><tr><th><?= $e($L('Prazo', 'Due', 'Plazo')) ?></th><th><?= $e($L('Tipo', 'Type', 'Tipo')) ?></th><th><?= $e($L('Item', 'Item', 'Ítem')) ?></th><th><?= $e($L('Detalhe', 'Detail', 'Detalle')) ?></th></tr></thead><tbody>
<?php foreach ($grupos[$sit] as $i): $tp = PainelLateralService::TIPOS_PRAZO[$i['TIPO']] ?? [[$idioma => $i['TIPO']], 'bi-dot']; ?>
<tr<?= $i['LINK'] !== '' ? ' data-abrir="' . $e($i['LINK']) . '" tabindex="0" role="link"' : '' ?> data-titulo="<?= $e(($tp[0][$idioma] ?? $i['TIPO']) . ' - ' . $i['TITULO']) ?>">
<td class="painel-data"><?= $e($data($i['PRAZO'])) ?><small><?= $e($dias($i['PRAZO'])) ?></small></td>
<td><i class="bi <?= $e($tp[1]) ?> me-1"></i><?= $e($tp[0][$idioma] ?? $i['TIPO']) ?></td>
<td class="painel-item"><?= $e($i['TITULO']) ?><?php if ($i['LINK'] !== ''): ?> <i class="bi bi-box-arrow-up-right painel-abrir" aria-hidden="true"></i><?php endif; ?></td>
<td class="text-muted"><?= $e($i['DETALHE']) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endforeach; endif; ?>
</section></main>
<script src="../assets/js/painel-lateral.js?v=2"></script>
<?php if (($_GET['foco'] ?? '') === 'hoje'): ?><script>(function(){var g=document.getElementById('grupo-hoje');if(g)g.scrollIntoView();})();</script><?php endif; ?></body></html>
